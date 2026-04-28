<?php
require_once 'ml_session_boot.php';
require_once 'ml_config.php';
require_once 'ml_gameplay.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

$livePdo = mlGetLivePdo();

$mlQaTables = [
    'ML_Config',
    'ML_DiscordEventLog',
    'ML_FixedRounds',
    'ML_Q1Categories',
    'ML_Q1Votes',
    'ML_Q2Answers',
    'ML_Q3Answers',
    'ML_RoundPlaylistItems',
    'ML_RoundPlaylists',
    'ML_RoundSongs',
    'ML_RoundVotes',
    'ML_RoundVoteSubmissions',
    'ML_SeasonQ2Options',
    'ML_SeasonQ3Options',
    'ML_SeasonRounds',
    'ML_SeasonRoundSlots',
    'ML_Seasons',
    'ML_Settings',
    'ML_SpotifyTokens',
    'ML_Submissions',
    'ML_Users',
    'ML_WalkmanExcluded',
];

function mlQaQuoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function mlQaTableExists(PDO $pdo, string $tableName): bool
{
    $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tableName]);
    return ((int)$stmt->fetchColumn() > 0);
}

function mlQaGetTableCount(PDO $pdo, string $tableName): ?int
{
    if (!mlQaTableExists($pdo, $tableName)) {
        return null;
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM ' . mlQaQuoteIdentifier($tableName));
    return (int)$stmt->fetchColumn();
}

function mlQaPushLiveToQa(PDO $pdo, array $tables): void
{
    $pdo->beginTransaction();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $liveTable) {
            $qaTable = 'QA_' . $liveTable;

            if (!mlQaTableExists($pdo, $qaTable)) {
                throw new RuntimeException('Missing QA table: ' . $qaTable . '. Run qa_clone_setup.sql first.');
            }

            $pdo->exec('TRUNCATE TABLE ' . mlQaQuoteIdentifier($qaTable));
            $pdo->exec('INSERT INTO ' . mlQaQuoteIdentifier($qaTable) . ' SELECT * FROM ' . mlQaQuoteIdentifier($liveTable));
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $inner) {
        }

        throw $e;
    }
}

function mlQaGetLatestRound(PDO $pdo): ?array
{
    foreach (['QA_ML_SeasonRounds', 'QA_ML_Seasons'] as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            return null;
        }
    }

    $sql = "
        SELECT sr.SeasonRoundID,
               sr.SeasonID,
               sr.RoundNumber,
               sr.Title,
               sr.RoundState,
               sr.SongsDue,
               sr.VotesDue,
               s.SeasonName,
               s.IsActive
        FROM QA_ML_SeasonRounds sr
        LEFT JOIN QA_ML_Seasons s ON sr.SeasonID = s.SeasonID
        ORDER BY sr.SeasonID DESC, sr.RoundNumber DESC, sr.SeasonRoundID DESC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    return is_array($row) ? $row : null;
}

function mlQaRollbackLatestRound(PDO $pdo): array
{
    $requiredTables = [
        'QA_ML_SeasonRounds',
        'QA_ML_Seasons',
        'QA_ML_RoundSongs',
        'QA_ML_RoundVotes',
        'QA_ML_RoundVoteSubmissions',
        'QA_ML_RoundPlaylists',
        'QA_ML_RoundPlaylistItems',
        'QA_ML_DiscordEventLog',
    ];

    foreach ($requiredTables as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            throw new RuntimeException('Missing required QA table: ' . $tableName . '. Run qa_clone_setup.sql and push live data first.');
        }
    }

    $latestRound = mlQaGetLatestRound($pdo);
    if (!$latestRound) {
        throw new RuntimeException('No QA round data was found to roll back.');
    }

    $seasonRoundId = (int)$latestRound['SeasonRoundID'];
    $seasonId = (int)$latestRound['SeasonID'];

    $pdo->beginTransaction();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $deletePlaylistItemsStmt = $pdo->prepare('DELETE FROM QA_ML_RoundPlaylistItems WHERE SeasonRoundID = ?');
        $deletePlaylistItemsStmt->execute([$seasonRoundId]);
        $deletedPlaylistItems = (int)$deletePlaylistItemsStmt->rowCount();

        $deleteVotesStmt = $pdo->prepare('DELETE FROM QA_ML_RoundVotes WHERE SeasonRoundID = ?');
        $deleteVotesStmt->execute([$seasonRoundId]);
        $deletedVotes = (int)$deleteVotesStmt->rowCount();

        $deleteVoteSubmissionsStmt = $pdo->prepare('DELETE FROM QA_ML_RoundVoteSubmissions WHERE SeasonRoundID = ?');
        $deleteVoteSubmissionsStmt->execute([$seasonRoundId]);
        $deletedVoteSubmissions = (int)$deleteVoteSubmissionsStmt->rowCount();

        $deleteDiscordStmt = $pdo->prepare('DELETE FROM QA_ML_DiscordEventLog WHERE SeasonRoundID = ?');
        $deleteDiscordStmt->execute([$seasonRoundId]);
        $deletedDiscordEvents = (int)$deleteDiscordStmt->rowCount();

        $deletePlaylistsStmt = $pdo->prepare('DELETE FROM QA_ML_RoundPlaylists WHERE SeasonRoundID = ?');
        $deletePlaylistsStmt->execute([$seasonRoundId]);
        $deletedPlaylists = (int)$deletePlaylistsStmt->rowCount();

        $deleteSongsStmt = $pdo->prepare('DELETE FROM QA_ML_RoundSongs WHERE SeasonRoundID = ?');
        $deleteSongsStmt->execute([$seasonRoundId]);
        $deletedSongs = (int)$deleteSongsStmt->rowCount();

        $resetRoundStmt = $pdo->prepare("
            UPDATE QA_ML_SeasonRounds
            SET RoundState = 'submission'
            WHERE SeasonRoundID = ?
        ");
        $resetRoundStmt->execute([$seasonRoundId]);

        $pdo->exec('UPDATE QA_ML_Seasons SET IsActive = 0');
        $activateSeasonStmt = $pdo->prepare('UPDATE QA_ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
        $activateSeasonStmt->execute([$seasonId]);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();

        return [
            'round' => $latestRound,
            'deleted_songs' => $deletedSongs,
            'deleted_votes' => $deletedVotes,
            'deleted_vote_submissions' => $deletedVoteSubmissions,
            'deleted_playlists' => $deletedPlaylists,
            'deleted_playlist_items' => $deletedPlaylistItems,
            'deleted_discord_events' => $deletedDiscordEvents,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $inner) {
        }

        throw $e;
    }
}

$message = '';
$error = '';
$info = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['qa_action']) ? trim((string)$_POST['qa_action']) : '';

    try {
        if ($action === 'push_live_to_qa') {
            mlQaPushLiveToQa($livePdo, $mlQaTables);
            $message = 'Live data was copied into all QA_ML_* tables.';
        } elseif ($action === 'rollback_latest_round') {
            if (!mlIsQaMode()) {
                throw new RuntimeException('Open QA Tools in QA mode before running a QA round rollback.');
            }

            $rollbackResult = mlQaRollbackLatestRound($livePdo);
            $round = $rollbackResult['round'];
            $message = 'QA rollback complete for ' . $round['SeasonName'] . ' / Round ' . (int)$round['RoundNumber'] . ' - ' . $round['Title'] . '.';
            $info = 'Deleted ' . (int)$rollbackResult['deleted_songs'] . ' songs, '
                . (int)$rollbackResult['deleted_votes'] . ' votes, '
                . (int)$rollbackResult['deleted_vote_submissions'] . ' vote submissions, '
                . (int)$rollbackResult['deleted_playlists'] . ' playlists, '
                . (int)$rollbackResult['deleted_playlist_items'] . ' playlist items, and '
                . (int)$rollbackResult['deleted_discord_events'] . ' Discord log entries. The round state was reset to submission and that season was set active in QA.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tableRows = [];
foreach ($mlQaTables as $liveTable) {
    $qaTable = 'QA_' . $liveTable;
    $tableRows[] = [
        'live_table' => $liveTable,
        'qa_table' => $qaTable,
        'exists' => mlQaTableExists($livePdo, $qaTable),
        'live_count' => mlQaGetTableCount($livePdo, $liveTable),
        'qa_count' => mlQaGetTableCount($livePdo, $qaTable),
    ];
}

$latestQaRound = mlQaGetLatestRound($livePdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musicball - QA Tools</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(mlAssetUrl('styles.css')) ?>">
    <?php require_once 'pwa_head.php'; ?>
</head>
<body class="<?= htmlspecialchars(mlGetThemeBodyClass()) ?>">
<?php $currentPage = 'admin'; include 'header.php'; ?>
<div class="wrapper">
    <div class="card admin-card">
        <div class="admin-page-topline admin-page-topline-compact">
            <div class="admin-page-intro">
                <h1>QA Tools</h1>
                <p style="margin:8px 0 0;opacity:.85;">Copy the current live Musicball data into the QA_ML_* tables, then launch the app in QA mode.</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">
                <a href="<?= htmlspecialchars(mlUrl('admin.php')) ?>" class="admin-back-link admin-back-link-discreet">&larr; Back to Admin</a>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="status-banner success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($info !== ''): ?>
            <div class="status-banner" style="margin-top:10px;"><?= htmlspecialchars($info) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="status-banner error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0 24px;">
            <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php')) ?>" style="margin:0;">
                <input type="hidden" name="qa_action" value="push_live_to_qa">
                <button type="submit" class="button-primary">Push Live Data to QA</button>
            </form>
            <a href="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="button-secondary">Open QA Tools in QA Mode</a>
            <a href="<?= htmlspecialchars(mlUrl('season.php?testing=qa')) ?>" class="button-secondary">Open QA App</a>
            <a href="<?= htmlspecialchars(mlUrl('season.php?testing=live')) ?>" class="button-secondary">Open Live App</a>
        </div>

        <div class="card" style="margin:0 0 24px;padding:18px;">
            <h2 style="margin-top:0;">Rollback Latest QA Round</h2>
            <?php if ($latestQaRound): ?>
                <p style="margin:8px 0 14px;opacity:.9;">
                    Latest QA round: <strong><?= htmlspecialchars((string)$latestQaRound['SeasonName']) ?></strong>
                    / Round <strong><?= (int)$latestQaRound['RoundNumber'] ?></strong>
                    - <?= htmlspecialchars((string)$latestQaRound['Title']) ?>
                    <?php if (!empty($latestQaRound['RoundState'])): ?>
                        <span style="opacity:.75;">(state: <?= htmlspecialchars((string)$latestQaRound['RoundState']) ?>)</span>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p style="margin:8px 0 14px;opacity:.9;">No QA round data found yet.</p>
            <?php endif; ?>

            <p style="margin:0 0 14px;opacity:.8;">
                This deletes all QA-only data for the single most recent round, resets that round back to submission state, and makes that round's season active in QA.
            </p>

            <?php if (!mlIsQaMode()): ?>
                <div class="status-banner" style="margin:0;">Open this page with <code>?testing=qa</code> before using round rollback.</div>
            <?php elseif ($latestQaRound): ?>
                <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php')) ?>" style="margin:0;">
                    <input type="hidden" name="qa_action" value="rollback_latest_round">
                    <button type="submit" class="button-secondary" onclick="return confirm('Roll back the most recent QA round? This only changes QA_ML_* tables.');">Roll Back Latest QA Round</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="admin-season-table-wrap">
            <table class="admin-season-table">
                <thead>
                    <tr>
                        <th>Live Table</th>
                        <th>QA Table</th>
                        <th>QA Exists</th>
                        <th>Live Rows</th>
                        <th>QA Rows</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableRows as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['live_table']) ?></td>
                            <td><?= htmlspecialchars($row['qa_table']) ?></td>
                            <td><?= $row['exists'] ? 'Yes' : 'No' ?></td>
                            <td><?= $row['live_count'] === null ? '—' : (int)$row['live_count'] ?></td>
                            <td><?= $row['qa_count'] === null ? '—' : (int)$row['qa_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:18px;opacity:.8;">
            Run <code>qa_clone_setup.sql</code> one time before using this page.
        </div>
    </div>
</div>
</body>
</html>
