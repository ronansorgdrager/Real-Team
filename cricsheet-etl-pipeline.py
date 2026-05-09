import json
import uuid
import mysql.connector
from datetime import datetime

def load_json(filepath):
    with open(filepath, 'r') as f:
        return json.load(f)

def generate_id():
    return str(uuid.uuid4())

def extract_teams(data):
    teams_dict = {}
    if 'teams' in data['info']:
        for team_name in data['info']['teams']:
            teams_dict[team_name] = generate_id()
    return teams_dict

def run_etl():
    json_file = 'wi_201706.json'
    print(f"Starting ETL process for {json_file}")
    
    try:
        data = load_json(json_file)
    except FileNotFoundError:
        print(f"Error: Could not find {json_file}")
        return

    # extract/transform
    info = data.get('info', {})
    
    # 1. COMPETITIONS
    comp_name = info.get('event', {}).get('name', 'Unknown Competition')
    comp_id = generate_id()

    # 2. SEASONS
    season_name = info.get('season', 'Unknown Season')
    season_id = generate_id()

    # 3. TEAMS
    teams = extract_teams(data)
    # team_name mapped to team_id for easier reference later
    
    # 4. PLAYERS
    players = {}
    registry = info.get('registry', {}).get('people', {})
    for name, p_id in registry.items():
        players[name] = p_id

    # 5. MATCHES
    match_id = generate_id()
    match_date = info.get('dates', ['1970-01-01'])[0]
    venue = info.get('venue', None)
    
    team_list = list(teams.keys())
    team1_id = teams.get(team_list[0]) if len(team_list) > 0 else None
    team2_id = teams.get(team_list[1]) if len(team_list) > 1 else None

    outcome = info.get('outcome', {})
    winner_name = outcome.get('winner')
    winning_team_id = teams.get(winner_name) if winner_name else None
    
    win_type = None
    win_margin = None
    if 'by' in outcome:
        if 'runs' in outcome['by']:
            win_type = 'runs'
            win_margin = outcome['by']['runs']
        elif 'wickets' in outcome['by']:
            win_type = 'wickets'
            win_margin = outcome['by']['wickets']
            
    win_method = outcome.get('method', None)
    match_type = info.get('match_type', None)
    
    toss = info.get('toss', {})
    toss_winner_name = toss.get('winner')
    toss_winner_id = teams.get(toss_winner_name) if toss_winner_name else None
    toss_decision = toss.get('decision', None)

    # 6 and 7. INNINGS & PERFORMANCE
    innings_data = []
    performance_data = {} # (player_id, innings_id) -> stats

    for i, inning in enumerate(data.get('innings', [])):
        innings_id = generate_id()
        batting_team_name = inning.get('team')
        batting_team_id = teams.get(batting_team_name)
        
        bowling_team_name = team_list[1] if team_list[0] == batting_team_name else team_list[0]
        bowling_team_id = teams.get(bowling_team_name)

        total_runs = 0
        total_wickets = 0

        # Process deliveries (balls)
        for over in inning.get('overs', []):
            for delivery in over.get('deliveries', []):
                batter = delivery.get('batter')
                bowler = delivery.get('bowler')
                runs = delivery.get('runs', {})
                batter_runs = runs.get('batter', 0)
                total_delivery_runs = runs.get('total', 0)
                
                total_runs += total_delivery_runs
                
                batter_id = players.get(batter)
                bowler_id = players.get(bowler)

                # Batting performance update
                if batter_id:
                    perf_key = (batter_id, innings_id)
                    if perf_key not in performance_data:
                        performance_data[perf_key] = {
                            'team_id': batting_team_id,
                            'runs_scored': 0, 'balls_faced': 0,
                            'wickets_taken': 0, 'balls_bowled': 0,
                            'catches': 0, 'stumpings': 0
                        }
                    performance_data[perf_key]['runs_scored'] += batter_runs
                    # Wides don't count as a ball faced usually, but for POC we just count deliveries
                    if 'wides' not in delivery.get('extras', {}):
                        performance_data[perf_key]['balls_faced'] += 1

                # Bowling performance update
                if bowler_id:
                    perf_key = (bowler_id, innings_id)
                    if perf_key not in performance_data:
                        performance_data[perf_key] = {
                            'team_id': bowling_team_id,
                            'runs_scored': 0, 'balls_faced': 0,
                            'wickets_taken': 0, 'balls_bowled': 0,
                            'catches': 0, 'stumpings': 0
                        }
                    if 'wides' not in delivery.get('extras', {}) and 'noballs' not in delivery.get('extras', {}):
                        performance_data[perf_key]['balls_bowled'] += 1

                # Wickets
                if 'wickets' in delivery:
                    total_wickets += len(delivery['wickets'])
                    for wicket in delivery['wickets']:
                        if bowler_id and wicket.get('kind') not in ['run out', 'retired hurt', 'obstructing the field']:
                            performance_data[(bowler_id, innings_id)]['wickets_taken'] += 1
                        
                        # Fielding stats (Catches and Stumpings)
                        if 'fielders' in wicket:
                            for fielder in wicket['fielders']:
                                fielder_name = fielder.get('name')
                                fielder_id = players.get(fielder_name)
                                if fielder_id:
                                    f_perf_key = (fielder_id, innings_id)
                                    if f_perf_key not in performance_data:
                                        performance_data[f_perf_key] = {
                                            'team_id': bowling_team_id,
                                            'runs_scored': 0, 'balls_faced': 0,
                                            'wickets_taken': 0, 'balls_bowled': 0,
                                            'catches': 0, 'stumpings': 0
                                        }
                                    if wicket.get('kind') == 'caught':
                                        performance_data[f_perf_key]['catches'] += 1
                                    elif wicket.get('kind') == 'stumped':
                                        performance_data[f_perf_key]['stumpings'] += 1

        innings_data.append({
            'innings_id': innings_id,
            'match_id': match_id,
            'batting_team_id': batting_team_id,
            'bowling_team_id': bowling_team_id,
            'innings_number': i + 1,
            'total_runs': total_runs,
            'total_wickets': total_wickets
        })

    # load data
    print("Connecting to MySQL Database...")
    try:
        conn = mysql.connector.connect(
            host="localhost",
            user="etl", 
            password="etlv1", 
            database="cricket_explorer"
        )
        cursor = conn.cursor()

        # 1. Insert Competition
        cursor.execute("""
            INSERT IGNORE INTO COMPETITIONS (comp_id, comp_name) 
            VALUES (%s, %s)
        """, (comp_id, comp_name))

        # 2. Insert Season
        cursor.execute("""
            INSERT IGNORE INTO SEASONS (season_id, comp_id, season_name) 
            VALUES (%s, %s, %s)
        """, (season_id, comp_id, season_name))

        # 3. Insert Teams
        for team_name, team_id in teams.items():
            cursor.execute("""
                INSERT IGNORE INTO TEAMS (team_id, team_name) 
                VALUES (%s, %s)
            """, (team_id, team_name))

        # 4. Insert Players
        for player_name, player_id in players.items():
            cursor.execute("""
                INSERT IGNORE INTO PLAYERS (player_id, player_name) 
                VALUES (%s, %s)
            """, (player_id, player_name))

        # 5. Insert Match
        cursor.execute("""
            INSERT IGNORE INTO MATCHES 
            (match_id, season_id, match_date, venue, team1_id, team2_id, winning_team_id, win_type, win_margin, win_method, match_type, toss_winner_id, toss_decision) 
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (match_id, season_id, match_date, venue, team1_id, team2_id, winning_team_id, win_type, win_margin, win_method, match_type, toss_winner_id, toss_decision))

        # 6. Insert Innings
        for inn in innings_data:
            cursor.execute("""
                INSERT IGNORE INTO INNINGS 
                (innings_id, match_id, batting_team_id, bowling_team_id, innings_number, total_runs, total_wickets) 
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (inn['innings_id'], inn['match_id'], inn['batting_team_id'], inn['bowling_team_id'], inn['innings_number'], inn['total_runs'], inn['total_wickets']))

        # 7. Insert Performance
        for (player_id, innings_id), stats in performance_data.items():
            perf_id = generate_id()
            
            # Convert balls bowled to overs (e.g., 8 balls = 1.2 overs)
            balls = stats['balls_bowled']
            overs_bowled = (balls // 6) + ((balls % 6) / 10.0)

            cursor.execute("""
                INSERT IGNORE INTO PERFORMANCE 
                (performance_id, player_id, innings_id, team_id, runs_scored, balls_faced, wickets_taken, overs_bowled, catches, stumpings) 
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (perf_id, player_id, innings_id, stats['team_id'], stats['runs_scored'], stats['balls_faced'], stats['wickets_taken'], overs_bowled, stats['catches'], stats['stumpings']))

        # 8. Insert Import Log
        cursor.execute("""
            INSERT INTO IMPORT_LOG (source_url, status) 
            VALUES (%s, %s)
        """, (json_file, "SUCCESS"))

        conn.commit()
        print("ETL pipeline completed successfully! Data inserted into MySQL.")

    except mysql.connector.Error as err:
        print(f"MySQL Error: {err}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()
            print("MySQL connection is closed.")

if __name__ == "__main__":
    run_etl()
