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
        return self::bootSystem($db, $tables, $staff)->core();
    }

    public static function bootSystem(PDO $db, TableNames $tables, StaffAccessService $staff): TournamentSystem
    {
        $features = TournamentFeaturePolicy::fromEnvironment();
        $core = new TournamentService(new TournamentRepository($db, $tables), $features, $staff);
        $bracket = new TournamentBracketRepository($db, $tables);
        $competition = new TournamentCompetitionService($core, $bracket, $features, $staff);
        return new TournamentSystem($core, $competition, new TournamentViewRepository($db, $tables), $features);
    }
}
