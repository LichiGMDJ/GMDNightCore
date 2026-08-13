<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use InvalidArgumentException;

final class BracketSettings
{
    private int $size;
    private int $firstRoundOpensAt;
    private int $firstRoundLocksAt;

    public function __construct(int $size, int $firstRoundOpensAt = 0, int $firstRoundLocksAt = 0)
    {
        if (!in_array($size, [4, 8, 16, 32], true)) {
            throw new InvalidArgumentException('Single-elimination bracket size must be 4, 8, 16 or 32.');
        }
        if ($firstRoundOpensAt < 0 || $firstRoundLocksAt < 0) {
            throw new InvalidArgumentException('Bracket prediction timestamps cannot be negative.');
        }
        if ($firstRoundOpensAt > 0 && $firstRoundLocksAt > 0 && $firstRoundLocksAt <= $firstRoundOpensAt) {
            throw new InvalidArgumentException('First-round prediction lock must be after opening time.');
        }
        $this->size = $size;
        $this->firstRoundOpensAt = $firstRoundOpensAt;
        $this->firstRoundLocksAt = $firstRoundLocksAt;
    }

    public function size(): int { return $this->size; }
    public function firstRoundOpensAt(): int { return $this->firstRoundOpensAt; }
    public function firstRoundLocksAt(): int { return $this->firstRoundLocksAt; }
}
