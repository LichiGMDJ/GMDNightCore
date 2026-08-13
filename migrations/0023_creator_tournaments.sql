CREATE TABLE IF NOT EXISTS `{{prefix}}core_tournaments` (
    `tournamentID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(64) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `status` ENUM('draft','open','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    `predictionOpensAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `predictionLocksAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `startsAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `endsAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `winnerParticipantID` BIGINT UNSIGNED NULL,
    `matchPickPoints` INT UNSIGNED NOT NULL DEFAULT 1,
    `championPickPoints` INT UNSIGNED NOT NULL DEFAULT 5,
    `matchPickRewardJson` TEXT NOT NULL,
    `championPickRewardJson` TEXT NOT NULL,
    `createdBy` INT UNSIGNED NOT NULL DEFAULT 0,
    `createdAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedBy` INT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`tournamentID`),
    UNIQUE KEY `uniq_tournament_slug` (`slug`),
    KEY `idx_tournaments_status_window` (`status`, `startsAt`, `endsAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_tournament_participants` (
    `participantID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournamentID` BIGINT UNSIGNED NOT NULL,
    `accountID` INT UNSIGNED NOT NULL,
    `displayName` VARCHAR(32) NOT NULL,
    `seed` SMALLINT UNSIGNED NULL,
    `status` ENUM('active','eliminated','withdrawn','champion') NOT NULL DEFAULT 'active',
    `placement` SMALLINT UNSIGNED NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`participantID`),
    UNIQUE KEY `uniq_tournament_account` (`tournamentID`, `accountID`),
    UNIQUE KEY `uniq_tournament_seed` (`tournamentID`, `seed`),
    KEY `idx_tournament_participants_status` (`tournamentID`, `status`),
    KEY `idx_tournament_participants_account` (`accountID`, `tournamentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_tournament_matches` (
    `matchID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournamentID` BIGINT UNSIGNED NOT NULL,
    `stage` VARCHAR(32) NOT NULL,
    `matchOrder` SMALLINT UNSIGNED NOT NULL,
    `participant1ID` BIGINT UNSIGNED NULL,
    `participant2ID` BIGINT UNSIGNED NULL,
    `entry1LevelID` INT UNSIGNED NULL,
    `entry2LevelID` INT UNSIGNED NULL,
    `status` ENUM('scheduled','open','judging','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `winnerParticipantID` BIGINT UNSIGNED NULL,
    `opensAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `locksAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `completedAt` BIGINT UNSIGNED NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`matchID`),
    UNIQUE KEY `uniq_tournament_stage_order` (`tournamentID`, `stage`, `matchOrder`),
    KEY `idx_tournament_matches_status` (`tournamentID`, `status`, `opensAt`, `locksAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{prefix}}core_tournament_predictions` (
    `predictionID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tournamentID` BIGINT UNSIGNED NOT NULL,
    `accountID` INT UNSIGNED NOT NULL,
    `kind` ENUM('champion','match') NOT NULL,
    `matchID` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `participantID` BIGINT UNSIGNED NOT NULL,
    `submittedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updatedAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `resolvedAt` BIGINT UNSIGNED NULL,
    `result` ENUM('pending','correct','wrong','void') NOT NULL DEFAULT 'pending',
    `pointsAwarded` INT UNSIGNED NOT NULL DEFAULT 0,
    `rewardJson` TEXT NOT NULL,
    `claimedAt` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`predictionID`),
    UNIQUE KEY `uniq_tournament_prediction` (`tournamentID`, `accountID`, `kind`, `matchID`),
    KEY `idx_tournament_predictions_account` (`accountID`, `tournamentID`),
    KEY `idx_tournament_predictions_match` (`matchID`, `result`),
    KEY `idx_tournament_predictions_leaderboard` (`tournamentID`, `pointsAwarded`, `result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{{prefix}}core_staff_permissions` (`permissionKey`, `description`) VALUES
('tournaments.manage', 'Create and manage creator tournaments and brackets'),
('tournaments.resolve', 'Resolve tournament matches and tournament winners');
