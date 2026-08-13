<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;
use NightCore\Domain\Moderation\StaffAccessService;
use NightCore\Domain\Tournaments\TournamentFeaturePolicy;
use NightCore\Domain\Tournaments\TournamentRepository;
use NightCore\Domain\Tournaments\TournamentService;
use NightCore\Security\PasswordService;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];

$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    $app = Application::boot();
    $migrations = new MigrationRunner($app->db(), $app->tables());
    $migrations->migrate($root . '/migrations');

    foreach (['accounts', 'users', 'levels', 'core_auth_attempts', 'core_level_downloads'] as $table) {
        $assert($app->schema()->tableExists($table), 'missing table ' . $table);
    }
    foreach (['core_tournaments', 'core_tournament_participants', 'core_tournament_matches', 'core_tournament_predictions'] as $table) {
        $assert($app->schema()->tableExists($table), 'missing tournament table ' . $table);
    }

    $assert($app->accounts()->register('IntegrationUser', 'secret', 'integration@example.test') === 1, 'account registration');
    $login = $app->accounts()->login('IntegrationUser', 'secret', '', 'integration-udid', '127.0.0.1');
    $assert((bool) preg_match('/^\d+,\d+$/', $login), 'account login');

    [$accountID, $userID] = array_map('intval', explode(',', $login));
    $passwords = new PasswordService();
    $gjp2 = $passwords->gjp2FromPassword('secret');

    $profileUpdate = $app->profiles()->updateScore($accountID, '', $gjp2, '127.0.0.1', [
        'gameVersion' => '22',
        'secret' => 'integration',
        'stars' => '123',
        'demons' => '4',
        'coins' => '50',
        'icon' => '1',
        'color1' => '2',
        'color2' => '3',
        'color3' => '4',
        'iconType' => '0',
        'userCoins' => '10',
        'special' => '0',
        'accIcon' => '1',
        'accShip' => '1',
        'accBall' => '1',
        'accBird' => '1',
        'accDart' => '1',
        'accRobot' => '1',
        'accGlow' => '1',
        'accSpider' => '1',
        'accExplosion' => '1',
        'accSwing' => '1',
        'accJetpack' => '1',
        'diamonds' => '25',
        'moons' => '6',
    ]);
    $assert($profileUpdate === (string) $userID, 'profile update');

    $levelID = $app->levels()->upload($accountID, '', $gjp2, '127.0.0.1', [
        'gameVersion' => '22',
        'binaryVersion' => '40',
        'levelID' => '0',
        'levelName' => 'Integration Level',
        'levelDesc' => rtrim(strtr(base64_encode('Night Core integration'), '+/', '-_'), '='),
        'levelVersion' => '1',
        'levelLength' => '3',
        'audioTrack' => '0',
        'secret' => 'integration',
        'password' => '1234',
        'objects' => '1000',
        'coins' => '3',
        'requestedStars' => '5',
        'levelString' => 'kS1,1,1,2,2,3,3;',
        'unlisted' => '0',
        'ldm' => '0',
    ]);
    $assert(ctype_digit($levelID) && (int) $levelID > 0, 'level upload');

    $search = $app->levels()->search([
        'gameVersion' => '22',
        'binaryVersion' => '40',
        'type' => '0',
        'str' => $levelID,
        'page' => '0',
    ], $accountID, '', $gjp2, '127.0.0.1');
    $assert($search !== '-1' && str_contains($search, '1:' . $levelID . ':2:Integration Level'), 'level search');

    $download = $app->levels()->download((int) $levelID, $accountID, '', $gjp2, '127.0.0.1', [
        'gameVersion' => '22',
        'binaryVersion' => '40',
        'extras' => '1',
        'inc' => '1',
    ]);
    $assert($download !== '-1' && str_starts_with($download, '1:' . $levelID . ':2:Integration Level:'), 'level download');
    $assert(substr_count($download, '#') === 2, 'level download hashes');

    $stored = getenv('LEVEL_STORAGE_PATH');
    if ($stored !== false && $stored !== '') {
        $assert(is_file(rtrim($stored, '/\\') . DIRECTORY_SEPARATOR . $levelID), 'level file storage');
    }

    // This is fixture setup, not a registration-flow test. Create the second creator
    // directly so the tournament scenario does not consume the anti-abuse registration quota
    // shared with later CI tests in the same MariaDB database.
    $creatorTwoPassword = 'secret2';
    $creatorTwoAccountID = $app->accountRepository()->create(
        'CreatorTwo',
        $passwords->hashPassword($creatorTwoPassword),
        'creator2@example.test',
        1,
        $passwords->hashGjp2FromPassword($creatorTwoPassword)
    );
    $app->accountRepository()->ensureUser($creatorTwoAccountID, 'CreatorTwo');
    $assert($creatorTwoAccountID > 0, 'second creator fixture');

    $staff = new StaffAccessService($app->staffAccess()->repository(), [$accountID]);
    $tournaments = new TournamentService(
        new TournamentRepository($app->db(), $app->tables()),
        new TournamentFeaturePolicy('enabled', 'enabled'),
        $staff
    );

    $now = time();
    $tournamentID = $tournaments->createTournament(
        $accountID,
        'integration-major',
        'Integration Major',
        $now - 60,
        $now + 3600,
        $now + 3600,
        0,
        2,
        7,
        ['badge' => 'match-pick'],
        ['badge' => 'champion-pick'],
        $now
    );
    $assert($tournamentID > 0, 'tournament creation');

    $participantOne = $tournaments->addParticipant($accountID, $tournamentID, $accountID, 'IntegrationUser', 1, $now);
    $participantTwo = $tournaments->addParticipant($accountID, $tournamentID, $creatorTwoAccountID, 'CreatorTwo', 2, $now);
    $assert($participantOne > 0 && $participantTwo > 0, 'tournament participants');

    $matchID = $tournaments->createMatch(
        $accountID,
        $tournamentID,
        'final',
        1,
        $participantOne,
        $participantTwo,
        (int) $levelID,
        null,
        $now - 60,
        $now + 600,
        $now
    );
    $assert($matchID > 0, 'tournament match creation');
    $assert($tournaments->setTournamentStatus($accountID, $tournamentID, 'open', $now), 'open tournament');
    $assert($tournaments->setMatchStatus($accountID, $matchID, 'open', $now), 'open tournament match');

    $championPredictionID = $tournaments->submitChampionPrediction($creatorTwoAccountID, $tournamentID, $participantOne, $now);
    $matchPredictionID = $tournaments->submitMatchPrediction($creatorTwoAccountID, $matchID, $participantOne, $now);
    $assert($championPredictionID > 0 && $matchPredictionID > 0, 'pickem submissions');

    $resolvedMatchPredictions = $tournaments->resolveMatch($accountID, $matchID, $participantOne, $now + 10);
    $assert($resolvedMatchPredictions === 1, 'match pickem resolution');
    $resolvedChampionPredictions = $tournaments->completeTournament($accountID, $tournamentID, $participantOne, $now + 20);
    $assert($resolvedChampionPredictions === 1, 'champion pickem resolution');

    $leaderboard = $tournaments->predictionLeaderboard($tournamentID, 10);
    $assert(count($leaderboard) === 1, 'pickem leaderboard row count');
    $assert((int) ($leaderboard[0]['accountID'] ?? 0) === $creatorTwoAccountID, 'pickem leaderboard account');
    $assert((int) ($leaderboard[0]['points'] ?? 0) === 9, 'pickem points total');
    $assert((int) ($leaderboard[0]['correctPicks'] ?? 0) === 2, 'pickem correct picks');
    $assert((int) ($leaderboard[0]['championCorrect'] ?? 0) === 1, 'pickem champion correct');

    $reward = $tournaments->claimPredictionReward($creatorTwoAccountID, $championPredictionID, $now + 30);
    $assert(is_array($reward), 'champion reward claim');
    $assert(($reward['reward']['badge'] ?? '') === 'champion-pick', 'champion reward payload');
    $assert($tournaments->claimPredictionReward($creatorTwoAccountID, $championPredictionID, $now + 31) === null, 'reward is one-time');
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, 'INTEGRATION FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core integration test: OK\n";
