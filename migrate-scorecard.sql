-- =============================================================================
-- Migration: scorecard detail (INNINGS + PERFORMANCE columns, MATCH_SQUAD,
-- FALL_OF_WICKETS).
--
-- Brings a database created from an earlier cricket-stats-schema.sql up to the
-- current one. A database built fresh from that file already has everything
-- here and does not need this.
--
-- ONE-SHOT. MySQL has no ADD COLUMN IF NOT EXISTS, so running it twice fails on
-- "Duplicate column name". That is noise, not damage: nothing below writes data.
--
-- The new columns are all nullable with a 0/NULL default, so existing rows stay
-- valid and readable. They stay EMPTY until the source files are re-imported -
-- none of this can be back-filled from what is already stored, because the
-- ball-by-ball detail only exists in the source JSON.
-- =============================================================================

USE `cricket_explorer`;

-- 1. INNINGS: legal balls + the extras breakdown ------------------------------
ALTER TABLE `INNINGS`
  ADD COLUMN `legal_balls`    INT NULL DEFAULT 0 AFTER `total_wickets`,
  ADD COLUMN `extras_byes`    INT NULL DEFAULT 0 AFTER `legal_balls`,
  ADD COLUMN `extras_legbyes` INT NULL DEFAULT 0 AFTER `extras_byes`,
  ADD COLUMN `extras_wides`   INT NULL DEFAULT 0 AFTER `extras_legbyes`,
  ADD COLUMN `extras_noballs` INT NULL DEFAULT 0 AFTER `extras_wides`,
  ADD COLUMN `extras_penalty` INT NULL DEFAULT 0 AFTER `extras_noballs`;

-- 2. PERFORMANCE: batting detail, dismissal, bowler extras --------------------
ALTER TABLE `PERFORMANCE`
  ADD COLUMN `batting_position`     INT NULL         AFTER `is_out`,
  ADD COLUMN `fours`                INT NULL DEFAULT 0 AFTER `batting_position`,
  ADD COLUMN `sixes`                INT NULL DEFAULT 0 AFTER `fours`,
  ADD COLUMN `dismissal_kind`       VARCHAR(50) NULL AFTER `sixes`,
  ADD COLUMN `dismissal_bowler_id`  VARCHAR(50) NULL AFTER `dismissal_kind`,
  ADD COLUMN `dismissal_fielder_id` VARCHAR(50) NULL AFTER `dismissal_bowler_id`,
  ADD COLUMN `wides_bowled`         INT NULL DEFAULT 0 AFTER `runs_conceded`,
  ADD COLUMN `noballs_bowled`       INT NULL DEFAULT 0 AFTER `wides_bowled`;

-- 3. MATCH_SQUAD --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `MATCH_SQUAD` (
  `squad_id` VARCHAR(100) NOT NULL,
  `match_id` VARCHAR(50) NOT NULL,
  `team_id` VARCHAR(50) NOT NULL,
  `player_id` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`squad_id`),
  UNIQUE KEY `uq_squad_player_in_match` (`match_id`, `player_id`),
  KEY `ix_squad_team` (`match_id`, `team_id`),
  KEY `ix_squad_player` (`player_id`)
  -- No foreign keys: the etl account is not granted REFERENCES, and this file
  -- has to be runnable by it. Same reasoning as ETL_ERROR_LOG.log_id in the
  -- schema. The ETL clears this table by match_id on a re-import, so nothing
  -- depends on a cascade.
) ENGINE = InnoDB;

-- 4. FALL_OF_WICKETS ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `FALL_OF_WICKETS` (
  `fow_id` VARCHAR(100) NOT NULL,
  `innings_id` VARCHAR(50) NOT NULL,
  `wicket_number` INT NOT NULL,
  `runs_at_fall` INT NOT NULL,
  `over_number` INT NULL,
  `ball_in_over` INT NULL,
  `player_out_id` VARCHAR(50) NULL,
  PRIMARY KEY (`fow_id`),
  UNIQUE KEY `uq_fow_in_innings` (`innings_id`, `wicket_number`)
  -- No foreign key, same reason as MATCH_SQUAD above.
) ENGINE = InnoDB;

-- 5. OPTIONAL: clear out non-players left by the old import --------------------
-- Earlier runs inserted every person in the source registry into PLAYERS, which
-- includes the match officials. They are identifiable now: no squad membership
-- and no performance row anywhere. Re-import first, so the squads exist, then
-- run this if you want them gone. Safe as written - anyone with a performance
-- row is excluded, so the ON DELETE CASCADE has nothing to take with it.
--
-- DELETE FROM PLAYERS
-- WHERE player_id NOT IN (SELECT player_id FROM MATCH_SQUAD)
--   AND player_id NOT IN (SELECT player_id FROM PERFORMANCE);
