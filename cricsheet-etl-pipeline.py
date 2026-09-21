import os
import sys
import json
import uuid
import logging
import traceback
import mysql.connector
from datetime import datetime

# Connection details in one place - the failure logger needs to reconnect after a
# failed run, and it must not drift from what the main run used.
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

# Fixed namespace for deterministic ids. Every id below is derived from the
# natural key of the thing it identifies, so importing the same source data
# twice produces the same ids and the INSERTs collide instead of duplicating.
# Do not change this value - it would orphan every id already in the database.
ID_NAMESPACE = uuid.UUID('6f1b9d64-2c3e-5a8f-9b4d-1e7c0a5f3d82')

def stable_id(kind, *parts):
    """A repeatable id for one real-world thing, derived from its natural key."""
    key = kind + '|' + '|'.join('' if p is None else str(p) for p in parts)
    return str(uuid.uuid5(ID_NAMESPACE, key))

def new_perf(team_id):
    """A fresh performance accumulator for one (player, innings)."""
    return {
        'team_id': team_id,
        'runs_scored': 0, 'balls_faced': 0, 'is_out': 0,
        'wickets_taken': 0, 'balls_bowled': 0, 'runs_conceded': 0,
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

    # 6 and 7. INNINGS & PERFORMANCE
    innings_data = []
    performance_data = {} # (player_id, innings_id) -> stats

    for i, inning in enumerate(data.get('innings', [])):
        innings_id = stable_id('innings', match_id, i + 1)
        batting_team_name = inning.get('team')
        batting_team_id = teams.get(batting_team_name)
        
        bowling_team_name = team_list[1] if team_list[0] == batting_team_name else team_list[0]
        bowling_team_id = teams.get(bowling_team_name)

        total_runs = 0
        total_wickets = 0

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

                # get bowlwers performance for the innings, also gets wides/noballs
                if bowler_id:
                    perf_key = (bowler_id, innings_id)
                    if perf_key not in performance_data:
                        performance_data[perf_key] = new_perf(bowling_team_id)
                    # wides and no-balls are not legal balls bowled
                    if 'wides' not in extras and 'noballs' not in extras:
                        performance_data[perf_key]['balls_bowled'] += 1
                    # Runs charged to the bowler: off the bat + wides + no-balls
                    # (byes and leg-byes are NOT the bowler's fault, so excluding)
                    performance_data[perf_key]['runs_conceded'] += (
                        batter_runs + extras.get('wides', 0) + extras.get('noballs', 0)
                    )

                    # Same figures again, but scoped to this over only (for maidens)
                    if bowler_id not in over_tally:
                        over_tally[bowler_id] = {'legal_balls': 0, 'runs_conceded': 0}
                    if 'wides' not in extras and 'noballs' not in extras:
                        over_tally[bowler_id]['legal_balls'] += 1
                    over_tally[bowler_id]['runs_conceded'] += (
                        batter_runs + extras.get('wides', 0) + extras.get('noballs', 0)
                    )

                # Wickets
                if 'wickets' in delivery:
                    total_wickets += len(delivery['wickets'])
                    for wicket in delivery['wickets']:
                        kind = wicket.get('kind')
                        if bowler_id and kind not in ['run out', 'retired hurt', 'obstructing the field']:
                            performance_data[(bowler_id, innings_id)]['wickets_taken'] += 1

                        # Mark the batter as out 
                        # "retired hurt" is not a dismissal, so it stays not-out.
                        if kind != 'retired hurt':
                            out_id = players.get(wicket.get('player_out'))
                            if out_id:
                                out_key = (out_id, innings_id)
                                if out_key not in performance_data:
                                    # non-striker run out before facing a ball
                                    performance_data[out_key] = new_perf(batting_team_id)
                                performance_data[out_key]['is_out'] = 1

                        # Fielding stats (catches, stumpings, run outs).
                        # Cricsheet lists a single fielder per dismissal, so a run out
                        # is credited to that one player rather than shared around.
                        for fielder in (wicket.get('fielders') or []):
                            fielder_id = players.get(fielder.get('name'))
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

        # 4. Insert Players
        for player_name, player_id in players.items():
            cursor.execute("""
                INSERT IGNORE INTO PLAYERS (player_id, player_name) 
                VALUES (%s, %s)
            """, (player_id, player_name))

        # Is this match already in the database? match_id is derived from the
        # match's natural key, so a row here means this same fixture has been
        # imported before - whether from this file or from a differently-named
        # one. The import still goes ahead and replaces it, but it gets flagged
        # rather than passing silently as a first-time import.
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
        # match_id is derived from the match's natural key, so re-importing the
        # same fixture hits the primary key and updates in place rather than
        # inserting a second copy. The UPDATE clause means a re-run also picks up
        # corrections and newly-extracted columns instead of being ignored.
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

        # Clear this match's existing innings and performance rows so the rebuild
        # below is a genuine replace. Without this, a row that should no longer
        # exist - a player dropped by a source-data correction - would survive.
        # PERFORMANCE is deleted explicitly rather than left to the cascade on
        # INNINGS, so the pipeline does not depend on how the FKs are declared.
        cursor.execute("""
            DELETE p FROM PERFORMANCE p
            INNER JOIN INNINGS i ON i.innings_id = p.innings_id
            WHERE i.match_id = %s
        """, (match_id,))
        cursor.execute("DELETE FROM INNINGS WHERE match_id = %s", (match_id,))

        # 6. Insert Innings
        for inn in innings_data:
            cursor.execute("""
                INSERT INTO INNINGS 
                (innings_id, match_id, batting_team_id, bowling_team_id, innings_number, total_runs, total_wickets) 
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (inn['innings_id'], inn['match_id'], inn['batting_team_id'], inn['bowling_team_id'], inn['innings_number'], inn['total_runs'], inn['total_wickets']))

        # 7. Insert Performance
        for (player_id, innings_id), stats in performance_data.items():
            perf_id = stable_id('performance', innings_id, player_id)
            
            # Convert balls bowled to overs (e.g., 8 balls = 1.2 overs)
            balls = stats['balls_bowled']
            overs_bowled = (balls // 6) + ((balls % 6) / 10.0)

            cursor.execute("""
                INSERT INTO PERFORMANCE
                (performance_id, player_id, innings_id, team_id, runs_scored, balls_faced, is_out, wickets_taken, overs_bowled, balls_bowled, maidens, runs_conceded, catches, stumpings, run_outs)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (perf_id, player_id, innings_id, stats['team_id'], stats['runs_scored'], stats['balls_faced'], stats['is_out'], stats['wickets_taken'], overs_bowled, stats['balls_bowled'], stats['maidens'], stats['runs_conceded'], stats['catches'], stats['stumpings'], stats['run_outs']))

        # 8. Insert Import Log. The run succeeded either way, so status stays
        # SUCCESS - the scraper's already-imported check depends on that. The
        # duplicate is recorded alongside it, so re-imports stay queryable:
        #   SELECT * FROM IMPORT_LOG WHERE is_duplicate = 1;
        cursor.execute("""
            INSERT INTO IMPORT_LOG (source_url, status, is_duplicate, notes) 
            VALUES (%s, %s, %s, %s)
        """, (source_name, "SUCCESS", 1 if is_duplicate else 0, import_note))

        conn.commit()
        logger.info(
            "ETL completed for %s: %d innings, %d performance rows%s",
            source_name, len(innings_data), len(performance_data),
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
