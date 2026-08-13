<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;
use NightCore\Domain\Moderation\StaffAccessService;
use RuntimeException;

final class TournamentCompetitionService
{
    public function __construct(
        private TournamentService $core,
        private TournamentBracketRepository $bracket,
        private TournamentFeaturePolicy $features,
        private StaffAccessService $staff
    ) {
    }

    public function createTournament(int $actorID, TournamentSetup $setup, ?int $now = null): int
    {
        $now ??= time();
        $tournamentID = $this->core->createTournament(
            $actorID,
            $setup->slug(),
            $setup->name(),
            $setup->predictionOpensAt(),
            $setup->predictionLocksAt(),
            $setup->startsAt(),
            $setup->endsAt(),
            $setup->matchPickPoints(),
            $setup->championPickPoints(),
            $setup->matchPickReward(),
            $setup->championPickReward(),
            $now
        );
        $this->bracket->updateTournamentPresentation($tournamentID, $setup->description(), $setup->rulesText(), $actorID, $now);
        $this->bracket->audit($actorID, $tournamentID, 0, 'tournament.create', ['slug' => $setup->slug(), 'name' => $setup->name()], $now);
        return $tournamentID;
    }

    /** @param array<string,mixed> $account */
    public function addParticipant(int $actorID, int $tournamentID, array $account, ?int $seed = null, ?int $now = null): int
    {
        $accountID = (int) ($account['accountID'] ?? 0);
        $displayName = trim((string) ($account['userName'] ?? ''));
        if ($accountID <= 0 || $displayName === '') {
            throw new InvalidArgumentException('A valid account is required for a tournament participant.');
        }
        $now ??= time();
        $participantID = $this->core->addParticipant($actorID, $tournamentID, $accountID, $displayName, $seed, $now);
        $this->bracket->audit($actorID, $tournamentID, 0, 'participant.add', ['participantID' => $participantID, 'accountID' => $accountID, 'seed' => $seed], $now);
        return $participantID;
    }

    /** @return array{matches:int,rounds:int,size:int} */
    public function generateBracket(int $actorID, int $tournamentID, BracketSettings $settings, ?int $now = null): array
    {
        $this->features->requireTournamentWrite();
        $this->requireManage($actorID);
        $tournament = $this->requireTournament($tournamentID);
        if (!in_array((string) $tournament['status'], ['draft', 'open'], true)) {
            throw new RuntimeException('Bracket can only be generated before the tournament becomes active.');
        }
        if ($this->bracket->matchCount($tournamentID) > 0) {
            throw new RuntimeException('Tournament bracket already exists.');
        }

        $participants = $this->bracket->participants($tournamentID);
        if (count($participants) !== $settings->size()) {
            throw new RuntimeException('Participant count must exactly match the selected bracket size.');
        }
        foreach ($participants as $participant) {
            if ((string) ($participant['status'] ?? '') !== 'active') {
                throw new RuntimeException('Only active participants can be seeded into a new bracket.');
            }
        }

        $now ??= time();
        $seeded = $this->seedParticipants($participants, $settings->size());
        $roundSizes = SingleEliminationBracket::roundSizes($settings->size());
        $roundMatchIDs = [];
        $created = 0;

        foreach ($roundSizes as $roundIndex => $roundSize) {
            $matchCount = intdiv($roundSize, 2);
            $stage = TournamentStage::keyForRoundSize($roundSize);
            $roundMatchIDs[$roundIndex] = [];
            for ($matchOrder = 1; $matchOrder <= $matchCount; $matchOrder++) {
                $participantOne = null;
                $participantTwo = null;
                $opensAt = 0;
                $locksAt = 0;
                if ($roundIndex === 0) {
                    $participantOne = (int) $seeded[(($matchOrder - 1) * 2)]['participantID'];
                    $participantTwo = (int) $seeded[(($matchOrder - 1) * 2) + 1]['participantID'];
                    $opensAt = $settings->firstRoundOpensAt();
                    $locksAt = $settings->firstRoundLocksAt();
                }
                $matchID = $this->core->createMatch(
                    $actorID,
                    $tournamentID,
                    $stage,
                    $matchOrder,
                    $participantOne,
                    $participantTwo,
                    null,
                    null,
                    $opensAt,
                    $locksAt,
                    $now
                );
                $roundMatchIDs[$roundIndex][] = $matchID;
                $created++;
            }
        }

        foreach ($roundSizes as $roundIndex => $roundSize) {
            foreach ($roundMatchIDs[$roundIndex] as $index => $matchID) {
                $nextMatchID = null;
                $nextSlot = null;
                if (isset($roundMatchIDs[$roundIndex + 1])) {
                    $nextMatchID = $roundMatchIDs[$roundIndex + 1][intdiv($index, 2)] ?? null;
                    $nextSlot = ($index % 2) + 1;
                }
                $this->bracket->setMatchBracketMeta($matchID, $roundIndex + 1, $roundSize, $nextMatchID, $nextSlot, $now);
            }
        }

        $this->bracket->setBracketInfo($tournamentID, $settings->size(), $actorID, $now);
        $this->bracket->audit($actorID, $tournamentID, 0, 'bracket.generate', ['size' => $settings->size(), 'matches' => $created], $now);
        return ['matches' => $created, 'rounds' => count($roundSizes), 'size' => $settings->size()];
    }

    public function openTournament(int $actorID, int $tournamentID, ?int $now = null): void
    {
        $now ??= time();
        if (!$this->core->setTournamentStatus($actorID, $tournamentID, 'open', $now)) {
            throw new RuntimeException('Tournament status did not change.');
        }
        $this->bracket->audit($actorID, $tournamentID, 0, 'tournament.open', [], $now);
    }

    public function activateTournament(int $actorID, int $tournamentID, ?int $now = null): void
    {
        $now ??= time();
        if (!$this->core->setTournamentStatus($actorID, $tournamentID, 'active', $now)) {
            throw new RuntimeException('Tournament status did not change.');
        }
        $this->bracket->audit($actorID, $tournamentID, 0, 'tournament.activate', [], $now);
    }

    public function configureMatchEntries(int $actorID, int $matchID, MatchEntries $entries, ?int $now = null): void
    {
        $this->features->requireTournamentWrite();
        $this->requireManage($actorID);
        $match = $this->requireMatch($matchID);
        if ((string) $match['status'] === 'completed') {
            throw new RuntimeException('Completed match entries cannot be changed.');
        }
        foreach ([$entries->participantOneLevelID(), $entries->participantTwoLevelID()] as $levelID) {
            if ($levelID !== null && !$this->bracket->levelExists($levelID)) {
                throw new InvalidArgumentException('One of the submitted level IDs does not exist.');
            }
        }
        $now ??= time();
        $this->bracket->setMatchEntries($matchID, $entries, $now);
        $this->bracket->audit($actorID, (int) $match['tournamentID'], $matchID, 'match.entries', ['entry1LevelID' => $entries->participantOneLevelID(), 'entry2LevelID' => $entries->participantTwoLevelID()], $now);
    }

    public function openMatch(int $actorID, int $matchID, int $durationSeconds, ?int $now = null): void
    {
        $this->features->requireTournamentWrite();
        $this->requireManage($actorID);
        $match = $this->requireMatch($matchID);
        $tournament = $this->requireTournament((int) $match['tournamentID']);
        if (!in_array((string) $tournament['status'], ['open', 'active'], true)) {
            throw new RuntimeException('Open the tournament before opening a match.');
        }
        if ((int) ($match['participant1ID'] ?? 0) <= 0 || (int) ($match['participant2ID'] ?? 0) <= 0) {
            throw new RuntimeException('Both participant slots must be filled before opening a match.');
        }
        if ((int) ($match['entry1LevelID'] ?? 0) <= 0 || (int) ($match['entry2LevelID'] ?? 0) <= 0) {
            throw new RuntimeException('Both creator level entries must be assigned before opening a match.');
        }
        $durationSeconds = max(60, min(604800, $durationSeconds));
        $now ??= time();
        if (!$this->bracket->openMatch($matchID, $now, $now + $durationSeconds, $now)) {
            throw new RuntimeException('Match could not be opened.');
        }
        $this->bracket->audit($actorID, (int) $match['tournamentID'], $matchID, 'match.open', ['locksAt' => $now + $durationSeconds], $now);
    }

    public function startJudging(int $actorID, int $matchID, ?int $now = null): void
    {
        $this->features->requireTournamentWrite();
        $this->requireManage($actorID);
        $match = $this->requireMatch($matchID);
        $now ??= time();
        if (!$this->bracket->setMatchJudging($matchID, $now)) {
            throw new RuntimeException('Only an open match can enter judging.');
        }
        $this->bracket->audit($actorID, (int) $match['tournamentID'], $matchID, 'match.judging', [], $now);
    }

    /** @return array{final:bool,nextMatchID:?int,resolvedPredictions:int} */
    public function resolveMatch(int $actorID, int $matchID, int $winnerParticipantID, ?int $now = null): array
    {
        $match = $this->requireMatch($matchID);
        $participantOne = (int) ($match['participant1ID'] ?? 0);
        $participantTwo = (int) ($match['participant2ID'] ?? 0);
        if (!in_array($winnerParticipantID, [$participantOne, $participantTwo], true)) {
            throw new InvalidArgumentException('Winner must be one of the match participants.');
        }

        $nextMatchID = isset($match['nextMatchID']) ? (int) $match['nextMatchID'] : 0;
        $nextSlot = isset($match['nextSlot']) ? (int) $match['nextSlot'] : 0;
        if ($nextMatchID > 0) {
            $nextMatch = $this->requireMatch($nextMatchID);
            $slotValue = $nextSlot === 1 ? (int) ($nextMatch['participant1ID'] ?? 0) : (int) ($nextMatch['participant2ID'] ?? 0);
            if ($slotValue > 0 && $slotValue !== $winnerParticipantID) {
                throw new RuntimeException('Next bracket slot is already occupied by a different participant.');
            }
        }

        $now ??= time();
        $resolved = $this->core->resolveMatch($actorID, $matchID, $winnerParticipantID, $now);
        $loserParticipantID = $winnerParticipantID === $participantOne ? $participantTwo : $participantOne;
        $roundSize = max(2, (int) ($match['roundSize'] ?? 2));
        $this->bracket->markParticipantEliminated($loserParticipantID);
        $this->bracket->setPlacement($loserParticipantID, intdiv($roundSize, 2) + 1);

        $isFinal = $nextMatchID <= 0 || (string) $match['stage'] === 'final';
        if ($isFinal) {
            $resolved += $this->core->completeTournament($actorID, (int) $match['tournamentID'], $winnerParticipantID, $now);
            $this->bracket->setPlacement($winnerParticipantID, 1);
            $this->bracket->setPlacement($loserParticipantID, 2);
        } else {
            if (!$this->bracket->assignParticipant($nextMatchID, $nextSlot, $winnerParticipantID, $now)) {
                throw new RuntimeException('Winner could not be advanced to the next match.');
            }
        }

        $this->bracket->audit($actorID, (int) $match['tournamentID'], $matchID, 'match.resolve', [
            'winnerParticipantID' => $winnerParticipantID,
            'loserParticipantID' => $loserParticipantID,
            'nextMatchID' => $nextMatchID > 0 ? $nextMatchID : null,
            'resolvedPredictions' => $resolved,
        ], $now);

        return ['final' => $isFinal, 'nextMatchID' => $nextMatchID > 0 ? $nextMatchID : null, 'resolvedPredictions' => $resolved];
    }

    /** @param list<array<string,mixed>> $participants @return list<array<string,mixed>> */
    private function seedParticipants(array $participants, int $size): array
    {
        $bySeed = [];
        $validSeeds = true;
        foreach ($participants as $participant) {
            $seed = isset($participant['seed']) ? (int) $participant['seed'] : 0;
            if ($seed < 1 || $seed > $size || isset($bySeed[$seed])) {
                $validSeeds = false;
                break;
            }
            $bySeed[$seed] = $participant;
        }
        if (!$validSeeds || count($bySeed) !== $size) {
            $bySeed = [];
            foreach (array_values($participants) as $index => $participant) {
                $bySeed[$index + 1] = $participant;
            }
        }
        $ordered = [];
        foreach (SingleEliminationBracket::seedOrder($size) as $seed) {
            $ordered[] = $bySeed[$seed];
        }
        return $ordered;
    }

    /** @return array<string,mixed> */
    private function requireTournament(int $tournamentID): array
    {
        $row = $this->core->tournament($tournamentID);
        if ($row === null) {
            throw new InvalidArgumentException('Tournament not found.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function requireMatch(int $matchID): array
    {
        if ($matchID <= 0) {
            throw new InvalidArgumentException('Match ID must be positive.');
        }
        $row = $this->bracket->match($matchID);
        if ($row === null) {
            throw new InvalidArgumentException('Tournament match not found.');
        }
        return $row;
    }

    private function requireManage(int $actorID): void
    {
        if (!$this->staff->has($actorID, 'tournaments.manage')) {
            throw new RuntimeException('Missing staff permission: tournaments.manage');
        }
    }
}
