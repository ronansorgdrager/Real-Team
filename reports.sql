-- =============================================================================
-- SECTION A :: PER-PLAYER BATTING
-- =============================================================================

-- A1. Career batting card ------------------------------------------------------
-- Now includes average, not-outs and true ducks (requires the is_out column).
SELECT
    pl.player_name,
    COUNT(*)                                              AS innings,
    SUM(p.is_out = 0)                                     AS not_outs,
    SUM(p.runs_scored)                                    AS runs,
    SUM(p.balls_faced)                                    AS balls_faced,
    MAX(p.runs_scored)                                    AS highest_score,
    ROUND(SUM(p.runs_scored) / NULLIF(SUM(p.is_out), 0), 2)            AS batting_average,
    ROUND(100 * SUM(p.runs_scored) / NULLIF(SUM(p.balls_faced), 0), 2) AS strike_rate,
    SUM(p.runs_scored >= 50 AND p.runs_scored < 100)      AS fifties,
    SUM(p.runs_scored >= 100)                             AS hundreds,
    SUM(p.runs_scored = 0 AND p.is_out = 1)               AS ducks,
    ROUND(SUM(p.runs_scored >= 100)
          / NULLIF(SUM(p.runs_scored >= 50), 0), 2)       AS conversion_rate
FROM PERFORMANCE p
JOIN INNINGS i  ON i.innings_id = p.innings_id
JOIN PLAYERS pl ON pl.player_id = p.player_id
WHERE p.team_id = i.batting_team_id
GROUP BY pl.player_id, pl.player_name
ORDER BY runs DESC;


-- A2. Innings-by-innings list (the "innings list" view you flagged as heavily used)
-- One row per performance, most recent first. Filter by player with the WHERE.
SELECT
    pl.player_name,
    m.match_date,
    opp.team_name AS opposition,
    m.venue,
    i.innings_number,
    p.runs_scored,
    p.balls_faced,
    ROUND(100 * p.runs_scored / NULLIF(p.balls_faced, 0), 1) AS strike_rate
FROM PERFORMANCE p
JOIN INNINGS i  ON i.innings_id = p.innings_id
JOIN MATCHES m  ON m.match_id   = i.match_id
JOIN TEAMS  opp ON opp.team_id  = i.bowling_team_id
JOIN PLAYERS pl ON pl.player_id = p.player_id
WHERE p.team_id = i.batting_team_id
  AND p.player_id = @player_id           -- SET @player_id = '<uuid>'; first
ORDER BY m.match_date DESC, i.innings_number;


-- A3. Rolling last-10-innings form --------------------------------------------
SELECT player_name, match_date, runs_scored, balls_faced,
       ROUND(AVG(runs_scored) OVER w, 1) AS rolling_avg_last10
FROM (
    SELECT pl.player_name, m.match_date, p.runs_scored, p.balls_faced, p.player_id
    FROM PERFORMANCE p
    JOIN INNINGS i  ON i.innings_id = p.innings_id
    JOIN MATCHES m  ON m.match_id   = i.match_id
    JOIN PLAYERS pl ON pl.player_id = p.player_id
    WHERE p.team_id = i.batting_team_id
      AND p.player_id = @player_id
) t
WINDOW w AS (ORDER BY match_date ROWS BETWEEN 9 PRECEDING AND CURRENT ROW)
ORDER BY match_date DESC;


-- A4. Batting split by OPPOSITION ---------------------------------------------
-- Swap "opposition" grouping for m.venue, s.season_name, or m.match_type to get
-- venue / season / format splits (same shape).
SELECT
    opp.team_name AS opposition,
    COUNT(*)               AS innings,
    SUM(p.runs_scored)     AS runs,
    MAX(p.runs_scored)     AS highest_score,
    ROUND(100 * SUM(p.runs_scored) / NULLIF(SUM(p.balls_faced), 0), 2) AS strike_rate
FROM PERFORMANCE p
JOIN INNINGS i  ON i.innings_id = p.innings_id
JOIN TEAMS  opp ON opp.team_id  = i.bowling_team_id
WHERE p.team_id = i.batting_team_id
  AND p.player_id = @player_id
GROUP BY opp.team_id, opp.team_name
ORDER BY runs DESC;


-- A5. Batting split by SEASON / YEAR ------------------------------------------
SELECT
    s.season_name,
    COUNT(*)           AS innings,
    SUM(p.runs_scored) AS runs,
    ROUND(100 * SUM(p.runs_scored) / NULLIF(SUM(p.balls_faced), 0), 2) AS strike_rate
FROM PERFORMANCE p
JOIN INNINGS i  ON i.innings_id = p.innings_id
JOIN MATCHES m  ON m.match_id   = i.match_id
JOIN SEASONS s  ON s.season_id  = m.season_id
WHERE p.team_id = i.batting_team_id
  AND p.player_id = @player_id
GROUP BY s.season_id, s.season_name
ORDER BY s.season_name;


-- =============================================================================
-- SECTION B :: PER-PLAYER BOWLING
-- =============================================================================

-- B1. Career bowling card ------------------------------------------------------
-- Now includes economy, average and strike rate (requires balls_bowled + runs_conceded),
-- and uses the clean integer balls_bowled column instead of the overs-notation hack.
-- maidens is a stored count (a maiden = an over the bowler was charged no runs for;
-- byes and leg-byes don't break one). Still NOT collected: dot% / extras breakdown.
SELECT
    pl.player_name,
    COUNT(*)                                          AS innings_bowled,
    SUM(p.balls_bowled)                               AS balls_bowled,
    CONCAT(FLOOR(SUM(p.balls_bowled) / 6), '.', MOD(SUM(p.balls_bowled), 6)) AS overs_pretty,
    SUM(p.maidens)                                    AS maidens,
    SUM(p.runs_conceded)                              AS runs_conceded,
    SUM(p.wickets_taken)                              AS wickets,
    MAX(p.wickets_taken)                              AS best_wkts_in_innings,
    SUM(p.wickets_taken >= 4 AND p.wickets_taken < 5) AS four_fers,
    SUM(p.wickets_taken >= 5)                         AS five_fers,
    ROUND(6 * SUM(p.runs_conceded) / NULLIF(SUM(p.balls_bowled), 0), 2) AS economy,
    ROUND(SUM(p.runs_conceded) / NULLIF(SUM(p.wickets_taken), 0), 2)    AS bowling_average,
    ROUND(SUM(p.balls_bowled)  / NULLIF(SUM(p.wickets_taken), 0), 1)    AS bowling_strike_rate
FROM PERFORMANCE p
JOIN INNINGS i  ON i.innings_id = p.innings_id
JOIN PLAYERS pl ON pl.player_id = p.player_id
WHERE p.team_id = i.bowling_team_id
  AND p.balls_bowled > 0
GROUP BY pl.player_id, pl.player_name
ORDER BY wickets DESC;


-- B2. Bowling split by opposition / season (same swap trick as A4/A5) ----------
SELECT
    opp.team_name AS opposition,
    COUNT(*)             AS innings_bowled,
    SUM(p.wickets_taken) AS wickets,
    SUM(FLOOR(p.overs_bowled) * 6
        + ROUND((p.overs_bowled - FLOOR(p.overs_bowled)) * 10)) AS balls_bowled
FROM PERFORMANCE p
JOIN INNINGS i  ON i.innings_id = p.innings_id
JOIN TEAMS  opp ON opp.team_id  = i.batting_team_id   -- for a bowler, opposition = the batting team
WHERE p.team_id = i.bowling_team_id
  AND p.overs_bowled > 0
  AND p.player_id = @player_id
GROUP BY opp.team_id, opp.team_name
ORDER BY wickets DESC;


-- =============================================================================
-- SECTION C :: PER-PLAYER FIELDING
-- =============================================================================

-- C1. Fielding / keeping totals -----------------------------------------------
-- Answerable: catches, stumpings, run outs. Cricsheet names one fielder per run out,
-- so run_outs is a count of run outs effected, not of fielders involved.
-- NOT captured by ETL: direct hits vs relays, drops.
SELECT
    pl.player_name,
    SUM(p.catches)   AS catches,
    SUM(p.stumpings) AS stumpings,
    SUM(p.run_outs)  AS run_outs
FROM PERFORMANCE p
JOIN PLAYERS pl ON pl.player_id = p.player_id
GROUP BY pl.player_id, pl.player_name
HAVING catches > 0 OR stumpings > 0 OR run_outs > 0
ORDER BY catches DESC, stumpings DESC, run_outs DESC;


-- =============================================================================
-- SECTION D :: PER-TEAM RESULTS
-- =============================================================================

-- D1. Win / loss / NR record + win% -------------------------------------------
SELECT
    t.team_name,
    COUNT(*)                                                               AS played,
    SUM(m.winning_team_id = t.team_id)                                     AS won,
    SUM(m.winning_team_id IS NOT NULL AND m.winning_team_id <> t.team_id)  AS lost,
    SUM(m.winning_team_id IS NULL)                                         AS no_result_or_tie,
    ROUND(100 * SUM(m.winning_team_id = t.team_id) / COUNT(*), 1)          AS win_pct
FROM MATCHES m
JOIN TEAMS t ON t.team_id IN (m.team1_id, m.team2_id)
GROUP BY t.team_id, t.team_name
ORDER BY win_pct DESC;


-- D2. Head-to-head between two teams ------------------------------------------
SELECT
    a.team_name AS team_a,
    b.team_name AS team_b,
    COUNT(*)                                 AS matches,
    SUM(m.winning_team_id = a.team_id)       AS team_a_wins,
    SUM(m.winning_team_id = b.team_id)       AS team_b_wins,
    SUM(m.winning_team_id IS NULL)           AS no_result_or_tie
FROM MATCHES m
JOIN TEAMS a ON a.team_id = @team_a_id
JOIN TEAMS b ON b.team_id = @team_b_id
WHERE (m.team1_id = a.team_id AND m.team2_id = b.team_id)
   OR (m.team1_id = b.team_id AND m.team2_id = a.team_id)
GROUP BY a.team_name, b.team_name;


-- D3. Results by season and format --------------------------------------------
SELECT
    t.team_name, s.season_name, m.match_type,
    COUNT(*)                            AS played,
    SUM(m.winning_team_id = t.team_id)  AS won
FROM MATCHES m
JOIN SEASONS s ON s.season_id = m.season_id
JOIN TEAMS   t ON t.team_id IN (m.team1_id, m.team2_id)
WHERE t.team_id = @team_id
GROUP BY t.team_name, s.season_name, m.match_type
ORDER BY s.season_name;


-- D4. Current form / streak (last 10 results for a team, newest first) ---------
-- Gives W/L/NR sequence; read the top run of identical letters as the current streak.
SELECT
    m.match_date,
    CASE
        WHEN m.winning_team_id = @team_id THEN 'W'
        WHEN m.winning_team_id IS NULL    THEN 'NR/Tie'
        ELSE 'L'
    END AS result
FROM MATCHES m
WHERE @team_id IN (m.team1_id, m.team2_id)
ORDER BY m.match_date DESC
LIMIT 10;


-- =============================================================================
-- SECTION E :: TOSS & INNINGS ORDER
-- =============================================================================

-- E1. Bat-first vs chasing win rate, by venue ---------------------------------
-- Bat-first team = batting team of innings #1.
SELECT
    m.venue,
    COUNT(*)                                        AS decided_matches,
    SUM(m.winning_team_id = i1.batting_team_id)     AS bat_first_wins,
    SUM(m.winning_team_id = i1.bowling_team_id)     AS chasing_wins,
    ROUND(100 * SUM(m.winning_team_id = i1.batting_team_id) / COUNT(*), 1) AS bat_first_win_pct
FROM MATCHES m
JOIN INNINGS i1 ON i1.match_id = m.match_id AND i1.innings_number = 1
WHERE m.winning_team_id IS NOT NULL
GROUP BY m.venue
ORDER BY decided_matches DESC;


-- E2. Toss win -> match win correlation ---------------------------------------
SELECT
    COUNT(*)                                              AS decided_matches,
    SUM(m.toss_winner_id = m.winning_team_id)             AS toss_winner_also_won,
    ROUND(100 * SUM(m.toss_winner_id = m.winning_team_id)
          / COUNT(*), 1)                                  AS toss_to_win_pct
FROM MATCHES m
WHERE m.winning_team_id IS NOT NULL
  AND m.toss_winner_id  IS NOT NULL;


-- E3. Toss decision breakdown (bat vs field) and how it worked out ------------
SELECT
    m.toss_decision,
    COUNT(*)                                    AS matches,
    SUM(m.toss_winner_id = m.winning_team_id)   AS toss_winner_won
FROM MATCHES m
WHERE m.toss_decision IS NOT NULL
GROUP BY m.toss_decision;


-- E4. Average 1st-innings score & successful-chase rate per ground ------------
SELECT
    m.venue,
    COUNT(*)                                    AS matches,
    ROUND(AVG(i1.total_runs), 1)                AS avg_first_innings_score,
    SUM(m.winning_team_id = i1.bowling_team_id) AS successful_chases,
    ROUND(100 * SUM(m.winning_team_id = i1.bowling_team_id)
          / NULLIF(SUM(m.winning_team_id IS NOT NULL), 0), 1) AS chase_win_pct
FROM MATCHES m
JOIN INNINGS i1 ON i1.match_id = m.match_id AND i1.innings_number = 1
GROUP BY m.venue
ORDER BY matches DESC;


-- =============================================================================
-- SECTION F :: TEAM BATTING UNIT
-- =============================================================================

-- F1. Team totals: average / highest / lowest, plus run rate ------------------
-- Run rate uses balls faced summed from the batting PERFORMANCE rows of each innings
-- (legal balls, since the ETL excludes wides from balls_faced).
SELECT
    bt.team_name,
    COUNT(*)                        AS innings,
    ROUND(AVG(i.total_runs), 1)     AS avg_total,
    MAX(i.total_runs)               AS highest_total,
    MIN(i.total_runs)               AS lowest_total,
    ROUND(6 * SUM(i.total_runs) / NULLIF(SUM(bf.balls), 0), 2) AS run_rate
FROM INNINGS i
JOIN TEAMS bt ON bt.team_id = i.batting_team_id
JOIN (
    SELECT p.innings_id, SUM(p.balls_faced) AS balls
    FROM PERFORMANCE p
    JOIN INNINGS ii ON ii.innings_id = p.innings_id
    WHERE p.team_id = ii.batting_team_id
    GROUP BY p.innings_id
) bf ON bf.innings_id = i.innings_id
GROUP BY bt.team_id, bt.team_name
ORDER BY avg_total DESC;


-- =============================================================================
-- SECTION G :: VENUE / GROUND PROFILES
-- =============================================================================

-- G1. A team's record at each ground ------------------------------------------
SELECT
    t.team_name, m.venue,
    COUNT(*)                            AS played,
    SUM(m.winning_team_id = t.team_id)  AS won,
    ROUND(100 * SUM(m.winning_team_id = t.team_id) / COUNT(*), 1) AS win_pct
FROM MATCHES m
JOIN TEAMS t ON t.team_id IN (m.team1_id, m.team2_id)
WHERE t.team_id = @team_id
GROUP BY t.team_name, m.venue
ORDER BY played DESC;


-- G2. Ground profile: average score & chase-friendliness ----------------------
-- (pace-vs-spin bias and dew effect are NOT possible without ball-by-ball data.)
SELECT
    m.venue,
    COUNT(DISTINCT m.match_id)                  AS matches,
    ROUND(AVG(i.total_runs), 1)                 AS avg_innings_score,
    MAX(i.total_runs)                           AS highest_total
FROM MATCHES m
JOIN INNINGS i ON i.match_id = m.match_id
GROUP BY m.venue
ORDER BY matches DESC;
