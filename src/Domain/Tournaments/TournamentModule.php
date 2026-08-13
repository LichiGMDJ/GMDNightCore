<?php

declare(strict_types=1);

namespace NightCore\Domain\Tournaments;

use NightCore\Core\TableNames;
use NightCore\Domain\Moderation\StaffAccessService;
use PDO;

final class TournamentModule
{
    public static function boot(PDO $db, TableNames $tables, StaffAccessService $staff): TournamentService
    {
        return new TournamentService(
            new TournamentRepository($db, $tables),
            TournamentFeaturePolicy::fromEnvironment(),
            $staff
        );
    }
}
