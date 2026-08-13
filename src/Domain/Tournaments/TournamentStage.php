<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;

final class TournamentStage
{
    public static function keyForRoundSize(int $roundSize): string
    {
        return match ($roundSize) {
            2 => 'final',
            4 => 'semifinal',
            8 => 'quarterfinal',
            16 => 'round_of_16',
            32 => 'round_of_32',
            default => throw new InvalidArgumentException('Unsupported tournament round size.'),
        };
    }

    public static function label(string $stage): string
    {
        return match ($stage) {
            'final' => 'Final',
            'semifinal' => 'Semifinals',
            'quarterfinal' => 'Quarterfinals',
            'round_of_16' => 'Round of 16',
            'round_of_32' => 'Round of 32',
            default => ucwords(str_replace(['_', '-'], ' ', $stage)),
        };
    }
}
