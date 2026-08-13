# Creator tournaments and Pick'em

Night Core includes an optional backend foundation for creator tournaments inspired by large competitive event brackets.
The module is intentionally separate from the stock Geometry Dash protocol: a plain 2.2 GDPS can keep it disabled without deleting tournament history.

## Feature modes

Two environment settings control the module:

```env
TOURNAMENTS_MODE=disabled
TOURNAMENT_PREDICTIONS_MODE=disabled
```

Each accepts:

- `enabled` - reads and writes are allowed;
- `read_only` - historical data remains readable, but new tournament/prediction activity is blocked;
- `disabled` - the feature is unavailable to callers.

Already stored rows are never removed by changing these flags. Reward claims that were already earned remain claimable in `read_only` mode.

## Data model

Migration `0023_creator_tournaments.sql` creates isolated tables for:

- tournaments;
- creator participants;
- bracket matches and submitted level IDs;
- champion and per-match predictions;
- resolved Pick'em points and one-time reward claims.

The tables are independent from the normal accounts/levels/progress flow, so disabling the module does not change stock GDPS behavior.

## Permissions

The migration adds:

- `tournaments.manage` - create tournaments, add participants, build the bracket, open/close stages;
- `tournaments.resolve` - resolve matches and declare a tournament champion.

Bootstrap owners continue to inherit all permissions through the existing staff access layer.

## Backend usage

```php
use NightCore\Domain\Tournaments\TournamentModule;

$service = TournamentModule::boot($app->db(), $app->tables(), $app->staffAccess());
```

The MVP supports:

1. create a tournament with prediction windows and reward definitions;
2. add creator accounts as participants;
3. create bracket matches with optional submitted level IDs;
4. open a tournament/match;
5. submit or change a champion/match pick until its lock time;
6. resolve a match and automatically score all matching Pick'em entries;
7. complete a tournament and automatically score champion picks;
8. read a Pick'em leaderboard;
9. claim the stored reward payload exactly once.

Rewards are stored as JSON snapshots, for example:

```json
{"badge":"major-2026-champion-pick","seasonXp":250}
```

The tournament module deliberately does not interpret that payload yet. A later reward adapter can map it to badges, seasonal XP, cosmetics or other optional systems without coupling tournaments to them.

## Important MVP boundary

This patch is the domain/storage foundation. It does **not** yet add a public tournament website, Geode UI, player voting, automatic single-elimination bracket advancement, or a reward adapter. Those can be layered on without changing the persisted Pick'em history.
