<?php

declare(strict_types=1);

use NightCore\Domain\Tournaments\SingleEliminationBracket;
use NightCore\Domain\Tournaments\TournamentStage;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) { $failures[] = $label; }
};
$assert(SingleEliminationBracket::seedOrder(4) === [1, 4, 2, 3], '4-player seed order');
$assert(SingleEliminationBracket::seedOrder(8) === [1, 8, 4, 5, 2, 7, 3, 6], '8-player seed order');
$assert(SingleEliminationBracket::roundSizes(16) === [16, 8, 4, 2], 'round sizes');
$assert(TournamentStage::keyForRoundSize(8) === 'quarterfinal', 'quarterfinal stage');
$assert(TournamentStage::keyForRoundSize(2) === 'final', 'final stage');
if ($failures !== []) {
    fwrite(STDERR, 'TOURNAMENT BRACKET LOGIC TEST FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
echo "Night Core tournament bracket logic test: OK\n";
