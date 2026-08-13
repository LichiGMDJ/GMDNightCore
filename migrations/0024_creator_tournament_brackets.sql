ALTER TABLE `{{prefix}}core_tournaments`
    ADD COLUMN `description` TEXT NOT NULL DEFAULT '' AFTER `name`,
    ADD COLUMN `rulesText` TEXT NOT NULL DEFAULT '' AFTER `description`,
    ADD COLUMN `format` VARCHAR(32) NOT NULL DEFAULT 'single_elimination' AFTER `status`,
    ADD COLUMN `bracketSize` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `format`;

ALTER TABLE `{{prefix}}core_tournament_matches`
    ADD COLUMN `roundNumber` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `stage`,
    ADD COLUMN `roundSize` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `roundNumber`,
    ADD COLUMN `nextMatchID` BIGINT UNSIGNED NULL AFTER `winnerParticipantID`,
    ADD COLUMN `nextSlot` TINYINT UNSIGNED NULL AFTER `nextMatchID`,
    ADD KEY `idx_tournament_round_order` (`tournamentID`, `roundNumber`, `matchOrder`),
    ADD KEY `idx_tournament_next_match` (`nextMatchID`);

CREATE TABLE IF NOT EXISTS `{{prefix}}core_tournament_audit` (
    `auditID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actorAccountID` INT UNSIGNED NOT NULL DEFAULT 0,
    `tournamentID` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `matchID` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `action` VARCHAR(48) NOT NULL,
    `detailsJson` TEXT NOT NULL,
    `createdAt` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`auditID`),
    KEY `idx_tournament_audit_tournament` (`tournamentID`, `createdAt`),
    KEY `idx_tournament_audit_actor` (`actorAccountID`, `createdAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
