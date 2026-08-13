<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use NightCore\Core\TableNames;
use PDO;
use Throwable;

final class TournamentRepository
{
    public function __construct(private PDO $db, private TableNames $tables)
    {
    }

    /** @param array<string,mixed> $row */
    public function createTournament(array $row): int
    {
        $sql = 'INSERT INTO ' . $this->tables->get('core_tournaments')
            . ' (slug, name, status, predictionOpensAt, predictionLocksAt, startsAt, endsAt,'
            . ' matchPickPoints, championPickPoints, matchPickRewardJson, championPickRewardJson,'
            . ' createdBy, createdAt, updatedBy, updatedAt)'
            . ' VALUES (:slug, :name, :status, :predictionOpensAt, :predictionLocksAt, :startsAt, :endsAt,'
            . ' :matchPickPoints, :championPickPoints, :matchPickRewardJson, :championPickRewardJson,'
            . ' :createdBy, :createdAt, :updatedBy, :updatedAt)';
        $query = $this->db->prepare($sql);
        $query->execute([
            ':slug' => $row['slug'],
            ':name' => $row['name'],
            ':status' => $row['status'],
            ':predictionOpensAt' => $row['predictionOpensAt'],
            ':predictionLocksAt' => $row['predictionLocksAt'],
            ':startsAt' => $row['startsAt'],
            ':endsAt' => $row['endsAt'],
            ':matchPickPoints' => $row['matchPickPoints'],
            ':championPickPoints' => $row['championPickPoints'],
            ':matchPickRewardJson' => $row['matchPickRewardJson'],
            ':championPickRewardJson' => $row['championPickRewardJson'],
            ':createdBy' => $row['createdBy'],
            ':createdAt' => $row['createdAt'],
            ':updatedBy' => $row['updatedBy'],
            ':updatedAt' => $row['updatedAt'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function tournament(int $tournamentID): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournaments') . ' WHERE tournamentID = :tournamentID LIMIT 1'
        );
        $query->execute([':tournamentID' => $tournamentID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function tournamentBySlug(string $slug): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournaments') . ' WHERE slug = :slug LIMIT 1'
        );
        $query->execute([':slug' => $slug]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function tournaments(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $query = $this->db->query(
            'SELECT * FROM ' . $this->tables->get('core_tournaments')
            . ' ORDER BY tournamentID DESC LIMIT ' . $limit
        );
        return $query === false ? [] : $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setTournamentStatus(int $tournamentID, string $status, int $actorID, int $now): bool
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournaments')
            . ' SET status = :status, updatedBy = :updatedBy, updatedAt = :updatedAt'
            . ' WHERE tournamentID = :tournamentID'
        );
        $query->execute([
            ':status' => $status,
            ':updatedBy' => $actorID,
            ':updatedAt' => $now,
            ':tournamentID' => $tournamentID,
        ]);
        return $query->rowCount() > 0;
    }

    public function completeTournament(int $tournamentID, int $winnerParticipantID, int $actorID, int $now): bool
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournaments')
            . " SET status = 'completed', winnerParticipantID = :winnerParticipantID, endsAt = CASE WHEN endsAt = 0 THEN :endsAt ELSE endsAt END,"
            . ' updatedBy = :updatedBy, updatedAt = :updatedAt WHERE tournamentID = :tournamentID'
        );
        $query->execute([
            ':winnerParticipantID' => $winnerParticipantID,
            ':endsAt' => $now,
            ':updatedBy' => $actorID,
            ':updatedAt' => $now,
            ':tournamentID' => $tournamentID,
        ]);
        return $query->rowCount() > 0;
    }

    public function addParticipant(
        int $tournamentID,
        int $accountID,
        string $displayName,
        ?int $seed,
        int $now
    ): int {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_tournament_participants')
            . ' (tournamentID, accountID, displayName, seed, status, createdAt)'
            . " VALUES (:tournamentID, :accountID, :displayName, :seed, 'active', :createdAt)"
        );
        $query->bindValue(':tournamentID', $tournamentID, PDO::PARAM_INT);
        $query->bindValue(':accountID', $accountID, PDO::PARAM_INT);
        $query->bindValue(':displayName', $displayName, PDO::PARAM_STR);
        $query->bindValue(':seed', $seed, $seed === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':createdAt', $now, PDO::PARAM_INT);
        $query->execute();
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function participant(int $participantID): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournament_participants')
            . ' WHERE participantID = :participantID LIMIT 1'
        );
        $query->execute([':participantID' => $participantID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function participants(int $tournamentID): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournament_participants')
            . ' WHERE tournamentID = :tournamentID'
            . ' ORDER BY CASE WHEN seed IS NULL THEN 1 ELSE 0 END, seed ASC, participantID ASC'
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markChampion(int $tournamentID, int $winnerParticipantID): void
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournament_participants')
            . " SET status = CASE WHEN participantID = :winnerParticipantID THEN 'champion'"
            . " WHEN status = 'active' THEN 'eliminated' ELSE status END"
            . ' WHERE tournamentID = :tournamentID'
        );
        $query->execute([
            ':winnerParticipantID' => $winnerParticipantID,
            ':tournamentID' => $tournamentID,
        ]);
    }

    public function createMatch(
        int $tournamentID,
        string $stage,
        int $matchOrder,
        ?int $participant1ID,
        ?int $participant2ID,
        ?int $entry1LevelID,
        ?int $entry2LevelID,
        int $opensAt,
        int $locksAt,
        int $now
    ): int {
        $query = $this->db->prepare(
            'INSERT INTO ' . $this->tables->get('core_tournament_matches')
            . ' (tournamentID, stage, matchOrder, participant1ID, participant2ID, entry1LevelID, entry2LevelID,'
            . ' status, opensAt, locksAt, createdAt, updatedAt)'
            . " VALUES (:tournamentID, :stage, :matchOrder, :participant1ID, :participant2ID, :entry1LevelID, :entry2LevelID,"
            . " 'scheduled', :opensAt, :locksAt, :createdAt, :updatedAt)"
        );
        $query->bindValue(':tournamentID', $tournamentID, PDO::PARAM_INT);
        $query->bindValue(':stage', $stage, PDO::PARAM_STR);
        $query->bindValue(':matchOrder', $matchOrder, PDO::PARAM_INT);
        $query->bindValue(':participant1ID', $participant1ID, $participant1ID === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':participant2ID', $participant2ID, $participant2ID === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':entry1LevelID', $entry1LevelID, $entry1LevelID === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':entry2LevelID', $entry2LevelID, $entry2LevelID === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $query->bindValue(':opensAt', $opensAt, PDO::PARAM_INT);
        $query->bindValue(':locksAt', $locksAt, PDO::PARAM_INT);
        $query->bindValue(':createdAt', $now, PDO::PARAM_INT);
        $query->bindValue(':updatedAt', $now, PDO::PARAM_INT);
        $query->execute();
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function match(int $matchID): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournament_matches') . ' WHERE matchID = :matchID LIMIT 1'
        );
        $query->execute([':matchID' => $matchID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function matches(int $tournamentID): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournament_matches')
            . ' WHERE tournamentID = :tournamentID ORDER BY matchID ASC'
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setMatchStatus(int $matchID, string $status, int $now): bool
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournament_matches')
            . ' SET status = :status, updatedAt = :updatedAt WHERE matchID = :matchID'
        );
        $query->execute([':status' => $status, ':updatedAt' => $now, ':matchID' => $matchID]);
        return $query->rowCount() > 0;
    }

    public function completeMatch(int $matchID, int $winnerParticipantID, int $now): bool
    {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournament_matches')
            . " SET status = 'completed', winnerParticipantID = :winnerParticipantID, completedAt = :completedAt, updatedAt = :updatedAt"
            . ' WHERE matchID = :matchID'
        );
        $query->execute([
            ':winnerParticipantID' => $winnerParticipantID,
            ':completedAt' => $now,
            ':updatedAt' => $now,
            ':matchID' => $matchID,
        ]);
        return $query->rowCount() > 0;
    }

    public function savePrediction(
        int $tournamentID,
        int $accountID,
        string $kind,
        int $matchID,
        int $participantID,
        int $now
    ): int {
        $table = $this->tables->get('core_tournament_predictions');
        $query = $this->db->prepare(
            'INSERT INTO ' . $table
            . ' (tournamentID, accountID, kind, matchID, participantID, submittedAt, updatedAt, result, pointsAwarded, rewardJson)'
            . " VALUES (:tournamentID, :accountID, :kind, :matchID, :participantID, :submittedAt, :updatedAt, 'pending', 0, '{}')"
            . ' ON DUPLICATE KEY UPDATE participantID = VALUES(participantID), updatedAt = VALUES(updatedAt)'
        );
        $query->execute([
            ':tournamentID' => $tournamentID,
            ':accountID' => $accountID,
            ':kind' => $kind,
            ':matchID' => $matchID,
            ':participantID' => $participantID,
            ':submittedAt' => $now,
            ':updatedAt' => $now,
        ]);

        $lookup = $this->db->prepare(
            'SELECT predictionID FROM ' . $table
            . ' WHERE tournamentID = :tournamentID AND accountID = :accountID AND kind = :kind AND matchID = :matchID LIMIT 1'
        );
        $lookup->execute([
            ':tournamentID' => $tournamentID,
            ':accountID' => $accountID,
            ':kind' => $kind,
            ':matchID' => $matchID,
        ]);
        return (int) $lookup->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function prediction(int $predictionID): ?array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournament_predictions')
            . ' WHERE predictionID = :predictionID LIMIT 1'
        );
        $query->execute([':predictionID' => $predictionID]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function predictionsForAccount(int $tournamentID, int $accountID): array
    {
        $query = $this->db->prepare(
            'SELECT * FROM ' . $this->tables->get('core_tournament_predictions')
            . ' WHERE tournamentID = :tournamentID AND accountID = :accountID ORDER BY predictionID ASC'
        );
        $query->execute([':tournamentID' => $tournamentID, ':accountID' => $accountID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolveMatchPredictions(
        int $matchID,
        int $winnerParticipantID,
        int $points,
        string $rewardJson,
        int $now
    ): int {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournament_predictions')
            . " SET result = CASE WHEN participantID = :winnerResult THEN 'correct' ELSE 'wrong' END,"
            . ' pointsAwarded = CASE WHEN participantID = :winnerPoints THEN :points ELSE 0 END,'
            . " rewardJson = CASE WHEN participantID = :winnerReward THEN :rewardJson ELSE '{}' END,"
            . ' resolvedAt = :resolvedAt, updatedAt = :updatedAt'
            . " WHERE kind = 'match' AND matchID = :matchID AND result = 'pending'"
        );
        $query->execute([
            ':winnerResult' => $winnerParticipantID,
            ':winnerPoints' => $winnerParticipantID,
            ':winnerReward' => $winnerParticipantID,
            ':points' => max(0, $points),
            ':rewardJson' => $rewardJson,
            ':resolvedAt' => $now,
            ':updatedAt' => $now,
            ':matchID' => $matchID,
        ]);
        return $query->rowCount();
    }

    public function resolveChampionPredictions(
        int $tournamentID,
        int $winnerParticipantID,
        int $points,
        string $rewardJson,
        int $now
    ): int {
        $query = $this->db->prepare(
            'UPDATE ' . $this->tables->get('core_tournament_predictions')
            . " SET result = CASE WHEN participantID = :winnerResult THEN 'correct' ELSE 'wrong' END,"
            . ' pointsAwarded = CASE WHEN participantID = :winnerPoints THEN :points ELSE 0 END,'
            . " rewardJson = CASE WHEN participantID = :winnerReward THEN :rewardJson ELSE '{}' END,"
            . ' resolvedAt = :resolvedAt, updatedAt = :updatedAt'
            . " WHERE tournamentID = :tournamentID AND kind = 'champion' AND matchID = 0 AND result = 'pending'"
        );
        $query->execute([
            ':winnerResult' => $winnerParticipantID,
            ':winnerPoints' => $winnerParticipantID,
            ':winnerReward' => $winnerParticipantID,
            ':points' => max(0, $points),
            ':rewardJson' => $rewardJson,
            ':resolvedAt' => $now,
            ':updatedAt' => $now,
            ':tournamentID' => $tournamentID,
        ]);
        return $query->rowCount();
    }

    /** @return array<string,mixed>|null */
    public function claimReward(int $accountID, int $predictionID, int $now): ?array
    {
        return $this->transaction(function () use ($accountID, $predictionID, $now): ?array {
            $table = $this->tables->get('core_tournament_predictions');
            $query = $this->db->prepare(
                'SELECT * FROM ' . $table
                . ' WHERE predictionID = :predictionID AND accountID = :accountID FOR UPDATE'
            );
            $query->execute([':predictionID' => $predictionID, ':accountID' => $accountID]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string) $row['result'] !== 'correct' || $row['claimedAt'] !== null) {
                return null;
            }

            $update = $this->db->prepare(
                'UPDATE ' . $table . ' SET claimedAt = :claimedAt, updatedAt = :updatedAt'
                . ' WHERE predictionID = :predictionID AND claimedAt IS NULL'
            );
            $update->execute([
                ':claimedAt' => $now,
                ':updatedAt' => $now,
                ':predictionID' => $predictionID,
            ]);
            if ($update->rowCount() !== 1) {
                return null;
            }
            $row['claimedAt'] = $now;
            return $row;
        });
    }

    /** @return list<array<string,mixed>> */
    public function leaderboard(int $tournamentID, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->db->prepare(
            'SELECT accountID, SUM(pointsAwarded) AS points,'
            . " SUM(CASE WHEN result = 'correct' THEN 1 ELSE 0 END) AS correctPicks,"
            . " SUM(CASE WHEN result = 'wrong' THEN 1 ELSE 0 END) AS wrongPicks,"
            . " MAX(CASE WHEN kind = 'champion' AND result = 'correct' THEN 1 ELSE 0 END) AS championCorrect"
            . ' FROM ' . $this->tables->get('core_tournament_predictions')
            . ' WHERE tournamentID = :tournamentID GROUP BY accountID'
            . ' ORDER BY points DESC, correctPicks DESC, accountID ASC LIMIT ' . $limit
        );
        $query->execute([':tournamentID' => $tournamentID]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) {
            return $operation();
        }

        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
