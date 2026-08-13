<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;

final class SingleEliminationBracket
{
    /** @return list<int> */
    public static function seedOrder(int $size): array
    {
        if (!in_array($size, [4, 8, 16, 32], true)) {
            throw new InvalidArgumentException('Bracket size must be 4, 8, 16 or 32.');
        }
        $order = [1, 2];
        for ($current = 4; $current <= $size; $current *= 2) {
            $expanded = [];
            foreach ($order as $seed) {
                $expanded[] = $seed;
                $expanded[] = $current + 1 - $seed;
            }
            $order = $expanded;
        }
        return $order;
    }

    /** @return list<int> */
    public static function roundSizes(int $size): array
    {
        if (!in_array($size, [4, 8, 16, 32], true)) {
            throw new InvalidArgumentException('Bracket size must be 4, 8, 16 or 32.');
        }
        $rounds = [];
        for ($roundSize = $size; $roundSize >= 2; $roundSize = intdiv($roundSize, 2)) {
            $rounds[] = $roundSize;
        }
        return $rounds;
    }
}
