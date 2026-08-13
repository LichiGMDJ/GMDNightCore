<?php

declare(strict_types=1);

namespace NightCore\Web\Tournaments;

use DateTimeImmutable;
use NightCore\Core\Application;
use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Domain\Tournaments\BracketSettings;
use NightCore\Domain\Tournaments\MatchEntries;
use NightCore\Domain\Tournaments\TournamentSetup;
use NightCore\Domain\Tournaments\TournamentSystem;
use NightCore\Web\Security\PanelSecurity;
use RuntimeException;

final class TournamentWebController
{
    public function __construct(
        private Application $app,
        private TournamentSystem $system,
        private PanelSecurity $session
    ) {
    }

    /** @param array<string,mixed> $post */
    public function handle(array $post): string
    {
        $this->session->requireCsrf($post['csrf'] ?? '');
        $action = trim((string) ($post['action'] ?? ''));

        return match ($action) {
            'login' => $this->login($post),
            'logout' => $this->logout(),
            'champion_pick' => $this->championPick($post),
            'match_pick' => $this->matchPick($post),
            'claim_reward' => $this->claimReward($post),
            'create_tournament' => $this->createTournament($post),
            'add_participant' => $this->addParticipant($post),
            'generate_bracket' => $this->generateBracket($post),
            'open_tournament' => $this->openTournament($post),
            'activate_tournament' => $this->activateTournament($post),
            'set_entries' => $this->setEntries($post),
            'open_match' => $this->openMatch($post),
            'start_judging' => $this->startJudging($post),
            'resolve_match' => $this->resolveMatch($post),
            default => throw new RuntimeException('Unknown tournament action.'),
        };
    }

    private function login(array $post): string
    {
        $username = substr(trim((string) ($post['username'] ?? '')), 0, 20);
        $password = (string) ($post['password'] ?? '');
        if ($username === '' || $password === '') {
            throw new RuntimeException('Username and password are required.');
        }

        $ip = ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false));
        $result = $this->app->accounts()->login($username, $password, '', 'tournament-web', $ip);
        if (preg_match('/^(\d+),(\d+)$/D', $result, $match) !== 1) {
            throw new RuntimeException($result === '-12' ? 'Too many login attempts. Try again later.' : 'Invalid username or password.');
        }
        $account = $this->app->accountRepository()->findById((int) $match[1]);
        if ($account === null) {
            throw new RuntimeException('Account could not be loaded after login.');
        }
        $this->session->signIn($account);
        return 'Signed in as ' . (string) $account['userName'] . '.';
    }

    private function logout(): string
    {
        $this->session->signOut();
        return 'Signed out.';
    }

    private function championPick(array $post): string
    {
        $accountID = $this->requireLogin();
        $this->system->core()->submitChampionPrediction(
            $accountID,
            $this->positiveInt($post['tournament_id'] ?? 0, 'tournament'),
            $this->positiveInt($post['participant_id'] ?? 0, 'participant')
        );
        return 'Champion prediction saved.';
    }

    private function matchPick(array $post): string
    {
        $accountID = $this->requireLogin();
        $this->system->core()->submitMatchPrediction(
            $accountID,
            $this->positiveInt($post['match_id'] ?? 0, 'match'),
            $this->positiveInt($post['participant_id'] ?? 0, 'participant')
        );
        return 'Match prediction saved.';
    }

    private function claimReward(array $post): string
    {
        $accountID = $this->requireLogin();
        $claimed = $this->system->core()->claimPredictionReward(
            $accountID,
            $this->positiveInt($post['prediction_id'] ?? 0, 'prediction')
        );
        if ($claimed === null) {
            throw new RuntimeException('Reward is unavailable or was already claimed.');
        }
        return 'Prediction reward claimed.';
    }

    private function createTournament(array $post): string
    {
        $actorID = $this->requirePermission('tournaments.manage');
        $setup = TournamentSetup::fromArray([
            'slug' => $post['slug'] ?? '',
            'name' => $post['name'] ?? '',
            'description' => $post['description'] ?? '',
            'rulesText' => $post['rules'] ?? '',
            'predictionOpensAt' => $this->parseDateTime($post['prediction_opens'] ?? ''),
            'predictionLocksAt' => $this->parseDateTime($post['prediction_locks'] ?? ''),
            'startsAt' => $this->parseDateTime($post['starts_at'] ?? ''),
            'endsAt' => $this->parseDateTime($post['ends_at'] ?? ''),
            'matchPickPoints' => $post['match_points'] ?? 1,
            'championPickPoints' => $post['champion_points'] ?? 5,
            'matchPickReward' => $this->decodeReward($post['match_reward'] ?? ''),
            'championPickReward' => $this->decodeReward($post['champion_reward'] ?? ''),
        ]);
        $id = $this->system->competition()->createTournament($actorID, $setup);
        return 'Tournament created. ID: ' . $id . '.';
    }

    private function addParticipant(array $post): string
    {
        $actorID = $this->requirePermission('tournaments.manage');
        $accountRef = substr(trim((string) ($post['account'] ?? '')), 0, 64);
        if ($accountRef === '') {
            throw new RuntimeException('Account name or ID is required.');
        }
        $account = ctype_digit($accountRef)
            ? $this->app->accountRepository()->findById((int) $accountRef)
            : $this->app->accountRepository()->findByUsername($accountRef);
        if ($account === null) {
            throw new RuntimeException('Account was not found.');
        }
        $seed = trim((string) ($post['seed'] ?? ''));
        $participantID = $this->system->competition()->addParticipant(
            $actorID,
            $this->positiveInt($post['tournament_id'] ?? 0, 'tournament'),
            $account,
            $seed === '' ? null : $this->positiveInt($seed, 'seed')
        );
        return 'Creator added to tournament. Participant ID: ' . $participantID . '.';
    }

    private function generateBracket(array $post): string
    {
        $actorID = $this->requirePermission('tournaments.manage');
        $result = $this->system->competition()->generateBracket(
            $actorID,
            $this->positiveInt($post['tournament_id'] ?? 0, 'tournament'),
            new BracketSettings((int) ($post['bracket_size'] ?? 0))
        );
        return 'Bracket generated: ' . $result['matches'] . ' matches across ' . $result['rounds'] . ' rounds.';
    }

    private function openTournament(array $post): string
    {
        $this->system->competition()->openTournament(
            $this->requirePermission('tournaments.manage'),
            $this->positiveInt($post['tournament_id'] ?? 0, 'tournament')
        );
        return 'Tournament opened for predictions.';
    }

    private function activateTournament(array $post): string
    {
        $this->system->competition()->activateTournament(
            $this->requirePermission('tournaments.manage'),
            $this->positiveInt($post['tournament_id'] ?? 0, 'tournament')
        );
        return 'Tournament marked active.';
    }

    private function setEntries(array $post): string
    {
        $this->system->competition()->configureMatchEntries(
            $this->requirePermission('tournaments.manage'),
            $this->positiveInt($post['match_id'] ?? 0, 'match'),
            new MatchEntries($this->nullablePositiveInt($post['entry1'] ?? ''), $this->nullablePositiveInt($post['entry2'] ?? ''))
        );
        return 'Match level entries updated.';
    }

    private function openMatch(array $post): string
    {
        $minutes = max(1, min(10080, (int) ($post['duration_minutes'] ?? 60)));
        $this->system->competition()->openMatch(
            $this->requirePermission('tournaments.manage'),
            $this->positiveInt($post['match_id'] ?? 0, 'match'),
            $minutes * 60
        );
        return 'Match opened for ' . $minutes . ' minute(s).';
    }

    private function startJudging(array $post): string
    {
        $this->system->competition()->startJudging(
            $this->requirePermission('tournaments.manage'),
            $this->positiveInt($post['match_id'] ?? 0, 'match')
        );
        return 'Match moved to judging.';
    }

    private function resolveMatch(array $post): string
    {
        $actorID = $this->requireResolvePermission();
        $result = $this->system->competition()->resolveMatch(
            $actorID,
            $this->positiveInt($post['match_id'] ?? 0, 'match'),
            $this->positiveInt($post['winner_participant_id'] ?? 0, 'winner')
        );
        return $result['final'] ? 'Final resolved. Tournament champion crowned.' : 'Match resolved. Winner advanced automatically.';
    }

    private function requireLogin(): int
    {
        $accountID = $this->session->accountId();
        if ($accountID <= 0) {
            throw new RuntimeException('Sign in to use Pick’em.');
        }
        return $accountID;
    }

    private function requirePermission(string $permission): int
    {
        $accountID = $this->requireLogin();
        if (!$this->app->staffAccess()->has($accountID, $permission)) {
            throw new RuntimeException('Missing staff permission: ' . $permission);
        }
        return $accountID;
    }

    private function requireResolvePermission(): int
    {
        $accountID = $this->requireLogin();
        if (!$this->app->staffAccess()->has($accountID, 'tournaments.resolve')
            && !$this->app->staffAccess()->has($accountID, 'tournaments.manage')) {
            throw new RuntimeException('Missing staff permission: tournaments.resolve.');
        }
        return $accountID;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $number = is_numeric($value) ? (int) $value : 0;
        if ($number <= 0) {
            throw new RuntimeException('Invalid ' . $label . ' ID/value.');
        }
        return $number;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $number = (int) $value;
        return $number > 0 ? $number : null;
    }

    private function parseDateTime(mixed $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        if ($date === false) {
            throw new RuntimeException('Invalid date/time value.');
        }
        return $date->getTimestamp();
    }

    /** @return array<string,mixed> */
    private function decodeReward(mixed $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Reward must be a JSON object.');
        }
        return $decoded;
    }
}
