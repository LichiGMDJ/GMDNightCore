<?php

declare(strict_types=1);

use NightCore\Core\Application;
use NightCore\Core\ClientIp;
use NightCore\Core\Config;
use NightCore\Domain\Tournaments\TournamentFeaturePolicy;
use NightCore\Domain\Tournaments\TournamentModule;
use NightCore\Web\Tournaments\TournamentClientApiController;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';
$system = TournamentModule::bootSystem($app->db(), $app->tables(), $app->staffAccess());

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Allow: GET, POST');

$response = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    $payload = ['apiVersion' => 1, 'serverTime' => time()] + $payload;
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
};

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    $response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    if ($system->features()->tournamentsMode() === TournamentFeaturePolicy::DISABLED) {
        $response(['ok' => false, 'error' => 'tournaments_disabled'], 404);
    }

    if ($method === 'POST') {
        $input = $_POST;
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $input = $decoded;
                }
            }
        }

        $controller = new TournamentClientApiController($app, $system);
        $result = $controller->handle(
            is_array($input) ? $input : [],
            ClientIp::detect(Config::getBool('TRUST_PROXY_HEADERS', false))
        );
        $response($result['payload'], $result['status']);
    }

    $tournamentID = isset($_GET['id']) && is_numeric($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
    if ($tournamentID <= 0) {
        $response([
            'ok' => true,
            'authenticated' => false,
            'tournaments' => $system->view()->tournaments(50),
        ]);
    }

    $tournament = $system->view()->tournament($tournamentID);
    if ($tournament === null) {
        $response(['ok' => false, 'error' => 'tournament_not_found'], 404);
    }

    $payload = [
        'ok' => true,
        'authenticated' => false,
        'tournament' => $tournament,
        'participants' => $system->view()->participants($tournamentID),
        'matches' => $system->view()->matches($tournamentID),
    ];
    if ($system->features()->predictionsMode() !== TournamentFeaturePolicy::DISABLED) {
        $payload['leaderboard'] = $system->view()->leaderboard($tournamentID, 100);
    }
    $response($payload);
} catch (InvalidArgumentException $error) {
    $response([
        'ok' => false,
        'error' => 'invalid_request',
        'message' => $error->getMessage(),
    ], 400);
} catch (RuntimeException $error) {
    $response([
        'ok' => false,
        'error' => 'action_rejected',
        'message' => $error->getMessage(),
    ], 409);
} catch (JsonException $error) {
    $response(['ok' => false, 'error' => 'invalid_json'], 400);
} catch (Throwable $error) {
    error_log('Night Core tournament API failed: ' . $error->getMessage());
    $response(['ok' => false, 'error' => 'internal_error'], 500);
}
