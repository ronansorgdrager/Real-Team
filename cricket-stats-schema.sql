SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

CREATE SCHEMA IF NOT EXISTS `cricket_explorer` DEFAULT CHARACTER SET utf8mb4;
USE `cricket_explorer`;

-- 1. COMPETITIONS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`COMPETITIONS` (
  `comp_id` VARCHAR(50) NOT NULL,
  `comp_name` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`comp_id`)
) ENGINE = InnoDB;

-- 2. SEASONS
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`SEASONS` (
  `season_id` VARCHAR(50) NOT NULL,
  `comp_id` VARCHAR(50) NOT NULL,
  `season_name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`season_id`),
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
  PRIMARY KEY (`team_id`)
) ENGINE = InnoDB;

-- 4. MATCHES
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
  CONSTRAINT `fk_innings_match`
    FOREIGN KEY (`match_id`)
    REFERENCES `cricket_explorer`.`MATCHES` (`match_id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB;

-- 7. PERFORMANCE (Fact Table)
CREATE TABLE IF NOT EXISTS `cricket_explorer`.`PERFORMANCE` (
  `performance_id` VARCHAR(100) NOT NULL,
  `player_id` VARCHAR(50) NOT NULL,
  `innings_id` VARCHAR(50) NOT NULL,
  `team_id` VARCHAR(50) NOT NULL,
  `runs_scored` INT NULL DEFAULT 0,
  `balls_faced` INT NULL DEFAULT 0,
  `wickets_taken` INT NULL DEFAULT 0,
  `overs_bowled` DECIMAL(4,1) NULL DEFAULT 0.0,
  `catches` INT NULL DEFAULT 0,
  `stumpings` INT NULL DEFAULT 0,
  PRIMARY KEY (`performance_id`),
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
  PRIMARY KEY (`log_id`)
) ENGINE = InnoDB;

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;