<?php

declare(strict_types=1);

namespace NightCore\Web\Tournaments;

use NightCore\Core\Application;
use NightCore\Domain\Tournaments\TournamentFeaturePolicy;
use NightCore\Domain\Tournaments\TournamentSystem;

final class TournamentClientApiController
{
    public function __construct(
        private Application $app,
        private TournamentSystem $system
    ) {
    }

    /**
     * Handle an authenticated native-client request.
     *
     * @param array<string,mixed> $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function handle(array $input, string $ip): array
    {
        if ($this->system->features()->tournamentsMode() === TournamentFeaturePolicy::DISABLED) {
            return $this->result(404, ['ok' => false, 'error' => 'tournaments_disabled']);
        }

        $accountID = $this->positiveInt($input['accountID'] ?? 0);
        $gjp = $this->field($input['gjp'] ?? '', 512);
        $gjp2 = $this->field($input['gjp2'] ?? '', 128);

        if ($accountID <= 0 || ($gjp === '' && $gjp2 === '')
            || !$this->app->authenticator()->verify($accountID, $gjp, $gjp2, $ip)) {
            return $this->result(401, ['ok' => false, 'error' => 'authentication_failed']);
        }

        $account = $this->app->accountRepository()->findById($accountID);
        if ($account === null) {
            return $this->result(401, ['ok' => false, 'error' => 'authentication_failed']);
        }

        $action = strtolower($this->field($input['action'] ?? 'state', 32));
        if ($action === '') {
            $action = 'state';
        }

        return match ($action) {
            'state' => $this->result(200, $this->statePayload(
                $account,
                $this->positiveInt($input['tournamentID'] ?? 0)
            )),
            'champion_pick' => $this->championPick($account, $input),
            'match_pick' => $this->matchPick($account, $input),
            'claim_reward' => $this->claimReward($account, $input),
            default => $this->result(400, ['ok' => false, 'error' => 'unknown_action']),
        };
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $input */
    private function championPick(array $account, array $input): array
    {
        $this->requirePredictionWrite();
        $tournamentID = $this->positiveInt($input['tournamentID'] ?? 0);
        $participantID = $this->positiveInt($input['participantID'] ?? 0);
        if ($tournamentID <= 0 || $participantID <= 0) {
            return $this->result(400, ['ok' => false, 'error' => 'invalid_prediction']);
        }

        $predictionID = $this->system->core()->submitChampionPrediction(
            (int) $account['accountID'],
            $tournamentID,
            $participantID
        );

        $payload = $this->statePayload($account, $tournamentID);
        $payload['result'] = [
            'action' => 'champion_pick',
            'predictionID' => $predictionID,
        ];
        return $this->result(200, $payload);
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $input */
    private function matchPick(array $account, array $input): array
    {
        $this->requirePredictionWrite();
        $matchID = $this->positiveInt($input['matchID'] ?? 0);
        $participantID = $this->positiveInt($input['participantID'] ?? 0);
        if ($matchID <= 0 || $participantID <= 0) {
            return $this->result(400, ['ok' => false, 'error' => 'invalid_prediction']);
        }

        $predictionID = $this->system->core()->submitMatchPrediction(
            (int) $account['accountID'],
            $matchID,
            $participantID
        );

        $matches = $this->system->view()->matchesForMatchLookup($matchID);
        $tournamentID = $matches === null ? 0 : (int) $matches['tournamentID'];
        $payload = $this->statePayload($account, $tournamentID);
        $payload['result'] = [
            'action' => 'match_pick',
            'predictionID' => $predictionID,
        ];
        return $this->result(200, $payload);
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $input */
    private function claimReward(array $account, array $input): array
    {
        $this->requirePredictionWrite();
        $predictionID = $this->positiveInt($input['predictionID'] ?? 0);
        if ($predictionID <= 0) {
            return $this->result(400, ['ok' => false, 'error' => 'invalid_prediction']);
        }

        $claimed = $this->system->core()->claimPredictionReward((int) $account['accountID'], $predictionID);
        if ($claimed === null) {
            return $this->result(409, ['ok' => false, 'error' => 'reward_not_claimable']);
        }

        $payload = $this->statePayload($account, (int) ($claimed['tournamentID'] ?? 0));
        $payload['result'] = [
            'action' => 'claim_reward',
            'claim' => $claimed,
        ];
        return $this->result(200, $payload);
    }

    /** @param array<string,mixed> $account @return array<string,mixed> */
    private function statePayload(array $account, int $tournamentID): array
    {
        if ($tournamentID <= 0) {
            $rows = $this->system->view()->tournaments(50);
            if ($rows !== []) {
                $tournamentID = (int) $rows[0]['tournamentID'];
            }
        }

        $payload = [
            'ok' => true,
            'authenticated' => true,
            'account' => [
                'accountID' => (int) $account['accountID'],
                'userName' => (string) ($account['userName'] ?? ''),
            ],
            'tournaments' => $this->system->view()->tournaments(50),
            'tournament' => null,
            'participants' => [],
            'matches' => [],
            'predictions' => [],
            'leaderboard' => [],
            'unclaimedRewards' => [],
        ];

        if ($tournamentID <= 0) {
            return $payload;
        }

        $tournament = $this->system->view()->tournament($tournamentID);
        if ($tournament === null) {
            return [
                'ok' => false,
                'authenticated' => true,
                'account' => $payload['account'],
                'error' => 'tournament_not_found',
            ];
        }

        $payload['tournament'] = $tournament;
        $payload['participants'] = $this->system->view()->participants($tournamentID);
        $payload['matches'] = $this->system->view()->matches($tournamentID);

        if ($this->system->features()->predictionsMode() !== TournamentFeaturePolicy::DISABLED) {
            $accountID = (int) $account['accountID'];
            $payload['predictions'] = $this->system->view()->predictionsForAccount($tournamentID, $accountID);
            $payload['leaderboard'] = $this->system->view()->leaderboard($tournamentID, 100);
            $payload['unclaimedRewards'] = $this->system->view()->unclaimedRewards($tournamentID, $accountID);
        }

        return $payload;
    }

    private function requirePredictionWrite(): void
    {
        $this->system->features()->requirePredictionWrite();
    }

    /** @return array{status:int,payload:array<string,mixed>} */
    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }

    private function field(mixed $value, int $maxLength): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
    }

    private function positiveInt(mixed $value): int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return 0;
        }
        return max(0, (int) $value);
    }
}
