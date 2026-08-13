# Creator tournaments and Pick'em

Night Core includes an optional Creator Major system: single-elimination creator tournaments, per-match level entries, player predictions, Pick'em points, rewards, leaderboards and a Geometry Dash-inspired web UI.

The module is isolated from the stock GD protocol. When disabled, Night Core continues to behave as a plain 2.2 GDPS and previously stored tournament history remains in the database.

## Feature modes

```env
TOURNAMENTS_MODE=enabled
TOURNAMENT_PREDICTIONS_MODE=enabled
```

Both settings accept:

- `enabled` — reads and writes are allowed;
- `read_only` — history stays available while new activity is blocked;
- `disabled` — the feature is unavailable to callers.

Switching modes never deletes tournament rows. Rewards that were already earned remain claimable in `read_only` mode.

## Storage

`0023_creator_tournaments.sql` creates isolated tournament, participant, match and prediction tables.

`0024_creator_tournament_brackets.sql` adds:

- Major description and rules;
- bracket format and size;
- round number and round size;
- links from each match to the next match/slot;
- an isolated staff audit trail.

Tournament data is not required by the standard account/level/progress flow.

## Brackets

Supported single-elimination sizes are `4`, `8`, `16` and `32` creators.

The bracket is generated automatically from seeds. When staff resolves a match, Night Core:

1. completes the current match;
2. scores its Pick'em predictions;
3. marks the losing participant as eliminated;
4. advances the winner into the correct slot of the next round;
5. crowns the champion after the final;
6. scores champion predictions and the final Pick'em leaderboard.

A bracket cannot be regenerated over existing matches.

## Matches

Staff assigns two level IDs to every match, one entry for each creator. Both participant slots and both levels must exist before a match can be opened.

Normal lifecycle:

`scheduled -> open -> judging -> completed`

While a match is `open`, players can change their prediction until `locksAt`. Once the match is resolved, its Pick'em result is final.

## Pick'em

Players can:

- predict the overall Major champion;
- predict each open match;
- change picks before lock time;
- earn configurable Pick'em points;
- inspect their own prediction history and the global leaderboard;
- claim a stored reward payload exactly once for a correct prediction.

Rewards are stored as JSON snapshots, for example:

```json
{"badge":"major-2026-champion-pick","seasonXp":250}
```

The tournament module deliberately does not mutate future XP/badge systems directly. A shared reward adapter can later map this payload to optional progression modules without coupling them to Tournaments.

## Web UI

Public page:

```text
/tournaments.php
```

Tabs:

- Overview — state, dates, rules and participants;
- Bracket — full bracket plus open-match predictions;
- Pick'em — champion prediction and personal pick history;
- Leaderboard — Pick'em ranking;
- Rewards — claimable prediction rewards;
- Admin — staff Major controls.

The page uses `public/assets/tournaments.css`. Its visual language is inspired by stock GD menus (blue headers, dimensional buttons and brown panels) without copying game assets.

Players sign in with their normal GDPS account. POST actions use the existing `PanelSecurity` session and CSRF protections.

## Public API

A read-only endpoint is available for future Geode/client integrations:

```text
/tournamentApi.php
/tournamentApi.php?id=123
```

Without `id`, it returns the Major list. With `id`, it returns tournament metadata, participants, matches and, when predictions are visible, the Pick'em leaderboard.

## Staff permissions

- `tournaments.manage` — create Majors, add creators, generate brackets, assign levels and operate stages;
- `tournaments.resolve` — resolve winners.

Bootstrap owners inherit these permissions through the existing staff access layer.

## Backend API

The minimal interface remains for compatibility:

```php
$service = TournamentModule::boot($app->db(), $app->tables(), $app->staffAccess());
```

Full-system callers use:

```php
$system = TournamentModule::bootSystem($app->db(), $app->tables(), $app->staffAccess());
$system->competition();
$system->view();
$system->core();
```

New operations use small configuration objects such as `TournamentSetup`, `BracketSettings` and `MatchEntries`, avoiding long controller/service parameter lists.

## Verification

The dedicated `Tournament system` GitHub Actions workflow checks:

1. PHP syntax across tournament backend, web controller, public endpoints and tests;
2. `enabled/read_only/disabled` behavior;
3. seed and round helpers;
4. a complete eight-creator Major against MariaDB;
5. automatic winner advancement through the final;
6. Pick'em scoring and one-time reward claims;
7. a real HTTP request to `/tournaments.php`;
8. JSON output from `/tournamentApi.php`;
9. server logs for Fatal/Parse/Uncaught errors.

A Geode-native tab and adapters that apply reward payloads to other optional progression systems remain separate integration layers; the current Creator Major can already be operated end to end through the web UI.
