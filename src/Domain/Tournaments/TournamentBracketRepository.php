<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use NightCore\Core\TableNames;
use PDO;

final class TournamentBracketRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    public function updateTournamentPresentation(int $tournamentID, string $description, string $rulesText, int $actorID, int $now): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournaments') . ' SET description = :description, rulesText = :rulesText, updatedBy = :actorID, updatedAt = :updatedAt WHERE tournamentID = :tournamentID');
        $query->execute([':description' => $description, ':rulesText' => $rulesText, ':actorID' => $actorID, ':updatedAt' => $now, ':tournamentID' => $tournamentID]);
    }

    public function setBracketInfo(int $tournamentID, int $size, int $actorID, int $now): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournaments') . " SET format = 'single_elimination', bracketSize = :bracketSize, updatedBy = :actorID, updatedAt = :updatedAt WHERE tournamentID = :tournamentID");
        $query->execute([':bracketSize' => $size, ':actorID' => $actorID, ':updatedAt' => $now, ':tournamentID' => $tournamentID]);
    }

    public function matchCount(int $tournamentID): int
    {
        $query = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->tables->get('core_tournament_matches') . ' WHERE tournamentID = :tournamentID');
        $query->execute([':tournamentID' => $tournamentID]);
        return (int) $query->fetchColumn();
    }

    public function setMatchBracketMeta(int $matchID, int $roundNumber, int $roundSize, ?int $nextMatchID, ?int $nextSlot, int $now): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_matches') . ' SET roundNumber = :roundNumber, roundSize = :roundSize, nextMatchID = :nextMatchID, nextSlot = :nextSlot, updatedAt = :updatedAt WHERE matchID = :matchID');
        $query->bindValue(':roundNumber', $roundNumber, PDO::PARAM_INT);
        $query->bindValue(':roundSize', $roundSize, PDO::PARAM_INT);
        $query->bindValue(':nextMatchID', $nextMatchID, $nextMatchID === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':nextSlot', $nextSlot, $nextSlot === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':updatedAt', $now, PDO::PARAM_INT);
        $query->bindValue(':matchID', $matchID, PDO::PARAM_INT);
        $query->execute();
    }

    public function assignParticipant(int $matchID, int $slot, int $participantID, int $now): bool
    {
        $column = $slot === 1 ? 'participant1ID' : ($slot === 2 ? 'participant2ID' : '');
        if ($column === '') {
            return false;
        }
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_matches') . ' SET ' . $column . ' = :participantID, updatedAt = :updatedAt WHERE matchID = :matchID AND (' . $column . ' IS NULL OR ' . $column . ' = :sameParticipantID)');
        $query->execute([':participantID' => $participantID, ':sameParticipantID' => $participantID, ':updatedAt' => $now, ':matchID' => $matchID]);
        return $query->rowCount() > 0;
    }

    public function levelExists(int $levelID): bool
    {
        if ($levelID <= 0) {
            return false;
        }
        $query = $this->db->prepare('SELECT 1 FROM ' . $this->tables->get('levels') . ' WHERE levelID = :levelID LIMIT 1');
        $query->execute([':levelID' => $levelID]);
        return $query->fetchColumn() !== false;
    }

    public function setMatchEntries(int $matchID, MatchEntries $entries, int $now): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_matches') . ' SET entry1LevelID = :entry1, entry2LevelID = :entry2, updatedAt = :updatedAt WHERE matchID = :matchID');
        $query->bindValue(':entry1', $entries->participantOneLevelID(), $entries->participantOneLevelID() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':entry2', $entries->participantTwoLevelID(), $entries->participantTwoLevelID() === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':updatedAt', $now, PDO::PARAM_INT);
        $query->bindValue(':matchID', $matchID, PDO::PARAM_INT);
        $query->execute();
    }

    public function openMatch(int $matchID, int $opensAt, int $locksAt, int $now): bool
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_matches') . " SET status = 'open', opensAt = :opensAt, locksAt = :locksAt, updatedAt = :updatedAt WHERE matchID = :matchID AND status IN ('scheduled','open')");
        $query->execute([':opensAt' => $opensAt, ':locksAt' => $locksAt, ':updatedAt' => $now, ':matchID' => $matchID]);
        return $query->rowCount() > 0;
    }

    public function setMatchJudging(int $matchID, int $now): bool
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_matches') . " SET status = 'judging', updatedAt = :updatedAt WHERE matchID = :matchID AND status = 'open'");
        $query->execute([':updatedAt' => $now, ':matchID' => $matchID]);
        return $query->rowCount() > 0;
    }

    public function markParticipantEliminated(int $participantID): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_participants') . " SET status = 'eliminated' WHERE participantID = :participantID AND status = 'active'");
        $query->execute([':participantID' => $participantID]);
    }

    public function setPlacement(int $participantID, int $placement): void
    {
        $query = $this->db->prepare('UPDATE ' . $this->tables->get('core_tournament_participants') . ' SET placement = :placement WHERE participantID = :participantID');
        $query->execute([':placement' => $placement, ':participantID' => $participantID]);
    }

    /** @return array<string,mixed>|null */
    public function match(int $matchID): ?array
    {
        $query = $this->db->prepare('SELECT * FROM ' . $this->tables->get('core_tournament_matches') . ' WHERE matchID = :matchID LIMIT 1');
        $query->execute([':matchID' => $matchID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function participants(int $tournamentID): array
    {
        $query = $this->db->prepare('SELECT * FROM ' . $this->tables->get('core_tournament_participants') . ' WHERE tournamentID = :tournamentID ORDER BY CASE WHEN seed IS NULL THEN 1 ELSE 0 END, seed ASC, participantID ASC');
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function audit(int $actorID, int $tournamentID, int $matchID, string $action, array $details, int $now): void
    {
        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            $encoded = '{}';
        }
        $query = $this->db->prepare('INSERT INTO ' . $this->tables->get('core_tournament_audit') . ' (actorAccountID, tournamentID, matchID, action, detailsJson, createdAt) VALUES (:actorID, :tournamentID, :matchID, :action, :detailsJson, :createdAt)');
        $query->execute([':actorID' => max(0, $actorID), ':tournamentID' => max(0, $tournamentID), ':matchID' => max(0, $matchID), ':action' => substr($action, 0, 48), ':detailsJson' => $encoded, ':createdAt' => $now]);
    }
}
