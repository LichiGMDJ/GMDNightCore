<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\MigrationRunner;
use NightCore\Domain\Moderation\StaffAccessService;
use NightCore\Domain\Tournaments\BracketSettings;
use NightCore\Domain\Tournaments\MatchEntries;
use NightCore\Domain\Tournaments\TournamentBracketRepository;
use NightCore\Domain\Tournaments\TournamentCompetitionService;
use NightCore\Domain\Tournaments\TournamentFeaturePolicy;
use NightCore\Domain\Tournaments\TournamentRepository;
use NightCore\Domain\Tournaments\TournamentService;
use NightCore\Domain\Tournaments\TournamentSetup;
use NightCore\Domain\Tournaments\TournamentViewRepository;
use NightCore\Security\PasswordService;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) { $failures[] = $label; }
};

try {
    $app = Application::boot();
    (new MigrationRunner($app->db(), $app->tables()))->migrate($root . '/migrations');
    $passwords = new PasswordService();
    $makeAccount = static function (string $name) use ($app, $passwords): array {
        $password = 'TournamentPass!42';
        $accountID = $app->accountRepository()->create($name, $passwords->hashPassword($password), strtolower($name) . '@example.test', 1, $passwords->hashGjp2FromPassword($password));
        $app->accountRepository()->ensureUser($accountID, $name);
        return ['accountID' => $accountID, 'userName' => $name, 'password' => $password];
    };

    $owner = $makeAccount('MajorOwner');
    $creators = [];
    for ($seed = 1; $seed <= 8; $seed++) { $creators[$seed] = $makeAccount('Creator' . $seed); }

    $ownerGjp2 = $passwords->gjp2FromPassword($owner['password']);
    $levelID = $app->levels()->upload((int) $owner['accountID'], '', $ownerGjp2, '127.0.0.1', [
        'gameVersion' => '22', 'binaryVersion' => '40', 'levelID' => '0', 'levelName' => 'Major Test Entry',
        'levelDesc' => rtrim(strtr(base64_encode('Tournament integration entry'), '+/', '-_'), '='),
        'levelVersion' => '1', 'levelLength' => '2', 'audioTrack' => '0', 'secret' => 'tournament-test',
        'password' => '0', 'objects' => '100', 'coins' => '0', 'requestedStars' => '0',
        'levelString' => 'kS1,1,1,2,2,3,3;', 'unlisted' => '0', 'ldm' => '0',
    ]);
    $assert(ctype_digit($levelID) && (int) $levelID > 0, 'test level upload');

    $features = new TournamentFeaturePolicy('enabled', 'enabled');
    $staff = new StaffAccessService($app->staffAccess()->repository(), [(int) $owner['accountID']]);
    $core = new TournamentService(new TournamentRepository($app->db(), $app->tables()), $features, $staff);
    $bracketRepository = new TournamentBracketRepository($app->db(), $app->tables());
    $competition = new TournamentCompetitionService($core, $bracketRepository, $features, $staff);
    $view = new TournamentViewRepository($app->db(), $app->tables());

    $now = time();
    $setup = TournamentSetup::fromArray([
        'slug' => 'major-system-test', 'name' => 'Night Core Major System Test',
        'description' => 'Eight-creator single elimination integration fixture.',
        'rulesText' => 'Each match has two entries. Staff resolves the winner.',
        'predictionOpensAt' => $now - 60, 'predictionLocksAt' => $now + 1800, 'startsAt' => $now + 1800,
        'matchPickPoints' => 2, 'championPickPoints' => 7,
        'matchPickReward' => ['badge' => 'major-match-pick'], 'championPickReward' => ['badge' => 'major-champion-pick'],
    ]);
    $tournamentID = $competition->createTournament((int) $owner['accountID'], $setup, $now);
    $assert($tournamentID > 0, 'tournament created');

    $participantIDs = [];
    foreach ($creators as $seed => $creator) {
        $participantIDs[$seed] = $competition->addParticipant((int) $owner['accountID'], $tournamentID, $creator, $seed, $now);
    }
    $generated = $competition->generateBracket((int) $owner['accountID'], $tournamentID, new BracketSettings(8), $now);
    $assert($generated['matches'] === 7, '8-player bracket has seven matches');
    $assert($generated['rounds'] === 3, '8-player bracket has three rounds');

    $matches = $view->matches($tournamentID);
    $assert(count($matches) === 7, 'view returns complete bracket');
    $assert(count(array_filter($matches, static fn(array $m): bool => (int) $m['roundNumber'] === 1)) === 4, 'quarterfinal count');
    $assert(count(array_filter($matches, static fn(array $m): bool => (int) $m['roundNumber'] === 2)) === 2, 'semifinal count');
    $assert(count(array_filter($matches, static fn(array $m): bool => (int) $m['roundNumber'] === 3)) === 1, 'final count');

    $competition->openTournament((int) $owner['accountID'], $tournamentID, $now);
    $predictorAccountID = (int) $creators[8]['accountID'];
    $championPredictionID = $core->submitChampionPrediction($predictorAccountID, $tournamentID, $participantIDs[1], $now);
    $assert($championPredictionID > 0, 'champion pick accepted');

    $matchPickSubmitted = false;
    for ($round = 1; $round <= 3; $round++) {
        $roundMatches = array_values(array_filter($view->matches($tournamentID), static fn(array $match): bool => (int) $match['roundNumber'] === $round));
        foreach ($roundMatches as $match) {
            $matchID = (int) $match['matchID'];
            $assert((int) ($match['participant1ID'] ?? 0) > 0 && (int) ($match['participant2ID'] ?? 0) > 0, 'advanced match slots filled');
            $competition->configureMatchEntries((int) $owner['accountID'], $matchID, new MatchEntries((int) $levelID, (int) $levelID), $now + $round);
            $competition->openMatch((int) $owner['accountID'], $matchID, 600, $now + $round);
            if (!$matchPickSubmitted && (int) $match['participant1ID'] === $participantIDs[1]) {
                $pickID = $core->submitMatchPrediction($predictorAccountID, $matchID, $participantIDs[1], $now + $round + 1);
                $assert($pickID > 0, 'match pick accepted');
                $matchPickSubmitted = true;
            }
            $competition->startJudging((int) $owner['accountID'], $matchID, $now + $round + 2);
            $competition->resolveMatch((int) $owner['accountID'], $matchID, (int) $match['participant1ID'], $now + $round + 3);
        }
    }

    $finished = $view->tournament($tournamentID);
    $assert(is_array($finished) && (string) $finished['status'] === 'completed', 'tournament completes after final');
    $assert((int) ($finished['winnerParticipantID'] ?? 0) === $participantIDs[1], 'seed one becomes champion in deterministic fixture');

    $leaderboard = $view->leaderboard($tournamentID, 10);
    $predictorRow = null;
    foreach ($leaderboard as $row) { if ((int) $row['accountID'] === $predictorAccountID) { $predictorRow = $row; break; } }
    $assert(is_array($predictorRow), 'predictor appears in leaderboard');
    $assert((int) ($predictorRow['points'] ?? 0) === 9, 'match plus champion pick points total');

    $unclaimed = $view->unclaimedRewards($tournamentID, $predictorAccountID);
    $assert(count($unclaimed) >= 1, 'correct picks expose claimable rewards');
    $claimed = $core->claimPredictionReward($predictorAccountID, $championPredictionID, $now + 100);
    $assert(is_array($claimed), 'champion reward can be claimed');
    $assert(($claimed['reward']['badge'] ?? '') === 'major-champion-pick', 'champion reward payload preserved');
    $assert($core->claimPredictionReward($predictorAccountID, $championPredictionID, $now + 101) === null, 'champion reward cannot be claimed twice');
    $assert(count($view->audit($tournamentID, 200)) >= 10, 'tournament lifecycle writes audit trail');
} catch (Throwable $error) {
    $failures[] = 'exception: ' . $error->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, 'TOURNAMENT SYSTEM TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo "Night Core tournament system test: OK\n";
