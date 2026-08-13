<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;
use NightCore\Domain\Moderation\StaffAccessService;
use RuntimeException;

final class TournamentService
{
    public function __construct(
        private TournamentRepository $repository,
        private TournamentFeaturePolicy $features,
        private StaffAccessService $staff
    ) {
    }

    public function createTournament(
        int $actorID,
        string $slug,
        string $name,
        int $predictionOpensAt,
        int $predictionLocksAt,
        int $startsAt,
        int $endsAt = 0,
        int $matchPickPoints = 1,
        int $championPickPoints = 5,
        array $matchPickReward = [],
        array $championPickReward = [],
        ?int $now = null
    ): int {
        $this->features->requireTournamentWrite();
        $this->requirePermission($actorID, 'tournaments.manage');
        $now ??= time();

        $slug = strtolower(trim($slug));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug) !== 1) {
            throw new InvalidArgumentException('Tournament slug must contain only lowercase letters, numbers and hyphens.');
        }
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Tournament name must contain 1-120 bytes.');
        }
        $this->validateWindow($predictionOpensAt, $predictionLocksAt, 'prediction window');
        if ($startsAt > 0 && $predictionLocksAt > 0 && $startsAt < $predictionLocksAt) {
            throw new InvalidArgumentException('Tournament cannot start before champion predictions lock.');
        }
        if ($startsAt > 0 && $endsAt > 0 && $endsAt <= $startsAt) {
            throw new InvalidArgumentException('Tournament end must be after start.');
        }
        if ($this->repository->tournamentBySlug($slug) !== null) {
            throw new InvalidArgumentException('Tournament slug already exists.');
        }

        return $this->repository->createTournament([
            'slug' => $slug,
            'name' => $name,
            'status' => 'draft',
            'predictionOpensAt' => max(0, $predictionOpensAt),
            'predictionLocksAt' => max(0, $predictionLocksAt),
            'startsAt' => max(0, $startsAt),
            'endsAt' => max(0, $endsAt),
            'matchPickPoints' => max(0, $matchPickPoints),
            'championPickPoints' => max(0, $championPickPoints),
            'matchPickRewardJson' => $this->encodeReward($matchPickReward),
            'championPickRewardJson' => $this->encodeReward($championPickReward),
            'createdBy' => $actorID,
            'createdAt' => $now,
            'updatedBy' => $actorID,
            'updatedAt' => $now,
        ]);
    }

    public function addParticipant(
        int $actorID,
        int $tournamentID,
        int $accountID,
        string $displayName,
        ?int $seed = null,
        ?int $now = null
    ): int {
        $this->features->requireTournamentWrite();
        $this->requirePermission($actorID, 'tournaments.manage');
        $tournament = $this->requireTournament($tournamentID);
        if (!in_array((string) $tournament['status'], ['draft', 'open'], true)) {
            throw new RuntimeException('Participants can only be added before the tournament becomes active.');
        }
        if ($accountID <= 0) {
            throw new InvalidArgumentException('Participant account ID must be positive.');
        }
        $displayName = trim($displayName);
        if ($displayName === '' || strlen($displayName) > 32) {
            throw new InvalidArgumentException('Participant display name must contain 1-32 bytes.');
        }
        if ($seed !== null && ($seed <= 0 || $seed > 65535)) {
            throw new InvalidArgumentException('Seed must be between 1 and 65535.');
        }
        return $this->repository->addParticipant($tournamentID, $accountID, $displayName, $seed, $now ?? time());
    }

    public function createMatch(
        int $actorID,
        int $tournamentID,
        string $stage,
        int $matchOrder,
        ?int $participant1ID,
        ?int $participant2ID,
        ?int $entry1LevelID,
        ?int $entry2LevelID,
        int $opensAt,
        int $locksAt,
        ?int $now = null
    ): int {
        $this->features->requireTournamentWrite();
        $this->requirePermission($actorID, 'tournaments.manage');
        $this->requireTournament($tournamentID);

        $stage = trim($stage);
        if ($stage === '' || strlen($stage) > 32 || preg_match('/^[A-Za-z0-9_-]+$/', $stage) !== 1) {
            throw new InvalidArgumentException('Match stage must contain only letters, numbers, underscore or hyphen.');
        }
        if ($matchOrder <= 0 || $matchOrder > 65535) {
            throw new InvalidArgumentException('Match order must be between 1 and 65535.');
        }
        $this->validateWindow($opensAt, $locksAt, 'match prediction window');
        foreach ([$participant1ID, $participant2ID] as $participantID) {
            if ($participantID !== null) {
                $participant = $this->requireParticipant($participantID, $tournamentID);
                if ((string) $participant['status'] === 'withdrawn') {
                    throw new InvalidArgumentException('Withdrawn participant cannot be placed into a match.');
                }
            }
        }
        if ($participant1ID !== null && $participant2ID !== null && $participant1ID === $participant2ID) {
            throw new InvalidArgumentException('A match must contain two different participants.');
        }

        return $this->repository->createMatch(
            $tournamentID,
            $stage,
            $matchOrder,
            $participant1ID,
            $participant2ID,
            $this->positiveOrNull($entry1LevelID),
            $this->positiveOrNull($entry2LevelID),
            max(0, $opensAt),
            max(0, $locksAt),
            $now ?? time()
        );
    }

    public function setTournamentStatus(int $actorID, int $tournamentID, string $status, ?int $now = null): bool
    {
        $this->features->requireTournamentWrite();
        $this->requirePermission($actorID, 'tournaments.manage');
        $this->requireTournament($tournamentID);
        if (!in_array($status, ['draft', 'open', 'active', 'cancelled'], true)) {
            throw new InvalidArgumentException('Unsupported tournament status.');
        }
        return $this->repository->setTournamentStatus($tournamentID, $status, $actorID, $now ?? time());
    }

    public function setMatchStatus(int $actorID, int $matchID, string $status, ?int $now = null): bool
    {
        $this->features->requireTournamentWrite();
        $this->requirePermission($actorID, 'tournaments.manage');
        $this->requireMatch($matchID);
        if (!in_array($status, ['scheduled', 'open', 'judging', 'cancelled'], true)) {
            throw new InvalidArgumentException('Unsupported match status.');
        }
        return $this->repository->setMatchStatus($matchID, $status, $now ?? time());
    }

    public function submitChampionPrediction(
        int $accountID,
        int $tournamentID,
        int $participantID,
        ?int $now = null
    ): int {
        $this->features->requirePredictionWrite();
        if ($accountID <= 0) {
            throw new InvalidArgumentException('Account ID must be positive.');
        }
        $now ??= time();
        $tournament = $this->requireTournament($tournamentID);
        if (!in_array((string) $tournament['status'], ['open', 'active'], true)) {
            throw new RuntimeException('Champion predictions are not open for this tournament.');
        }
        $this->requireParticipant($participantID, $tournamentID);
        $this->requireOpenWindow(
            (int) $tournament['predictionOpensAt'],
            (int) $tournament['predictionLocksAt'],
            $now,
            'Champion prediction'
        );
        return $this->repository->savePrediction($tournamentID, $accountID, 'champion', 0, $participantID, $now);
    }

    public function submitMatchPrediction(
        int $accountID,
        int $matchID,
        int $participantID,
        ?int $now = null
    ): int {
        $this->features->requirePredictionWrite();
        if ($accountID <= 0) {
            throw new InvalidArgumentException('Account ID must be positive.');
        }
        $now ??= time();
        $match = $this->requireMatch($matchID);
        if ((string) $match['status'] !== 'open') {
            throw new RuntimeException('Match predictions are not open.');
        }
        if (!in_array($participantID, [(int) $match['participant1ID'], (int) $match['participant2ID']], true)) {
            throw new InvalidArgumentException('Prediction participant is not in this match.');
        }
        $this->requireOpenWindow((int) $match['opensAt'], (int) $match['locksAt'], $now, 'Match prediction');
        return $this->repository->savePrediction(
            (int) $match['tournamentID'],
            $accountID,
            'match',
            $matchID,
            $participantID,
            $now
        );
    }

    public function resolveMatch(int $actorID, int $matchID, int $winnerParticipantID, ?int $now = null): int
    {
        $this->features->requireTournamentWrite();
        $this->requireResolvePermission($actorID);
        $now ??= time();
        $match = $this->requireMatch($matchID);
        if ((string) $match['status'] === 'completed') {
            throw new RuntimeException('Match is already completed.');
        }
        if (!in_array($winnerParticipantID, [(int) $match['participant1ID'], (int) $match['participant2ID']], true)) {
            throw new InvalidArgumentException('Winner must be one of the match participants.');
        }
        $tournament = $this->requireTournament((int) $match['tournamentID']);

        return $this->repository->transaction(function () use ($matchID, $winnerParticipantID, $tournament, $now): int {
            $this->repository->completeMatch($matchID, $winnerParticipantID, $now);
            return $this->repository->resolveMatchPredictions(
                $matchID,
                $winnerParticipantID,
                (int) $tournament['matchPickPoints'],
                (string) $tournament['matchPickRewardJson'],
                $now
            );
        });
    }

    public function completeTournament(
        int $actorID,
        int $tournamentID,
        int $winnerParticipantID,
        ?int $now = null
    ): int {
        $this->features->requireTournamentWrite();
        $this->requireResolvePermission($actorID);
        $now ??= time();
        $tournament = $this->requireTournament($tournamentID);
        if ((string) $tournament['status'] === 'completed') {
            throw new RuntimeException('Tournament is already completed.');
        }
        $this->requireParticipant($winnerParticipantID, $tournamentID);

        return $this->repository->transaction(function () use ($actorID, $tournamentID, $winnerParticipantID, $tournament, $now): int {
            $this->repository->completeTournament($tournamentID, $winnerParticipantID, $actorID, $now);
            $this->repository->markChampion($tournamentID, $winnerParticipantID);
            return $this->repository->resolveChampionPredictions(
                $tournamentID,
                $winnerParticipantID,
                (int) $tournament['championPickPoints'],
                (string) $tournament['championPickRewardJson'],
                $now
            );
        });
    }

    /** @return array<string,mixed>|null */
    public function claimPredictionReward(int $accountID, int $predictionID, ?int $now = null): ?array
    {
        $this->features->requirePredictionClaim();
        $row = $this->repository->claimReward($accountID, $predictionID, $now ?? time());
        if ($row === null) {
            return null;
        }
        $reward = json_decode((string) $row['rewardJson'], true);
        return [
            'predictionID' => (int) $row['predictionID'],
            'tournamentID' => (int) $row['tournamentID'],
            'pointsAwarded' => (int) $row['pointsAwarded'],
            'reward' => is_array($reward) ? $reward : [],
            'claimedAt' => (int) $row['claimedAt'],
        ];
    }

    /** @return array<string,mixed>|null */
    public function tournament(int $tournamentID): ?array
    {
        $this->features->requireTournamentRead();
        return $this->repository->tournament($tournamentID);
    }

    /** @return list<array<string,mixed>> */
    public function tournaments(int $limit = 50): array
    {
        $this->features->requireTournamentRead();
        return $this->repository->tournaments($limit);
    }

    /** @return list<array<string,mixed>> */
    public function participants(int $tournamentID): array
    {
        $this->features->requireTournamentRead();
        $this->requireTournament($tournamentID);
        return $this->repository->participants($tournamentID);
    }

    /** @return list<array<string,mixed>> */
    public function matches(int $tournamentID): array
    {
        $this->features->requireTournamentRead();
        $this->requireTournament($tournamentID);
        return $this->repository->matches($tournamentID);
    }

    /** @return list<array<string,mixed>> */
    public function predictionsForAccount(int $tournamentID, int $accountID): array
    {
        $this->features->requirePredictionRead();
        $this->requireTournament($tournamentID);
        return $this->repository->predictionsForAccount($tournamentID, $accountID);
    }

    /** @return list<array<string,mixed>> */
    public function predictionLeaderboard(int $tournamentID, int $limit = 100): array
    {
        $this->features->requirePredictionRead();
        $this->requireTournament($tournamentID);
        return $this->repository->leaderboard($tournamentID, $limit);
    }

    private function requireTournament(int $tournamentID): array
    {
        if ($tournamentID <= 0) {
            throw new InvalidArgumentException('Tournament ID must be positive.');
        }
        $row = $this->repository->tournament($tournamentID);
        if ($row === null) {
            throw new InvalidArgumentException('Tournament not found.');
        }
        return $row;
    }

    private function requireParticipant(int $participantID, int $tournamentID): array
    {
        if ($participantID <= 0) {
            throw new InvalidArgumentException('Participant ID must be positive.');
        }
        $row = $this->repository->participant($participantID);
        if ($row === null || (int) $row['tournamentID'] !== $tournamentID) {
            throw new InvalidArgumentException('Participant does not belong to this tournament.');
        }
        return $row;
    }

    private function requireMatch(int $matchID): array
    {
        if ($matchID <= 0) {
            throw new InvalidArgumentException('Match ID must be positive.');
        }
        $row = $this->repository->match($matchID);
        if ($row === null) {
            throw new InvalidArgumentException('Tournament match not found.');
        }
        return $row;
    }

    private function requirePermission(int $accountID, string $permission): void
    {
        if (!$this->staff->has($accountID, $permission)) {
            throw new RuntimeException('Missing staff permission: ' . $permission);
        }
    }

    private function requireResolvePermission(int $accountID): void
    {
        if (!$this->staff->has($accountID, 'tournaments.resolve') && !$this->staff->has($accountID, 'tournaments.manage')) {
            throw new RuntimeException('Missing staff permission: tournaments.resolve');
        }
    }

    private function validateWindow(int $opensAt, int $locksAt, string $label): void
    {
        if ($opensAt < 0 || $locksAt < 0) {
            throw new InvalidArgumentException(ucfirst($label) . ' cannot contain negative timestamps.');
        }
        if ($opensAt > 0 && $locksAt > 0 && $locksAt <= $opensAt) {
            throw new InvalidArgumentException(ucfirst($label) . ' lock must be after open time.');
        }
    }

    private function requireOpenWindow(int $opensAt, int $locksAt, int $now, string $label): void
    {
        if ($opensAt > 0 && $now < $opensAt) {
            throw new RuntimeException($label . ' window has not opened yet.');
        }
        if ($locksAt > 0 && $now >= $locksAt) {
            throw new RuntimeException($label . ' window is locked.');
        }
    }

    /** @param array<string,mixed> $reward */
    private function encodeReward(array $reward): string
    {
        return json_encode($reward, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function positiveOrNull(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }
}
