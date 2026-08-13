<?php

declare(strict_types=1);

use NightCore\Domain\Tournaments\TournamentFeaturePolicy;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$enabled = new TournamentFeaturePolicy('enabled', 'enabled');
$assert($enabled->tournamentsMode() === 'enabled', 'enabled tournament mode');
$assert($enabled->predictionsMode() === 'enabled', 'enabled prediction mode');

$readOnly = new TournamentFeaturePolicy('read_only', 'read_only');
try {
    $readOnly->requireTournamentRead();
    $readOnly->requirePredictionRead();
    $readOnly->requirePredictionClaim();
} catch (Throwable $error) {
    $failures[] = 'read-only read path: ' . $error->getMessage();
}

try {
    $readOnly->requireTournamentWrite();
    $failures[] = 'read-only tournament write unexpectedly allowed';
} catch (RuntimeException) {
}

try {
    $readOnly->requirePredictionWrite();
    $failures[] = 'read-only prediction write unexpectedly allowed';
} catch (RuntimeException) {
}

$disabled = new TournamentFeaturePolicy('disabled', 'disabled');
try {
    $disabled->requireTournamentRead();
    $failures[] = 'disabled tournament read unexpectedly allowed';
} catch (RuntimeException) {
}

try {
    new TournamentFeaturePolicy('broken', 'disabled');
    $failures[] = 'invalid mode unexpectedly accepted';
} catch (InvalidArgumentException) {
}

if ($failures !== []) {
    fwrite(STDERR, 'TOURNAMENT FEATURE POLICY TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo "Night Core tournament feature policy test: OK\n";
