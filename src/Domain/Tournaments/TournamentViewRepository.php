<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use NightCore\Core\TableNames;
use PDO;

final class TournamentViewRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    /** @return list<array<string,mixed>> */
    public function tournaments(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $query = $this->db->query(
            'SELECT t.*,'
            . ' (SELECT COUNT(*) FROM ' . $this->tables->get('core_tournament_participants') . ' p WHERE p.tournamentID = t.tournamentID) participantCount,'
            . ' (SELECT COUNT(*) FROM ' . $this->tables->get('core_tournament_matches') . ' m WHERE m.tournamentID = t.tournamentID) matchCount'
            . ' FROM ' . $this->tables->get('core_tournaments') . ' t'
            . " ORDER BY CASE t.status WHEN 'active' THEN 0 WHEN 'open' THEN 1 WHEN 'draft' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END,"
            . ' t.startsAt DESC, t.tournamentID DESC LIMIT ' . $limit
        );
        return $query === false ? [] : $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function tournament(int $tournamentID): ?array
    {
        $query = $this->db->prepare(
            'SELECT t.*, winner.displayName winnerName FROM ' . $this->tables->get('core_tournaments') . ' t'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_participants') . ' winner ON winner.participantID = t.winnerParticipantID'
            . ' WHERE t.tournamentID = :tournamentID LIMIT 1'
        );
        $query->execute([':tournamentID' => $tournamentID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function participants(int $tournamentID): array
    {
        $query = $this->db->prepare(
            'SELECT p.*, a.userName accountName FROM ' . $this->tables->get('core_tournament_participants') . ' p'
            . ' LEFT JOIN ' . $this->tables->get('accounts') . ' a ON a.accountID = p.accountID'
            . ' WHERE p.tournamentID = :tournamentID ORDER BY CASE WHEN p.seed IS NULL THEN 1 ELSE 0 END, p.seed ASC, p.participantID ASC'
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function matches(int $tournamentID): array
    {
        $query = $this->db->prepare(
            'SELECT m.*, p1.displayName participant1Name, p2.displayName participant2Name, w.displayName winnerName,'
            . ' l1.levelName entry1LevelName, l2.levelName entry2LevelName'
            . ' FROM ' . $this->tables->get('core_tournament_matches') . ' m'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_participants') . ' p1 ON p1.participantID = m.participant1ID'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_participants') . ' p2 ON p2.participantID = m.participant2ID'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_participants') . ' w ON w.participantID = m.winnerParticipantID'
            . ' LEFT JOIN ' . $this->tables->get('levels') . ' l1 ON l1.levelID = m.entry1LevelID'
            . ' LEFT JOIN ' . $this->tables->get('levels') . ' l2 ON l2.levelID = m.entry2LevelID'
            . ' WHERE m.tournamentID = :tournamentID ORDER BY m.roundNumber ASC, m.matchOrder ASC, m.matchID ASC'
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function leaderboard(int $tournamentID, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $query = $this->db->prepare(
            'SELECT p.accountID, a.userName, SUM(p.pointsAwarded) points,'
            . " SUM(CASE WHEN p.result = 'correct' THEN 1 ELSE 0 END) correctPicks,"
            . " SUM(CASE WHEN p.result = 'wrong' THEN 1 ELSE 0 END) wrongPicks,"
            . " SUM(CASE WHEN p.kind = 'champion' AND p.result = 'correct' THEN 1 ELSE 0 END) championCorrect"
            . ' FROM ' . $this->tables->get('core_tournament_predictions') . ' p'
            . ' LEFT JOIN ' . $this->tables->get('accounts') . ' a ON a.accountID = p.accountID'
            . ' WHERE p.tournamentID = :tournamentID GROUP BY p.accountID, a.userName'
            . ' ORDER BY points DESC, correctPicks DESC, p.accountID ASC LIMIT ' . $limit
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function predictionsForAccount(int $tournamentID, int $accountID): array
    {
        $query = $this->db->prepare(
            'SELECT pr.*, chosen.displayName participantName, m.stage, m.matchOrder, m.roundNumber'
            . ' FROM ' . $this->tables->get('core_tournament_predictions') . ' pr'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_participants') . ' chosen ON chosen.participantID = pr.participantID'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_matches') . ' m ON m.matchID = pr.matchID'
            . ' WHERE pr.tournamentID = :tournamentID AND pr.accountID = :accountID'
            . " ORDER BY CASE pr.kind WHEN 'champion' THEN 0 ELSE 1 END, m.roundNumber ASC, m.matchOrder ASC, pr.predictionID ASC"
        );
        $query->execute([':tournamentID' => $tournamentID, ':accountID' => $accountID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function unclaimedRewards(int $tournamentID, int $accountID): array
    {
        $query = $this->db->prepare(
            'SELECT pr.*, chosen.displayName participantName FROM ' . $this->tables->get('core_tournament_predictions') . ' pr'
            . ' LEFT JOIN ' . $this->tables->get('core_tournament_participants') . ' chosen ON chosen.participantID = pr.participantID'
            . " WHERE pr.tournamentID = :tournamentID AND pr.accountID = :accountID AND pr.result = 'correct'"
            . " AND pr.claimedAt IS NULL AND pr.rewardJson <> '{}' ORDER BY pr.predictionID ASC"
        );
        $query->execute([':tournamentID' => $tournamentID, ':accountID' => $accountID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function audit(int $tournamentID, int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $query = $this->db->prepare(
            'SELECT au.*, a.userName actorName FROM ' . $this->tables->get('core_tournament_audit') . ' au'
            . ' LEFT JOIN ' . $this->tables->get('accounts') . ' a ON a.accountID = au.actorAccountID'
            . ' WHERE au.tournamentID = :tournamentID ORDER BY au.auditID DESC LIMIT ' . $limit
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
