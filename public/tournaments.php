<?php

declare(strict_types=1);

use NightCore\Core\AccountPolicy;
use NightCore\Core\Application;
use NightCore\Domain\Tournaments\TournamentFeaturePolicy;
use NightCore\Domain\Tournaments\TournamentModule;
use NightCore\Domain\Tournaments\TournamentStage;
use NightCore\Web\Security\PanelSecurity;
use NightCore\Web\Security\RepositoryAccountStateProvider;
use NightCore\Web\Tournaments\TournamentWebController;

$root = dirname(__DIR__);
/** @var Application $app */
$app = require $root . '/bootstrap.php';
$policy = AccountPolicy::load($root);
$session = PanelSecurity::boot(
    'nightcore_tournaments',
    'tournaments',
    $policy,
    new RepositoryAccountStateProvider($app->accountRepository()),
    false
);
$session->validate();
$system = TournamentModule::bootSystem($app->db(), $app->tables(), $app->staffAccess());
$controller = new TournamentWebController($app, $system, $session);

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$mode = $system->features()->tournamentsMode();
$predictionMode = $system->features()->predictionsMode();
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $message = $controller->handle($_POST);
        $session->validate();
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$accountID = $session->accountId();
$account = $session->account();
$canManage = $accountID > 0 && $app->staffAccess()->has($accountID, 'tournaments.manage');
$canResolve = $accountID > 0 && ($canManage || $app->staffAccess()->has($accountID, 'tournaments.resolve'));
$csrf = $session->csrfToken();
$session->sendHeaders();

$tournaments = $mode === TournamentFeaturePolicy::DISABLED ? [] : $system->view()->tournaments(50);
$selectedID = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
if ($selectedID <= 0 && $tournaments !== []) {
    $selectedID = (int) $tournaments[0]['tournamentID'];
}
$tournament = $selectedID > 0 && $mode !== TournamentFeaturePolicy::DISABLED
    ? $system->view()->tournament($selectedID)
    : null;
$participants = $tournament !== null ? $system->view()->participants($selectedID) : [];
$matches = $tournament !== null ? $system->view()->matches($selectedID) : [];
$leaderboard = $tournament !== null && $predictionMode !== TournamentFeaturePolicy::DISABLED
    ? $system->view()->leaderboard($selectedID, 100)
    : [];
$predictions = $tournament !== null && $accountID > 0 && $predictionMode !== TournamentFeaturePolicy::DISABLED
    ? $system->view()->predictionsForAccount($selectedID, $accountID)
    : [];
$unclaimedRewards = $tournament !== null && $accountID > 0 && $predictionMode !== TournamentFeaturePolicy::DISABLED
    ? $system->view()->unclaimedRewards($selectedID, $accountID)
    : [];
$audit = $tournament !== null && $canManage ? $system->view()->audit($selectedID, 60) : [];

$tab = (string) ($_GET['tab'] ?? 'overview');
$allowedTabs = ['overview', 'bracket', 'pickem', 'leaderboard', 'rewards', 'admin'];
if (!in_array($tab, $allowedTabs, true) || ($tab === 'admin' && !$canManage && !$canResolve)) {
    $tab = 'overview';
}

$predictionByMatch = [];
$championPrediction = null;
foreach ($predictions as $prediction) {
    if ((string) $prediction['kind'] === 'champion') {
        $championPrediction = $prediction;
    } else {
        $predictionByMatch[(int) $prediction['matchID']] = $prediction;
    }
}
$rounds = [];
foreach ($matches as $match) {
    $rounds[(int) $match['roundNumber']][] = $match;
}
ksort($rounds);

$statusLabel = static fn(string $status): string => match ($status) {
    'draft' => 'Draft',
    'open' => 'Pick’em Open',
    'active' => 'Live',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'scheduled' => 'Scheduled',
    'judging' => 'Judging',
    default => ucfirst($status),
};
$formatTime = static function (mixed $timestamp): string {
    $timestamp = (int) $timestamp;
    return $timestamp > 0 ? date('d.m.Y H:i', $timestamp) : '—';
};
$rewardText = static function (mixed $json): string {
    $decoded = json_decode((string) $json, true);
    if (!is_array($decoded) || $decoded === []) {
        return 'No special reward';
    }
    $parts = [];
    foreach ($decoded as $key => $value) {
        if (is_scalar($value)) {
            $parts[] = (string) $key . ': ' . (string) $value;
        }
    }
    return $parts === [] ? 'Special reward' : implode(' · ', $parts);
};
$url = static fn(int $id, string $tabName): string => 'tournaments.php?id=' . $id . '&tab=' . rawurlencode($tabName);
$defaultInput = static fn(int $offset): string => date('Y-m-d\TH:i', time() + $offset);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Night Core Creator Major</title>
<link rel="stylesheet" href="assets/tournaments.css">
</head>
<body>
<div class="sky-glow"></div>
<main class="page-shell">
    <header class="gd-header">
        <div>
            <div class="eyebrow">NIGHT CORE</div>
            <h1>Creator Major</h1>
            <p>Creator tournaments, brackets and Pick’em predictions.</p>
        </div>
        <div class="account-box">
            <?php if ($accountID > 0 && $account !== null): ?>
                <div class="account-name"><?= $escape($account['userName'] ?? ('Account #' . $accountID)) ?></div>
                <div class="account-meta">Account #<?= $accountID ?><?= $canManage ? ' · Tournament staff' : '' ?></div>
                <form method="post" class="inline-form">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="gd-button danger small" type="submit">Log out</button>
                </form>
            <?php else: ?>
                <form method="post" class="login-form">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>">
                    <input type="hidden" name="action" value="login">
                    <input name="username" maxlength="20" placeholder="Username" autocomplete="username" required>
                    <input name="password" type="password" maxlength="128" placeholder="Password" autocomplete="current-password" required>
                    <button class="gd-button green" type="submit">Sign in</button>
                </form>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($message !== ''): ?><div class="flash success"><?= $escape($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="flash error"><?= $escape($error) ?></div><?php endif; ?>

    <?php if ($mode === TournamentFeaturePolicy::DISABLED): ?>
        <section class="gd-panel centered-panel">
            <h2>Tournaments are disabled</h2>
            <p>The base Geometry Dash 2.2 server is running normally. Set <code>TOURNAMENTS_MODE=enabled</code> to expose Creator Major features again. Existing tournament data stays in the database.</p>
        </section>
    <?php else: ?>
        <section class="tournament-picker gd-panel compact-panel">
            <div class="picker-title">Tournaments</div>
            <div class="picker-list">
                <?php foreach ($tournaments as $row): ?>
                    <a class="tournament-chip <?= (int) $row['tournamentID'] === $selectedID ? 'active' : '' ?>" href="<?= $escape($url((int) $row['tournamentID'], $tab)) ?>">
                        <strong><?= $escape($row['name']) ?></strong>
                        <span><?= $escape($statusLabel((string) $row['status'])) ?> · <?= (int) $row['participantCount'] ?>/<?= (int) ($row['bracketSize'] ?: $row['participantCount']) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if ($tournaments === []): ?><span class="muted">No tournaments yet.</span><?php endif; ?>
            </div>
        </section>

        <?php if ($tournament !== null): ?>
            <section class="hero-panel gd-panel">
                <div>
                    <span class="status-pill status-<?= $escape((string) $tournament['status']) ?>"><?= $escape($statusLabel((string) $tournament['status'])) ?></span>
                    <h2><?= $escape($tournament['name']) ?></h2>
                    <p><?= nl2br($escape((string) ($tournament['description'] ?? ''))) ?></p>
                </div>
                <div class="hero-stats">
                    <div><strong><?= count($participants) ?></strong><span>Creators</span></div>
                    <div><strong><?= (int) ($tournament['bracketSize'] ?? 0) ?: '—' ?></strong><span>Bracket</span></div>
                    <div><strong><?= (int) ($tournament['matchPickPoints'] ?? 0) ?></strong><span>Match pts</span></div>
                    <div><strong><?= (int) ($tournament['championPickPoints'] ?? 0) ?></strong><span>Champion pts</span></div>
                </div>
            </section>

            <nav class="gd-tabs" aria-label="Tournament tabs">
                <?php foreach (['overview' => 'Overview', 'bracket' => 'Bracket', 'pickem' => 'Pick’em', 'leaderboard' => 'Leaderboard', 'rewards' => 'Rewards'] as $key => $label): ?>
                    <a class="gd-tab <?= $tab === $key ? 'active' : '' ?>" href="<?= $escape($url($selectedID, $key)) ?>"><?= $escape($label) ?></a>
                <?php endforeach; ?>
                <?php if ($canManage || $canResolve): ?><a class="gd-tab staff <?= $tab === 'admin' ? 'active' : '' ?>" href="<?= $escape($url($selectedID, 'admin')) ?>">Admin</a><?php endif; ?>
            </nav>

            <?php if ($tab === 'overview'): ?>
                <section class="grid-two">
                    <div class="gd-panel">
                        <h3>Major status</h3>
                        <dl class="info-list">
                            <div><dt>Pick’em opens</dt><dd><?= $escape($formatTime($tournament['predictionOpensAt'])) ?></dd></div>
                            <div><dt>Champion pick locks</dt><dd><?= $escape($formatTime($tournament['predictionLocksAt'])) ?></dd></div>
                            <div><dt>Tournament start</dt><dd><?= $escape($formatTime($tournament['startsAt'])) ?></dd></div>
                            <div><dt>Winner</dt><dd><?= $escape($tournament['winnerName'] ?? '—') ?></dd></div>
                        </dl>
                    </div>
                    <div class="gd-panel">
                        <h3>Rules</h3>
                        <div class="rules-text"><?= nl2br($escape((string) ($tournament['rulesText'] ?? 'No rules published yet.'))) ?></div>
                    </div>
                </section>
                <section class="gd-panel">
                    <h3>Creators</h3>
                    <div class="creator-grid">
                        <?php foreach ($participants as $participant): ?>
                            <div class="creator-card <?= (string) $participant['status'] === 'champion' ? 'champion' : '' ?>">
                                <span class="seed">#<?= $escape($participant['seed'] ?? '—') ?></span>
                                <strong><?= $escape($participant['displayName']) ?></strong>
                                <span><?= $escape(ucfirst((string) $participant['status'])) ?><?= $participant['placement'] !== null ? ' · Place ' . (int) $participant['placement'] : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php elseif ($tab === 'bracket'): ?>
                <section class="gd-panel bracket-panel">
                    <div class="section-heading"><div><h3>Single-elimination bracket</h3><p>Winners advance automatically to the next slot.</p></div></div>
                    <?php if ($matches === []): ?><div class="empty-state">Bracket has not been generated yet.</div><?php else: ?>
                        <div class="bracket-scroll"><div class="bracket-grid">
                            <?php foreach ($rounds as $roundMatches): ?>
                                <div class="round-column">
                                    <h4><?= $escape(TournamentStage::label((string) $roundMatches[0]['stage'])) ?></h4>
                                    <?php foreach ($roundMatches as $match): ?>
                                        <article class="match-card status-border-<?= $escape((string) $match['status']) ?>">
                                            <div class="match-head"><span>Match #<?= (int) $match['matchOrder'] ?></span><span><?= $escape($statusLabel((string) $match['status'])) ?></span></div>
                                            <?php foreach ([1, 2] as $slot):
                                                $pid = (int) ($match['participant' . $slot . 'ID'] ?? 0);
                                                $pname = (string) ($match['participant' . $slot . 'Name'] ?? 'TBD');
                                                $levelID = (int) ($match['entry' . $slot . 'LevelID'] ?? 0);
                                                $levelName = (string) ($match['entry' . $slot . 'LevelName'] ?? '');
                                            ?>
                                                <div class="match-side <?= (int) ($match['winnerParticipantID'] ?? 0) === $pid && $pid > 0 ? 'winner' : '' ?>">
                                                    <div><strong><?= $escape($pname !== '' ? $pname : 'TBD') ?></strong><?php if ($levelID > 0): ?><span>Level #<?= $levelID ?><?= $levelName !== '' ? ' · ' . $escape($levelName) : '' ?></span><?php endif; ?></div>
                                                    <?php if ($pid > 0 && $accountID > 0 && (string) $match['status'] === 'open' && $predictionMode === TournamentFeaturePolicy::ENABLED): ?>
                                                        <form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="match_pick"><input type="hidden" name="match_id" value="<?= (int) $match['matchID'] ?>"><input type="hidden" name="participant_id" value="<?= $pid ?>"><button class="pick-button <?= isset($predictionByMatch[(int) $match['matchID']]) && (int) $predictionByMatch[(int) $match['matchID']]['participantID'] === $pid ? 'selected' : '' ?>" type="submit">Pick</button></form>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="match-foot">Locks: <?= $escape($formatTime($match['locksAt'])) ?></div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div></div>
                    <?php endif; ?>
                </section>
            <?php elseif ($tab === 'pickem'): ?>
                <section class="grid-two">
                    <div class="gd-panel">
                        <h3>Champion Pick</h3>
                        <?php if ($predictionMode === TournamentFeaturePolicy::DISABLED): ?><p>Predictions are disabled.</p>
                        <?php elseif ($accountID <= 0): ?><p>Sign in with your GDPS account to make predictions.</p>
                        <?php else: ?>
                            <p class="muted">Current pick: <strong><?= $escape($championPrediction['participantName'] ?? 'none') ?></strong></p>
                            <div class="pick-list">
                                <?php foreach ($participants as $participant): if ((string) $participant['status'] === 'withdrawn') continue; ?>
                                    <form method="post" class="pick-row">
                                        <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="champion_pick"><input type="hidden" name="tournament_id" value="<?= $selectedID ?>"><input type="hidden" name="participant_id" value="<?= (int) $participant['participantID'] ?>">
                                        <span><b>#<?= $escape($participant['seed'] ?? '—') ?></b> <?= $escape($participant['displayName']) ?></span>
                                        <button class="gd-button <?= $championPrediction !== null && (int) $championPrediction['participantID'] === (int) $participant['participantID'] ? 'yellow' : 'green' ?> small" type="submit"><?= $championPrediction !== null && (int) $championPrediction['participantID'] === (int) $participant['participantID'] ? 'Selected' : 'Pick' ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="gd-panel">
                        <h3>My predictions</h3>
                        <?php if ($predictions === []): ?><p class="muted">No picks yet.</p><?php endif; ?>
                        <?php foreach ($predictions as $prediction): ?>
                            <div class="prediction-row result-<?= $escape((string) $prediction['result']) ?>"><div><strong><?= $escape((string) $prediction['participantName']) ?></strong><span><?= $prediction['kind'] === 'champion' ? 'Champion' : $escape(TournamentStage::label((string) ($prediction['stage'] ?? 'match'))) . ' #' . (int) ($prediction['matchOrder'] ?? 0) ?></span></div><div><b><?= (int) $prediction['pointsAwarded'] ?> pts</b><span><?= $escape(ucfirst((string) $prediction['result'])) ?></span></div></div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php elseif ($tab === 'leaderboard'): ?>
                <section class="gd-panel">
                    <h3>Pick’em leaderboard</h3>
                    <div class="leaderboard-table">
                        <div class="leaderboard-row header"><span>#</span><span>Player</span><span>Points</span><span>Correct</span><span>Champion</span></div>
                        <?php foreach ($leaderboard as $index => $row): ?><div class="leaderboard-row"><span><?= $index + 1 ?></span><strong><?= $escape($row['userName'] ?? ('Account #' . $row['accountID'])) ?></strong><b><?= (int) $row['points'] ?></b><span><?= (int) $row['correctPicks'] ?></span><span><?= (int) $row['championCorrect'] > 0 ? '✓' : '—' ?></span></div><?php endforeach; ?>
                    </div>
                    <?php if ($leaderboard === []): ?><div class="empty-state">No resolved predictions yet.</div><?php endif; ?>
                </section>
            <?php elseif ($tab === 'rewards'): ?>
                <section class="gd-panel">
                    <h3>Prediction rewards</h3>
                    <?php if ($accountID <= 0): ?><p>Sign in to see your rewards.</p>
                    <?php elseif ($unclaimedRewards === []): ?><div class="empty-state">No unclaimed rewards.</div>
                    <?php else: foreach ($unclaimedRewards as $reward): ?>
                        <div class="reward-card"><div><strong><?= $escape($reward['kind'] === 'champion' ? 'Champion prediction' : 'Match prediction') ?></strong><span><?= $escape($rewardText($reward['rewardJson'])) ?> · <?= (int) $reward['pointsAwarded'] ?> pts</span></div><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="claim_reward"><input type="hidden" name="prediction_id" value="<?= (int) $reward['predictionID'] ?>"><button class="gd-button yellow" type="submit">Claim</button></form></div>
                    <?php endforeach; endif; ?>
                </section>
            <?php elseif ($tab === 'admin' && ($canManage || $canResolve)): ?>
                <section class="admin-grid">
                    <?php if ($canManage): ?>
                    <div class="gd-panel admin-card">
                        <h3>Tournament controls</h3>
                        <div class="button-line">
                            <form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="open_tournament"><input type="hidden" name="tournament_id" value="<?= $selectedID ?>"><button class="gd-button green" type="submit">Open Pick’em</button></form>
                            <form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="activate_tournament"><input type="hidden" name="tournament_id" value="<?= $selectedID ?>"><button class="gd-button yellow" type="submit">Mark Live</button></form>
                        </div>
                        <hr>
                        <form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="add_participant"><input type="hidden" name="tournament_id" value="<?= $selectedID ?>"><label>Creator account<input name="account" placeholder="Username or account ID" required></label><label>Seed<input name="seed" type="number" min="1" max="32" placeholder="1"></label><button class="gd-button green" type="submit">Add creator</button></form>
                        <hr>
                        <form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="generate_bracket"><input type="hidden" name="tournament_id" value="<?= $selectedID ?>"><label>Bracket size<select name="bracket_size"><option>4</option><option>8</option><option>16</option><option>32</option></select></label><button class="gd-button blue" type="submit">Generate bracket</button></form>
                    </div>
                    <?php endif; ?>
                    <div class="gd-panel admin-card wide-card">
                        <h3>Match operations</h3>
                        <?php foreach ($matches as $match): ?>
                            <article class="admin-match">
                                <div class="admin-match-title"><strong><?= $escape(TournamentStage::label((string) $match['stage'])) ?> #<?= (int) $match['matchOrder'] ?></strong><span><?= $escape($statusLabel((string) $match['status'])) ?> · <?= $escape($match['participant1Name'] ?? 'TBD') ?> vs <?= $escape($match['participant2Name'] ?? 'TBD') ?></span></div>
                                <?php if ($canManage && (string) $match['status'] !== 'completed'): ?>
                                    <form method="post" class="mini-controls"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="set_entries"><input type="hidden" name="match_id" value="<?= (int) $match['matchID'] ?>"><input name="entry1" type="number" min="1" placeholder="P1 level ID" value="<?= (int) ($match['entry1LevelID'] ?? 0) ?: '' ?>"><input name="entry2" type="number" min="1" placeholder="P2 level ID" value="<?= (int) ($match['entry2LevelID'] ?? 0) ?: '' ?>"><button class="gd-button blue small" type="submit">Save levels</button></form>
                                    <div class="button-line"><form method="post" class="mini-controls"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="open_match"><input type="hidden" name="match_id" value="<?= (int) $match['matchID'] ?>"><input name="duration_minutes" type="number" min="1" max="10080" value="60"><button class="gd-button green small" type="submit">Open match</button></form><form method="post"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="start_judging"><input type="hidden" name="match_id" value="<?= (int) $match['matchID'] ?>"><button class="gd-button yellow small" type="submit">Judging</button></form></div>
                                <?php endif; ?>
                                <?php if ($canResolve && (string) $match['status'] !== 'completed' && (int) ($match['participant1ID'] ?? 0) > 0 && (int) ($match['participant2ID'] ?? 0) > 0): ?>
                                    <form method="post" class="resolve-line"><input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="resolve_match"><input type="hidden" name="match_id" value="<?= (int) $match['matchID'] ?>"><select name="winner_participant_id"><option value="<?= (int) $match['participant1ID'] ?>"><?= $escape($match['participant1Name']) ?></option><option value="<?= (int) $match['participant2ID'] ?>"><?= $escape($match['participant2Name']) ?></option></select><button class="gd-button danger small" type="submit">Resolve winner</button></form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canManage): ?><div class="gd-panel admin-card wide-card"><h3>Audit trail</h3><div class="audit-list"><?php foreach ($audit as $row): ?><div><b><?= $escape($row['action']) ?></b><span><?= $escape($row['actorName'] ?? ('Account #' . $row['actorAccountID'])) ?> · <?= $escape($formatTime($row['createdAt'])) ?></span></div><?php endforeach; ?></div></div><?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canManage): ?>
            <details class="gd-panel create-panel" <?= $tournaments === [] ? 'open' : '' ?>><summary>Create new Creator Major</summary>
                <form method="post" class="create-grid">
                    <input type="hidden" name="csrf" value="<?= $escape($csrf) ?>"><input type="hidden" name="action" value="create_tournament">
                    <label>Name<input name="name" maxlength="120" placeholder="Night Core Major 2026" required></label>
                    <label>Slug<input name="slug" maxlength="64" placeholder="major-2026" required></label>
                    <label class="span-two">Description<textarea name="description" maxlength="2000" rows="3" placeholder="Tournament description"></textarea></label>
                    <label class="span-two">Rules<textarea name="rules" maxlength="12000" rows="5" placeholder="How creators compete and how winners are selected"></textarea></label>
                    <label>Pick’em opens<input name="prediction_opens" type="datetime-local" value="<?= $escape($defaultInput(0)) ?>"></label>
                    <label>Champion pick locks<input name="prediction_locks" type="datetime-local" value="<?= $escape($defaultInput(86400)) ?>"></label>
                    <label>Tournament starts<input name="starts_at" type="datetime-local" value="<?= $escape($defaultInput(86400)) ?>"></label>
                    <label>Optional end<input name="ends_at" type="datetime-local"></label>
                    <label>Match pick points<input name="match_points" type="number" min="0" max="100000" value="2"></label>
                    <label>Champion pick points<input name="champion_points" type="number" min="0" max="100000" value="7"></label>
                    <label>Match reward JSON<input name="match_reward" value='{"badge":"major-match-pick"}'></label>
                    <label>Champion reward JSON<input name="champion_reward" value='{"badge":"major-champion-pick"}'></label>
                    <div class="span-two"><button class="gd-button green large" type="submit">Create tournament</button></div>
                </form>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
