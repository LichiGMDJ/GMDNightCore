<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

final class MatchEntries
{
    private ?int $participantOneLevelID;
    private ?int $participantTwoLevelID;

    public function __construct(?int $participantOneLevelID, ?int $participantTwoLevelID)
    {
        $this->participantOneLevelID = self::positiveOrNull($participantOneLevelID);
        $this->participantTwoLevelID = self::positiveOrNull($participantTwoLevelID);
    }

    public function participantOneLevelID(): ?int { return $this->participantOneLevelID; }
    public function participantTwoLevelID(): ?int { return $this->participantTwoLevelID; }

    private static function positiveOrNull(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }
}
