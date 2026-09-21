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
  `wickets_taken` INT NULL DEFAULT 0,
  `overs_bowled` DECIMAL(4,1) NULL DEFAULT 0.0,
  `balls_bowled` INT NULL DEFAULT 0,
  `maidens` INT NULL DEFAULT 0,
  `runs_conceded` INT NULL DEFAULT 0,
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

-- 8. IMPORT LOG
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

-- 9. ETL ERROR LOG
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