import os
import sys
import json
import uuid
import logging
import traceback
import mysql.connector
from datetime import datetime


DB_CONFIG = {
    "host": "localhost",
    "user": "etl",
    "password": "etlv1",
    "database": "cricket_explorer",
}

# Errors go to a file as well as the console. The scraper runs this script as a
# subprocess and only keeps stderr on a non-zero exit, so without the file a
# failure part-way through a large import would leave nothing behind to read.
LOG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'etl.log')

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(levelname)-8s %(message)s',
    handlers=[
        logging.FileHandler(LOG_FILE, encoding='utf-8'),
        logging.StreamHandler(sys.stdout),
    ],
)
logger = logging.getLogger('cricsheet_etl')

def load_json(filepath):
    with open(filepath, 'r') as f:
        return json.load(f)

def record_failure(source_name, stage, exc):
    """Write a failed run to etl.log, then to IMPORT_LOG and ETL_ERROR_LOG.

    Always logs to the file first: the database is one of the things that can be
    broken, and a failure we cannot store is still a failure we want to read.
    The database write uses its own connection so it survives the rollback of
    whatever transaction was in flight when the run died.
    """
    logger.error(
        "ETL failed for %s during stage '%s': %s: %s",
        source_name, stage, type(exc).__name__, exc,
    )
    logger.debug("Traceback for %s:%s%s", source_name, os.linesep, traceback.format_exc())

    conn = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()

        # A FAILED row keeps the scraper from treating the file as done - its
        # already-imported check only counts rows with status = 'SUCCESS'.
        cursor.execute(
            "INSERT INTO IMPORT_LOG (source_url, status) VALUES (%s, %s)",
            (source_name, "FAILED"),
        )
        log_id = cursor.lastrowid

        cursor.execute("""
            INSERT INTO ETL_ERROR_LOG
            (log_id, source_file, stage, error_type, error_message, traceback)
            VALUES (%s, %s, %s, %s, %s, %s)
        """, (
            log_id,
            source_name,
            stage,
            type(exc).__name__,
            str(exc),
            traceback.format_exc(),
        ))
        conn.commit()
        cursor.close()
    except Exception as log_exc:
        # Never let the error logger become the error. The file log above has
        # already captured the original failure.
        logger.error("Could not write the failure to the database: %s", log_exc)
    finally:
        if conn is not None and conn.is_connected():
            conn.close()


def generate_id():
    return str(uuid.uuid4())

# Each record's ID is a fingerprint calculated from the details that describe it, so the same thing always gets the same ID and we never store it twice.
ID_NAMESPACE = uuid.UUID('6f1b9d64-2c3e-5a8f-9b4d-1e7c0a5f3d82')

def stable_id(kind, *parts):
    """A repeatable id for one real-world thing, derived from its natural key."""
    key = kind + '|' + '|'.join('' if p is None else str(p) for p in parts)
    return str(uuid.uuid5(ID_NAMESPACE, key))

# The dismissals a scorecard writes "b <bowler>" against. A run out names no
# bowler, and neither does retirement/obstruction, so those leave
# dismissal_bowler_id empty
BOWLER_CREDITED_KINDS = frozenset([
    'bowled', 'caught', 'caught and bowled', 'lbw', 'stumped', 'hit wicket',
])

# Retired hurt is the one entry that can appear in a delivery's wickets list
# without being a dismissal - the batter is entitled to come back. It must not
# count towards the innings wicket total or the fall of wickets. ("retired out"
# IS a real dismissal and is deliberately not in here.)
NOT_A_DISMISSAL = frozenset(['retired hurt', 'retired not out'])

def new_perf(team_id):
    """A fresh performance accumulator for one (player, innings)."""
    return {
        'team_id': team_id,
        'runs_scored': 0, 'balls_faced': 0, 'is_out': 0,
        'batting_position': None, 'fours': 0, 'sixes': 0,
        'dismissal_kind': None, 'dismissal_bowler_id': None,
        'dismissal_fielder_id': None,
        'wickets_taken': 0, 'balls_bowled': 0, 'runs_conceded': 0,
        'wides_bowled': 0, 'noballs_bowled': 0,
        'maidens': 0, 'catches': 0, 'stumpings': 0, 'run_outs': 0
    }

def extract_teams(data):
    teams_dict = {}
    if 'teams' in data['info']:
        for team_name in data['info']['teams']:
            teams_dict[team_name] = stable_id('team', team_name)
    return teams_dict

def _extract_and_load(json_file, source_name, stage):
    """The run itself. Raises on any failure; run_etl does the error handling.

    `stage` is a one-key dict the caller shares with us, so that when something
    throws, the error log can say which part of the pipeline was running rather
    than just naming the exception.
    """
    logger.info("Starting ETL process for %s", json_file)

    stage['name'] = 'read_source'
    data = load_json(json_file)

    stage['name'] = 'transform'

    # extract/transform
    info = data.get('info', {})
    
    # 1. COMPETITIONS
    comp_name = info.get('event', {}).get('name', 'Unknown Competition')
    comp_id = stable_id('competition', comp_name)

    # 2. SEASONS
    season_name = info.get('season', 'Unknown Season')
    season_id = stable_id('season', comp_name, season_name)

    # 3. TEAMS
    teams = extract_teams(data)
    # team_name mapped to team_id for easier reference later
    
    # 4. PLAYERS
    players = {}
    registry = info.get('registry', {}).get('people', {})
    for name, p_id in registry.items():
        players[name] = p_id

    # 5. MATCHES
    match_date = info.get('dates', ['1970-01-01'])[0]
    venue = info.get('venue', None)

    # Cricsheet JSON carries no match id of its own, so the match is identified by
    # the things that together can only describe one fixture. Teams are sorted so
    # the key doesn't change if the JSON happens to list them the other way round.
    match_id = stable_id(
        'match',
        match_date,
        '/'.join(sorted(info.get('teams', []))),
        venue,
        comp_name,
        season_name,
        info.get('gender'),
        info.get('match_type'),
    )

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

    # 5b. MATCH SQUAD. info.players is the team sheet - the eleven each side
    # named. info.registry.people, where the ids come from
    squad_data = []
    participants = set()
    for squad_team_name, squad_players in info.get('players', {}).items():
        squad_team_id = teams.get(squad_team_name)
        if not squad_team_id:
            continue
        for squad_player_name in squad_players:
            participants.add(squad_player_name)
            squad_player_id = players.get(squad_player_name)
            if squad_player_id:
                squad_data.append({
                    'squad_id': stable_id('squad', match_id, squad_player_id),
                    'match_id': match_id,
                    'team_id': squad_team_id,
                    'player_id': squad_player_id,
                })

    # 6 and 7. INNINGS & PERFORMANCE
    innings_data = []
    performance_data = {} # (player_id, innings_id) -> stats
    fall_of_wickets = []

    for i, inning in enumerate(data.get('innings', [])):
        innings_id = stable_id('innings', match_id, i + 1)
        batting_team_name = inning.get('team')
        batting_team_id = teams.get(batting_team_name)
        
        bowling_team_name = team_list[1] if team_list[0] == batting_team_name else team_list[0]
        bowling_team_id = teams.get(bowling_team_name)

        total_runs = 0
        total_wickets = 0
        legal_balls = 0
        extras_tally = {'byes': 0, 'legbyes': 0, 'wides': 0,
                        'noballs': 0, 'penalty': 0}

        # Batting order, in the order the innings reveals it: the opening pair
        # arrive together on the first ball as batter and non-striker, everyone
        # after that on the ball they first appear at either end.
        batting_order = {}

        # Process deliveries (balls)
        for over in inning.get('overs', []):
            # Per-over running total, keyed by bowler so that an over finished by a
            # second bowler doesn't get scored as a maiden for either of them.
            over_tally = {}

            for delivery in over.get('deliveries', []):
                batter = delivery.get('batter')
                bowler = delivery.get('bowler')
                runs = delivery.get('runs', {})
                batter_runs = runs.get('batter', 0)
                total_delivery_runs = runs.get('total', 0)
                extras = delivery.get('extras', {})

                total_runs += total_delivery_runs

                # A legal delivery is one that counts towards the over. This is
                # what the overs figure and the run rate are built from, and it
                # is NOT the same as balls faced - the batter faces a no-ball.
                is_legal = 'wides' not in extras and 'noballs' not in extras
                if is_legal:
                    legal_balls += 1

                # Extras are accumulated as RUNS, so these five plus the runs
                # off the bat add up to the innings total.
                for extra_kind in extras_tally:
                    extras_tally[extra_kind] += extras.get(extra_kind, 0)

                # Everyone the ball-by-ball data actually names, so the PLAYERS
                # insert can leave the officials out.
                for name in (batter, bowler, delivery.get('non_striker')):
                    if name:
                        participants.add(name)

                for name in (batter, delivery.get('non_striker')):
                    if name and name not in batting_order:
                        batting_order[name] = len(batting_order) + 1

                batter_id = players.get(batter)
                bowler_id = players.get(bowler)

                # get batters performance for innings, doesn't count wides as balls faced
                if batter_id:
                    perf_key = (batter_id, innings_id)
                    if perf_key not in performance_data:
                        performance_data[perf_key] = new_perf(batting_team_id)
                    performance_data[perf_key]['runs_scored'] += batter_runs
                    # wide is not a ball faced by the batter
                    if 'wides' not in extras:
                        performance_data[perf_key]['balls_faced'] += 1
                    # Boundaries. non_boundary marks the rare four or six that
                    # was run rather than hit to the rope - four runs, but not a
                    # four, and a scorecard does not count it as one.
                    if not runs.get('non_boundary', False):
                        if batter_runs == 4:
                            performance_data[perf_key]['fours'] += 1
                        elif batter_runs == 6:
                            performance_data[perf_key]['sixes'] += 1

                # get bowlwers performance for the innings, also gets wides/noballs
                if bowler_id:
                    perf_key = (bowler_id, innings_id)
                    if perf_key not in performance_data:
                        performance_data[perf_key] = new_perf(bowling_team_id)
                    # wides and no-balls are not legal balls bowled
                    if is_legal:
                        performance_data[perf_key]['balls_bowled'] += 1
                    if 'wides' in extras:
                        performance_data[perf_key]['wides_bowled'] += 1
                    if 'noballs' in extras:
                        performance_data[perf_key]['noballs_bowled'] += 1
                    # Runs charged to the bowler: off the bat + wides + no-balls
                    # (byes and leg-byes are NOT the bowler's fault, so excluding)
                    performance_data[perf_key]['runs_conceded'] += (
                        batter_runs + extras.get('wides', 0) + extras.get('noballs', 0)
                    )

                    # Same figures again, but scoped to this over only (for maidens)
                    if bowler_id not in over_tally:
                        over_tally[bowler_id] = {'legal_balls': 0, 'runs_conceded': 0}
                    if is_legal:
                        over_tally[bowler_id]['legal_balls'] += 1
                    over_tally[bowler_id]['runs_conceded'] += (
                        batter_runs + extras.get('wides', 0) + extras.get('noballs', 0)
                    )

                # Wickets
                if 'wickets' in delivery:
                    for wicket in delivery['wickets']:
                        kind = wicket.get('kind')
                        if bowler_id and kind not in ['run out', 'retired hurt', 'obstructing the field']:
                            performance_data[(bowler_id, innings_id)]['wickets_taken'] += 1

                        # Mark the batter as out. "retired hurt" is not a
                        # dismissal, so it stays not-out - and for the same
                        # reason it is not a wicket the innings total or the
                        # fall of wickets should count.
                        if kind not in NOT_A_DISMISSAL:
                            total_wickets += 1
                            out_name = wicket.get('player_out')
                            if out_name:
                                participants.add(out_name)
                            out_id = players.get(out_name)
                            if out_id:
                                out_key = (out_id, innings_id)
                                if out_key not in performance_data:
                                    # non-striker run out before facing a ball
                                    performance_data[out_key] = new_perf(batting_team_id)
                                performance_data[out_key]['is_out'] = 1
                                performance_data[out_key]['dismissal_kind'] = kind

                                if kind in BOWLER_CREDITED_KINDS:
                                    performance_data[out_key]['dismissal_bowler_id'] = bowler_id

                                if kind == 'caught and bowled':
                                    # The bowler took the catch, and Cricsheet
                                    # names no fielder for it.
                                    performance_data[out_key]['dismissal_fielder_id'] = bowler_id
                                else:
                                    # Cricsheet can name more than one fielder
                                    # on a run out. A scorecard line has room
                                    # for the first.
                                    named = wicket.get('fielders') or []
                                    if named:
                                        performance_data[out_key]['dismissal_fielder_id'] = (
                                            players.get(named[0].get('name'))
                                        )

                            # Score at the fall, including anything run off the
                            # delivery that brought the wicket. The over is read
                            # from the legal-ball count, so a wicket off a
                            # no-ball does not advance it.
                            fall_of_wickets.append({
                                'fow_id': stable_id('fow', innings_id, total_wickets),
                                'innings_id': innings_id,
                                'wicket_number': total_wickets,
                                'runs_at_fall': total_runs,
                                'over_number': legal_balls // 6,
                                'ball_in_over': legal_balls % 6,
                                'player_out_id': out_id,
                            })

                        # Fielding stats (catches, stumpings, run outs).
                        # Cricsheet lists a single fielder per dismissal, so a run out
                        # is credited to that one player rather than shared around.
                        for fielder in (wicket.get('fielders') or []):
                            fielder_name = fielder.get('name')
                            if fielder_name:
                                participants.add(fielder_name)
                            fielder_id = players.get(fielder_name)
                            if fielder_id:
                                f_perf_key = (fielder_id, innings_id)
                                if f_perf_key not in performance_data:
                                    performance_data[f_perf_key] = new_perf(bowling_team_id)
                                if kind == 'caught':
                                    performance_data[f_perf_key]['catches'] += 1
                                elif kind == 'stumped':
                                    performance_data[f_perf_key]['stumpings'] += 1
                                elif kind == 'run out':
                                    performance_data[f_perf_key]['run_outs'] += 1

                        # A caught and bowled has no fielders entry at all - the bowler
                        # took the catch themselves, so credit it to them.
                        if kind == 'caught and bowled' and bowler_id:
                            performance_data[(bowler_id, innings_id)]['catches'] += 1

            # A maiden is an over the bowler is charged no runs for. Byes and
            # leg-byes aren't charged to them, so they don't break it - same rule
            # as runs_conceded above. Requiring 6 legal balls from a single bowler
            # stops a part-over at the end of an innings counting as a maiden.
            if len(over_tally) == 1:
                (maiden_bowler_id, tally), = over_tally.items()
                if tally['legal_balls'] == 6 and tally['runs_conceded'] == 0:
                    performance_data[(maiden_bowler_id, innings_id)]['maidens'] += 1

        # Apply the batting order once the innings is complete
        for order_name, order_position in batting_order.items():
            order_player_id = players.get(order_name)
            if not order_player_id:
                continue
            order_key = (order_player_id, innings_id)
            if order_key not in performance_data:
                performance_data[order_key] = new_perf(batting_team_id)
            performance_data[order_key]['batting_position'] = order_position

        innings_data.append({
            'innings_id': innings_id,
            'match_id': match_id,
            'batting_team_id': batting_team_id,
            'bowling_team_id': bowling_team_id,
            'innings_number': i + 1,
            'total_runs': total_runs,
            'total_wickets': total_wickets,
            'legal_balls': legal_balls,
            'extras_byes': extras_tally['byes'],
            'extras_legbyes': extras_tally['legbyes'],
            'extras_wides': extras_tally['wides'],
            'extras_noballs': extras_tally['noballs'],
            'extras_penalty': extras_tally['penalty']
        })

    # load data
    stage['name'] = 'connect'
    logger.info("Connecting to MySQL Database...")
    conn = None
    cursor = None
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor()
        stage['name'] = 'load'

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

        # 4. Insert Players.
        # Only the people who actually took part in the match are inserted, so officials don't appear in PLAYERS.
        for player_name, player_id in players.items():
            if player_name not in participants:
                continue
            cursor.execute("""
                INSERT IGNORE INTO PLAYERS (player_id, player_name)
                VALUES (%s, %s)
            """, (player_id, player_name))

        # Is this match already in the database? The import still goes ahead and replaces it, but it gets flagged
        cursor.execute("SELECT COUNT(*) FROM MATCHES WHERE match_id = %s", (match_id,))
        is_duplicate = cursor.fetchone()[0] > 0
        import_note = None

        if is_duplicate:
            cursor.execute("""
                SELECT COUNT(*) FROM PERFORMANCE p
                INNER JOIN INNINGS i ON i.innings_id = p.innings_id
                WHERE i.match_id = %s
            """, (match_id,))
            replaced_rows = cursor.fetchone()[0]

            cursor.execute("""
                SELECT MAX(import_timestamp) FROM IMPORT_LOG
                WHERE source_url = %s AND status = 'SUCCESS'
            """, (source_name,))
            previous_import = cursor.fetchone()[0]

            seen_before = (
                "previously imported %s" % previous_import if previous_import
                else "no previous import of this filename - it may have arrived under another name"
            )
            import_note = (
                "DUPLICATE MATCH %s (%s, %s v %s): %s. "
                "Replacing %d existing performance rows."
                % (match_id, match_date, team_list[0],
                   team_list[1] if len(team_list) > 1 else '?',
                   seen_before, replaced_rows)
            )
            logger.warning(import_note)

        # 5. Insert Match.
        cursor.execute("""
            INSERT INTO MATCHES 
            (match_id, season_id, match_date, venue, team1_id, team2_id, winning_team_id, win_type, win_margin, win_method, match_type, toss_winner_id, toss_decision) 
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                season_id = VALUES(season_id),
                match_date = VALUES(match_date),
                venue = VALUES(venue),
                team1_id = VALUES(team1_id),
                team2_id = VALUES(team2_id),
                winning_team_id = VALUES(winning_team_id),
                win_type = VALUES(win_type),
                win_margin = VALUES(win_margin),
                win_method = VALUES(win_method),
                match_type = VALUES(match_type),
                toss_winner_id = VALUES(toss_winner_id),
                toss_decision = VALUES(toss_decision)
        """, (match_id, season_id, match_date, venue, team1_id, team2_id, winning_team_id, win_type, win_margin, win_method, match_type, toss_winner_id, toss_decision))

        # Clear this match's existing innings and performance rows - When we reload a match, we wipe its old scorecard and write the new one in full, so we never end up with a mix of old and new data
        cursor.execute("""
            DELETE p FROM PERFORMANCE p
            INNER JOIN INNINGS i ON i.innings_id = p.innings_id
            WHERE i.match_id = %s
        """, (match_id,))
        cursor.execute("""
            DELETE f FROM FALL_OF_WICKETS f
            INNER JOIN INNINGS i ON i.innings_id = f.innings_id
            WHERE i.match_id = %s
        """, (match_id,))
        cursor.execute("DELETE FROM INNINGS WHERE match_id = %s", (match_id,))
        cursor.execute("DELETE FROM MATCH_SQUAD WHERE match_id = %s", (match_id,))

        # 6a. Insert Match Squad 
        for squad in squad_data:
            cursor.execute("""
                INSERT INTO MATCH_SQUAD (squad_id, match_id, team_id, player_id)
                VALUES (%s, %s, %s, %s)
            """, (squad['squad_id'], squad['match_id'], squad['team_id'], squad['player_id']))

        # 6b. Insert Innings
        for inn in innings_data:
            cursor.execute("""
                INSERT INTO INNINGS
                (innings_id, match_id, batting_team_id, bowling_team_id, innings_number, total_runs, total_wickets, legal_balls, extras_byes, extras_legbyes, extras_wides, extras_noballs, extras_penalty)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (inn['innings_id'], inn['match_id'], inn['batting_team_id'], inn['bowling_team_id'], inn['innings_number'], inn['total_runs'], inn['total_wickets'], inn['legal_balls'], inn['extras_byes'], inn['extras_legbyes'], inn['extras_wides'], inn['extras_noballs'], inn['extras_penalty']))

        # 7. Insert Performance
        for (player_id, innings_id), stats in performance_data.items():
            perf_id = stable_id('performance', innings_id, player_id)
            
            # Convert balls bowled to overs (e.g., 8 balls = 1.2 overs)
            balls = stats['balls_bowled']
            overs_bowled = (balls // 6) + ((balls % 6) / 10.0)

            cursor.execute("""
                INSERT INTO PERFORMANCE
                (performance_id, player_id, innings_id, team_id, runs_scored, balls_faced, is_out, batting_position, fours, sixes, dismissal_kind, dismissal_bowler_id, dismissal_fielder_id, wickets_taken, overs_bowled, balls_bowled, maidens, runs_conceded, wides_bowled, noballs_bowled, catches, stumpings, run_outs)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (perf_id, player_id, innings_id, stats['team_id'], stats['runs_scored'], stats['balls_faced'], stats['is_out'], stats['batting_position'], stats['fours'], stats['sixes'], stats['dismissal_kind'], stats['dismissal_bowler_id'], stats['dismissal_fielder_id'], stats['wickets_taken'], overs_bowled, stats['balls_bowled'], stats['maidens'], stats['runs_conceded'], stats['wides_bowled'], stats['noballs_bowled'], stats['catches'], stats['stumpings'], stats['run_outs']))

        # 7b. insert fall of wickets
        for fow in fall_of_wickets:
            cursor.execute("""
                INSERT INTO FALL_OF_WICKETS
                (fow_id, innings_id, wicket_number, runs_at_fall, over_number, ball_in_over, player_out_id)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (fow['fow_id'], fow['innings_id'], fow['wicket_number'], fow['runs_at_fall'], fow['over_number'], fow['ball_in_over'], fow['player_out_id']))

        # 8. insert import log.
        cursor.execute("""
            INSERT INTO IMPORT_LOG (source_url, status, is_duplicate, notes) 
            VALUES (%s, %s, %s, %s)
        """, (source_name, "SUCCESS", 1 if is_duplicate else 0, import_note))

        conn.commit()
        logger.info(
            "ETL completed for %s: %d innings, %d performance rows, "
            "%d squad rows, %d wickets%s",
            source_name, len(innings_data), len(performance_data),
            len(squad_data), len(fall_of_wickets),
            " (REPLACED a duplicate match)." if is_duplicate else ".",
        )

    except Exception:
        # Roll back so a half-written match can't be mistaken for a complete one.
        # The IMPORT_LOG 'SUCCESS' row is inside this transaction too, so it goes
        # with it - a failed run never leaves a record claiming it worked.
        if conn is not None and conn.is_connected():
            conn.rollback()
            logger.warning("Rolled back the transaction for %s.", source_name)
        raise
    finally:
        if conn is not None and conn.is_connected():
            if cursor is not None:
                cursor.close()
            conn.close()
            logger.info("MySQL connection is closed.")

def run_etl(json_file=None):
    """Import one Cricsheet JSON file, recording the outcome either way."""
    # The scraper invokes this script once per file, passing the path as argv[1].
    if json_file is None:
        json_file = sys.argv[1] if len(sys.argv) > 1 else 'wi_201706.json'

    # IMPORT_LOG is keyed on the bare filename, which is what the scraper checks.
    source_name = os.path.basename(json_file)
    stage = {'name': 'startup'}

    try:
        _extract_and_load(json_file, source_name, stage)
        return True
    except Exception as exc:
        record_failure(source_name, stage['name'], exc)
        return False


if __name__ == "__main__":
    # Exit non-zero on failure so the scraper reports the file as failed rather
    # than moving on as though it had imported.
    sys.exit(0 if run_etl() else 1)
