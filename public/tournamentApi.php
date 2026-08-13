<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Domain\Tournaments\TournamentFeaturePolicy;
use NightCore\Domain\Tournaments\TournamentModule;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';
$system = TournamentModule::bootSystem($app->db(), $app->tables(), $app->staffAccess());

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$response = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
};

try {
    if ($system->features()->tournamentsMode() === TournamentFeaturePolicy::DISABLED) {
        $response(['ok' => false, 'error' => 'tournaments_disabled'], 404);
    }

    $tournamentID = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($tournamentID <= 0) {
        $response([
            'ok' => true,
            'tournaments' => $system->view()->tournaments(50),
        ]);
    }

    $tournament = $system->view()->tournament($tournamentID);
    if ($tournament === null) {
        $response(['ok' => false, 'error' => 'tournament_not_found'], 404);
    }

    $payload = [
        'ok' => true,
        'tournament' => $tournament,
        'participants' => $system->view()->participants($tournamentID),
        'matches' => $system->view()->matches($tournamentID),
    ];
    if ($system->features()->predictionsMode() !== TournamentFeaturePolicy::DISABLED) {
        $payload['leaderboard'] = $system->view()->leaderboard($tournamentID, 100);
    }
    $response($payload);
} catch (Throwable $error) {
    error_log('Night Core tournament API failed: ' . $error->getMessage());
    $response(['ok' => false, 'error' => 'internal_error'], 500);
}
