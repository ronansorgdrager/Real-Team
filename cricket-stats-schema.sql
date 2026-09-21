SET UNIQUE_CHECKS=0;
SET FOREIGN_KEY_CHECKS=0;

CREATE SCHEMA IF NOT EXISTS `cricket_explorer` DEFAULT CHARACTER SET utf8mb4;
USE `cricket_explorer`;

-- 1. COMPETITIONS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`COMPETITIONS` (
  `comp_id` VARCHAR(50) NOT NULL,
  `comp_name` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`comp_id`),
  UNIQUE KEY `uq_competition_name` (`comp_name`)
) ENGINE = InnoDB;

-- 2. SEASONS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`SEASONS` (
  `season_id` VARCHAR(50) NOT NULL,
  `comp_id` VARCHAR(50) NOT NULL,
  `season_name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`season_id`),
  UNIQUE KEY `uq_season_in_competition` (`comp_id`, `season_name`),
  CONSTRAINT `fk_season_comp`
    FOREIGN KEY (`comp_id`)
    REFERENCES `cricket_explorer`.`COMPETITIONS` (`comp_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB;

-- 3. TEAMS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`TEAMS` (
  `team_id` VARCHAR(50) NOT NULL,
  `team_name` VARCHAR(255) NOT NULL,
  `home_ground` VARCHAR(255) NULL,
  PRIMARY KEY (`team_id`),
  UNIQUE KEY `uq_team_name` (`team_name`)
) ENGINE = InnoDB;

-- 4. MATCHES
-- match_id is not random: the ETL derives it from the match's natural key
-- (date + the two teams + venue + competition + season + gender + match_type),
-- so re-importing the same source file collides on the primary key instead of
-- inserting the fixture a second time. A plain UNIQUE over those columns can't
-- do the job here because venue is nullable and MySQL lets NULLs repeat.
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`MATCHES` (
  `match_id` VARCHAR(50) NOT NULL,
  `season_id` VARCHAR(50) NOT NULL,
  `match_date` DATE NOT NULL,
  `venue` VARCHAR(255) NULL,
  `team1_id` VARCHAR(50) NOT NULL,
  `team2_id` VARCHAR(50) NOT NULL,
  `winning_team_id` VARCHAR(50) NULL,
  `win_type` VARCHAR(50) NULL,
  `win_margin` INT NULL,
  `win_method` VARCHAR(50) NULL,
  `match_type` VARCHAR(50) NULL,
  `toss_winner_id` VARCHAR(50) NULL,
  `toss_decision` VARCHAR(50) NULL,
  PRIMARY KEY (`match_id`),
  CONSTRAINT `fk_match_season`
    FOREIGN KEY (`season_id`)
    REFERENCES `cricket_explorer`.`SEASONS` (`season_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_match_team1`
    FOREIGN KEY (`team1_id`)
    REFERENCES `cricket_explorer`.`TEAMS` (`team_id`)
    ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `fk_match_team2`
    FOREIGN KEY (`team2_id`)
    REFERENCES `cricket_explorer`.`TEAMS` (`team_id`)
    ON DELETE NO ACTION ON UPDATE CASCADE
) ENGINE = InnoDB;

-- 5. PLAYERS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`PLAYERS` (
  `player_id` VARCHAR(50) NOT NULL,
  `player_name` VARCHAR(255) NOT NULL,
  `birth_date` DATE NULL,
  `nationality` VARCHAR(100) NULL,
  PRIMARY KEY (`player_id`)
) ENGINE = InnoDB;

-- 6. INNINGS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`INNINGS` (
  `innings_id` VARCHAR(50) NOT NULL,
  `match_id` VARCHAR(50) NOT NULL,
  `batting_team_id` VARCHAR(50) NOT NULL,
  `bowling_team_id` VARCHAR(50) NOT NULL,
  `innings_number` INT NOT NULL,
  `total_runs` INT NULL DEFAULT 0,
  `total_wickets` INT NULL DEFAULT 0,
  -- Legal deliveries only: wides and no-balls are excluded, so this is the
  -- number the overs figure and the run rate are built from. It is NOT the same
  -- as SUM(PERFORMANCE.balls_faced), which counts no-balls because the batter
  -- did face them.
  `legal_balls` INT NULL DEFAULT 0,
  -- Extras are stored as RUNS, not as delivery counts, so the five columns sum
  -- to the innings' extras total and reconcile against total_runs:
  --   total_runs = SUM(batting runs_scored) + the five columns below.
  -- The per-bowler wides_bowled / noballs_bowled columns on PERFORMANCE count
  -- DELIVERIES instead - different question, different unit, different name.
  `extras_byes` INT NULL DEFAULT 0,
  `extras_legbyes` INT NULL DEFAULT 0,
  `extras_wides` INT NULL DEFAULT 0,
  `extras_noballs` INT NULL DEFAULT 0,
  `extras_penalty` INT NULL DEFAULT 0,
  PRIMARY KEY (`innings_id`),
  UNIQUE KEY `uq_innings_in_match` (`match_id`, `innings_number`),
  CONSTRAINT `fk_innings_match`
    FOREIGN KEY (`match_id`)
    REFERENCES `cricket_explorer`.`MATCHES` (`match_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB;

-- 7. PERFORMANCE (Fact Table) - added is_out, runs_conceeded, balls_bowled, maidens, run_outs - enables more meaningful reporting
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`PERFORMANCE` (
  `performance_id` VARCHAR(100) NOT NULL,
  `player_id` VARCHAR(50) NOT NULL,
  `innings_id` VARCHAR(50) NOT NULL,
  `team_id` VARCHAR(50) NOT NULL,
  `runs_scored` INT NULL DEFAULT 0,
  `balls_faced` INT NULL DEFAULT 0,
  `is_out` TINYINT(1) NULL DEFAULT 0,
  -- Where this player came in, 1-11, derived from the order batters and
  -- non-strikers first appear in the ball-by-ball data. NULL on a row that is
  -- only a bowling or fielding contribution.
  `batting_position` INT NULL,
  `fours` INT NULL DEFAULT 0,
  `sixes` INT NULL DEFAULT 0,
  -- How the batter was dismissed. dismissal_kind is Cricsheet's own wording
  -- ('caught', 'lbw', 'run out', ...). The two id columns are what a scorecard
  -- line needs to render: "c <fielder> b <bowler>".
  --   * dismissal_bowler_id is set only for bowler-credited dismissals, so it
  --     is NULL on a run out - which is exactly how a scorecard reads.
  --   * dismissal_fielder_id holds the first fielder Cricsheet names.
  -- Neither carries a foreign key to PLAYERS. They are nullable soft
  -- references to a row the same import already inserted, and the pipeline runs
  -- under an account without REFERENCES - the same reasoning as
  -- ETL_ERROR_LOG.log_id below.
  `dismissal_kind` VARCHAR(50) NULL,
  `dismissal_bowler_id` VARCHAR(50) NULL,
  `dismissal_fielder_id` VARCHAR(50) NULL,
  `wickets_taken` INT NULL DEFAULT 0,
  `overs_bowled` DECIMAL(4,1) NULL DEFAULT 0.0,
  `balls_bowled` INT NULL DEFAULT 0,
  `maidens` INT NULL DEFAULT 0,
  `runs_conceded` INT NULL DEFAULT 0,
  -- Counts of DELIVERIES, which is what a bowling card's Wd / NB columns show.
  -- INNINGS.extras_wides / extras_noballs count RUNS, so the two only agree
  -- when every wide cost exactly one run.
  `wides_bowled` INT NULL DEFAULT 0,
  `noballs_bowled` INT NULL DEFAULT 0,
  `catches` INT NULL DEFAULT 0,
  `stumpings` INT NULL DEFAULT 0,
  `run_outs` INT NULL DEFAULT 0,
  PRIMARY KEY (`performance_id`),
  -- One row per player per innings. This is what stops a re-import from
  -- double-counting a player's runs, wickets and fielding.
  UNIQUE KEY `uq_performance_player_innings` (`player_id`, `innings_id`),
  CONSTRAINT `fk_perf_player`
    FOREIGN KEY (`player_id`)
    REFERENCES `cricket_explorer`.`PLAYERS` (`player_id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_perf_innings`
    FOREIGN KEY (`innings_id`)
    REFERENCES `cricket_explorer`.`INNINGS` (`innings_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB;

-- 8. MATCH SQUAD
-- The eleven named for each team, taken from the source file's team sheets.
-- PERFORMANCE only gets a row when a player actually does something, so this is
-- the only place a player who was selected but never batted, bowled or fielded
-- exists. "Did not bat" is this table LEFT JOINed against the batting rows of
-- the innings.
--
-- It also keeps non-players out: the source registry lists umpires alongside
-- the teams, and matching against the team sheets is what separates them.
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`MATCH_SQUAD` (
  `squad_id` VARCHAR(100) NOT NULL,
  `match_id` VARCHAR(50) NOT NULL,
  `team_id` VARCHAR(50) NOT NULL,
  `player_id` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`squad_id`),
  UNIQUE KEY `uq_squad_player_in_match` (`match_id`, `player_id`),
  KEY `ix_squad_team` (`match_id`, `team_id`),
  KEY `ix_squad_player` (`player_id`)
  -- No foreign keys, for the reason given on ETL_ERROR_LOG.log_id below: the
  -- etl account is not granted REFERENCES, and a real FK here would make this
  -- file un-runnable by the account that runs the pipeline. Nothing depends on
  -- a cascade - the ETL clears this table by match_id on a re-import, the same
  -- way it deletes PERFORMANCE explicitly rather than trusting the cascade on
  -- INNINGS.
) ENGINE = InnoDB;

-- 9. FALL OF WICKETS
-- One row per wicket, in the order they fell: "1-38 Katie Mack (4.2 ov)".
-- runs_at_fall is the team score including any runs scored on that delivery.
-- over_number and ball_in_over are derived from the legal-ball count at the
-- moment the wicket fell, so a wicket off a no-ball does not advance the over.
-- Retired hurt is not a wicket and does not appear here.
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`FALL_OF_WICKETS` (
  `fow_id` VARCHAR(100) NOT NULL,
  `innings_id` VARCHAR(50) NOT NULL,
  `wicket_number` INT NOT NULL,
  `runs_at_fall` INT NOT NULL,
  `over_number` INT NULL,
  `ball_in_over` INT NULL,
  `player_out_id` VARCHAR(50) NULL,
  PRIMARY KEY (`fow_id`),
  UNIQUE KEY `uq_fow_in_innings` (`innings_id`, `wicket_number`)
  -- No foreign key, same reason as MATCH_SQUAD above. The ETL deletes this
  -- table's rows for the match explicitly before rebuilding the innings, so
  -- nothing relies on a cascade from INNINGS.
) ENGINE = InnoDB;

-- 10. IMPORT LOG
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`IMPORT_LOG` (
  `log_id` INT NOT NULL AUTO_INCREMENT,
  `import_timestamp` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `source_url` VARCHAR(255) NULL,
  `status` VARCHAR(50) NULL,
  -- Set when the import replaced a match that was already in the database.
  -- status stays SUCCESS in that case - the run did work - so this is what
  -- separates a first-time import from a re-import.
  `is_duplicate` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` VARCHAR(500) NULL,
  PRIMARY KEY (`log_id`),
  KEY `ix_import_log_source` (`source_url`, `status`),
  KEY `ix_import_log_duplicate` (`is_duplicate`)
) ENGINE = InnoDB;

-- 11. ETL ERROR LOG
-- One row per failure during an ETL run. Written after the failed run's
-- transaction is rolled back, so the error survives even though the partial
-- match data does not.
--
-- log_id points at the IMPORT_LOG row for the same failure, but deliberately has
-- no foreign key: the etl user is not granted REFERENCES, so a real FK here
-- would make the schema un-runnable by the account that actually runs the
-- pipeline. It is nullable in any case, because a failure can happen before
-- there is anything to link to - a missing file, malformed JSON, or a
-- connection that never opened.
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`ETL_ERROR_LOG` (
  `error_id` INT NOT NULL AUTO_INCREMENT,
  `occurred_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  `log_id` INT NULL,
  `source_file` VARCHAR(255) NULL,
  `stage` VARCHAR(50) NULL,
  `error_type` VARCHAR(100) NULL,
  `error_message` TEXT NULL,
  `traceback` TEXT NULL,
  PRIMARY KEY (`error_id`),
  KEY `ix_error_log_source` (`source_file`, `occurred_at`),
  KEY `ix_error_log_import` (`log_id`)
) ENGINE = InnoDB;

SET FOREIGN_KEY_CHECKS=1;
SET UNIQUE_CHECKS=1;