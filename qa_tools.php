<?php
require_once 'session_boot.php';
require_once 'config.php';
require_once __DIR__ . '/gameplay/bootstrap.php';

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

function mlQaGetEnvironmentSpecificSettingRows(PDO $pdo): array
{
    if (!mlQaTableExists($pdo, 'QA_ML_Settings')) {
        return [];
    }

    $stmt = $pdo->query("
        SELECT SettingKey, SettingValue, UpdatedAt
        FROM QA_ML_Settings
        WHERE SettingKey LIKE 'discord\\_%'
    ");

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

function mlQaRestoreEnvironmentSpecificSettingRows(PDO $pdo, array $settingRows): void
{
    if (!$settingRows || !mlQaTableExists($pdo, 'QA_ML_Settings')) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO QA_ML_Settings (SettingKey, SettingValue, UpdatedAt)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            SettingValue = VALUES(SettingValue),
            UpdatedAt = VALUES(UpdatedAt)
    ");

    foreach ($settingRows as $row) {
        if (!isset($row['SettingKey'])) {
            continue;
        }

        $stmt->execute([
            (string)$row['SettingKey'],
            array_key_exists('SettingValue', $row) ? $row['SettingValue'] : null,
            array_key_exists('UpdatedAt', $row) ? $row['UpdatedAt'] : null,
        ]);
    }
}

function mlQaPushLiveToQa(PDO $pdo, array $tables): void
{
    $qaEnvironmentSettings = mlQaGetEnvironmentSpecificSettingRows($pdo);

    $pdo->beginTransaction();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $liveTable) {
            $qaTable = 'QA_' . $liveTable;

            if (!mlQaTableExists($pdo, $qaTable)) {
                throw new RuntimeException('Missing QA table: ' . $qaTable . '. Run qa_clone_setup.sql first.');
            }

            $pdo->exec('DELETE FROM ' . mlQaQuoteIdentifier($qaTable));
			$pdo->exec('INSERT INTO ' . mlQaQuoteIdentifier($qaTable) . ' SELECT * FROM ' . mlQaQuoteIdentifier($liveTable));
        }

        mlQaRestoreEnvironmentSpecificSettingRows($pdo, $qaEnvironmentSettings);
        mlQaClearCurrentSeasonRoundId($pdo);

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
        INNER JOIN QA_ML_Seasons s ON sr.SeasonID = s.SeasonID
        WHERE s.IsActive = 1
        ORDER BY sr.RoundNumber DESC, sr.SeasonRoundID DESC
        LIMIT 1
    ";

    $stmt = $pdo->query($sql);
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    if (is_array($row)) {
        return $row;
    }

    $fallbackSql = "
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
        INNER JOIN QA_ML_Seasons s ON sr.SeasonID = s.SeasonID
        ORDER BY sr.SeasonID DESC, sr.RoundNumber DESC, sr.SeasonRoundID DESC
        LIMIT 1
    ";

    $fallbackStmt = $pdo->query($fallbackSql);
    $fallbackRow = $fallbackStmt ? $fallbackStmt->fetch(PDO::FETCH_ASSOC) : false;

    return is_array($fallbackRow) ? $fallbackRow : null;
}

function mlQaGetPreviousRound(PDO $pdo, array $currentRound): ?array
{
    foreach (['QA_ML_SeasonRounds', 'QA_ML_Seasons'] as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            return null;
        }
    }

    $seasonId = (int)$currentRound['SeasonID'];
    $roundNumber = (int)$currentRound['RoundNumber'];

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
        INNER JOIN QA_ML_Seasons s ON sr.SeasonID = s.SeasonID
        WHERE (
            sr.SeasonID = ? AND sr.RoundNumber < ?
        ) OR (
            sr.SeasonID < ?
        )
        ORDER BY sr.SeasonID DESC, sr.RoundNumber DESC, sr.SeasonRoundID DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$seasonId, $roundNumber, $seasonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function mlQaGetSettingValue(PDO $pdo, string $settingKey, $default = null)
{
    if (!mlQaTableExists($pdo, 'QA_ML_Settings')) {
        return $default;
    }

    $stmt = $pdo->prepare('SELECT SettingValue FROM QA_ML_Settings WHERE SettingKey = ? LIMIT 1');
    $stmt->execute([$settingKey]);
    $value = $stmt->fetchColumn();

    return ($value === false) ? $default : $value;
}

function mlQaSetSettingValue(PDO $pdo, string $settingKey, ?string $settingValue): void
{
    if (!mlQaTableExists($pdo, 'QA_ML_Settings')) {
        throw new RuntimeException('Missing required QA table: QA_ML_Settings. Run qa_clone_setup.sql and push live data first.');
    }

    $stmt = $pdo->prepare('
        INSERT INTO QA_ML_Settings (SettingKey, SettingValue)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ');
    $stmt->execute([$settingKey, $settingValue]);
}

function mlQaClearSettingValue(PDO $pdo, string $settingKey): void
{
    if (!mlQaTableExists($pdo, 'QA_ML_Settings')) {
        return;
    }

    $stmt = $pdo->prepare('DELETE FROM QA_ML_Settings WHERE SettingKey = ?');
    $stmt->execute([$settingKey]);
}

function mlQaSetCurrentSeasonRoundId(PDO $pdo, int $seasonRoundId): void
{
    if ($seasonRoundId <= 0) {
        mlQaClearSettingValue($pdo, 'qa_current_season_round_id');
        return;
    }

    mlQaSetSettingValue($pdo, 'qa_current_season_round_id', (string)$seasonRoundId);
}

function mlQaClearCurrentSeasonRoundId(PDO $pdo): void
{
    mlQaClearSettingValue($pdo, 'qa_current_season_round_id');
}

function mlQaGetCurrentSeasonRoundId(PDO $pdo): int
{
    $value = mlQaGetSettingValue($pdo, 'qa_current_season_round_id', '0');
    return is_numeric($value) ? (int)$value : 0;
}

function mlQaGetActiveSeasonId(PDO $pdo): int
{
    if (!mlQaTableExists($pdo, 'QA_ML_Seasons')) {
        return 0;
    }

    $stmt = $pdo->query('SELECT SeasonID FROM QA_ML_Seasons WHERE IsActive = 1 ORDER BY SeasonID DESC LIMIT 1');
    $seasonId = $stmt ? $stmt->fetchColumn() : false;

    return is_numeric($seasonId) ? (int)$seasonId : 0;
}

function mlQaGetRoundById(PDO $pdo, int $seasonRoundId): ?array
{
    if ($seasonRoundId <= 0) {
        return null;
    }

    foreach (['QA_ML_SeasonRounds', 'QA_ML_Seasons'] as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            return null;
        }
    }

    $stmt = $pdo->prepare("
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
        INNER JOIN QA_ML_Seasons s ON sr.SeasonID = s.SeasonID
        WHERE sr.SeasonRoundID = ?
        LIMIT 1
    ");
    $stmt->execute([$seasonRoundId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function mlQaGetCurrentRound(PDO $pdo): ?array
{
    $overrideSeasonRoundId = mlQaGetCurrentSeasonRoundId($pdo);
    if ($overrideSeasonRoundId > 0) {
        $overrideRound = mlQaGetRoundById($pdo, $overrideSeasonRoundId);
        $activeSeasonId = mlQaGetActiveSeasonId($pdo);

        if ($overrideRound && ($activeSeasonId <= 0 || (int)$overrideRound['SeasonID'] === $activeSeasonId)) {
            return $overrideRound;
        }

        mlQaClearCurrentSeasonRoundId($pdo);
    }

    return mlQaGetLatestRound($pdo);
}
function mlQaGetRollbackStages(): array
{
    return [
        'submission' => [
            'label' => 'Song Submission - Current Round',
            'description' => 'Deletes songs, votes, vote submissions, playlists, playlist items, and Discord log entries for the current QA round. Opens song submission again.',
        ],
        'voting' => [
            'label' => 'Voting - Current Round',
            'description' => 'Keeps songs and playlist for the current QA round, deletes vote data, and opens voting again.',
        ],
        'voting_previous' => [
            'label' => 'Voting - Previous Round',
            'description' => 'Moves the previous QA round back to voting and destructively clears the current QA round data so the app behaves as if it is back in the previous round.',
        ],
    ];
}

function mlQaGetPushForwardStages(): array
{
    return [
        'voting' => [
            'label' => 'Push Forward to Voting',
            'description' => 'Copies live song submissions and playlist data into the matching QA round, then opens voting in QA.',
        ],
        'closed' => [
            'label' => 'Push Forward to Closed / Results',
            'description' => 'Copies live song submissions, playlist data, votes, and vote submissions into the matching QA round, then closes voting in QA so results are available.',
        ],
    ];
}

function mlQaGetMatchingLiveRound(PDO $pdo, array $qaRound): ?array
{
    $seasonId = (int)($qaRound['SeasonID'] ?? 0);
    $roundNumber = (int)($qaRound['RoundNumber'] ?? 0);

    if ($seasonId <= 0 || $roundNumber <= 0) {
        return null;
    }

    foreach (['ML_SeasonRounds', 'ML_Seasons'] as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            return null;
        }
    }

    $stmt = $pdo->prepare("
        SELECT sr.SeasonRoundID,
               sr.SeasonID,
               sr.RoundNumber,
               sr.Title,
               sr.RoundState,
               sr.SongsDue,
               sr.VotesDue,
               s.SeasonName,
               s.IsActive
        FROM ML_SeasonRounds sr
        INNER JOIN ML_Seasons s ON sr.SeasonID = s.SeasonID
        WHERE sr.SeasonID = ?
          AND sr.RoundNumber = ?
        LIMIT 1
    ");
    $stmt->execute([$seasonId, $roundNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function mlQaDeleteRoundData(PDO $pdo, int $seasonRoundId, array $groups): array
{
    $deleted = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
        'discord_events' => 0,
    ];

    if (in_array('playlist_items', $groups, true)) {
        $stmt = $pdo->prepare('DELETE FROM QA_ML_RoundPlaylistItems WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $deleted['playlist_items'] = (int)$stmt->rowCount();
    }

    if (in_array('votes', $groups, true)) {
        $stmt = $pdo->prepare('DELETE FROM QA_ML_RoundVotes WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $deleted['votes'] = (int)$stmt->rowCount();
    }

    if (in_array('vote_submissions', $groups, true)) {
        $stmt = $pdo->prepare('DELETE FROM QA_ML_RoundVoteSubmissions WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $deleted['vote_submissions'] = (int)$stmt->rowCount();
    }

    if (in_array('discord_events', $groups, true)) {
        $stmt = $pdo->prepare('DELETE FROM QA_ML_DiscordEventLog WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $deleted['discord_events'] = (int)$stmt->rowCount();
    }

    if (in_array('playlists', $groups, true)) {
        $stmt = $pdo->prepare('DELETE FROM QA_ML_RoundPlaylists WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $deleted['playlists'] = (int)$stmt->rowCount();
    }

    if (in_array('songs', $groups, true)) {
        $stmt = $pdo->prepare('DELETE FROM QA_ML_RoundSongs WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $deleted['songs'] = (int)$stmt->rowCount();
    }

    return $deleted;
}

function mlQaAddCounts(array $base, array $add): array
{
    foreach ($add as $key => $value) {
        if (!isset($base[$key])) {
            $base[$key] = 0;
        }
        $base[$key] += (int)$value;
    }

    return $base;
}

function mlQaCopyRoundRows(PDO $pdo, string $liveTable, string $qaTable, int $seasonRoundId): int
{
    if (!mlQaTableExists($pdo, $liveTable) || !mlQaTableExists($pdo, $qaTable)) {
        throw new RuntimeException('Missing table needed for QA push forward: ' . $liveTable . ' / ' . $qaTable . '.');
    }

    $sql = 'INSERT INTO ' . mlQaQuoteIdentifier($qaTable)
        . ' SELECT * FROM ' . mlQaQuoteIdentifier($liveTable)
        . ' WHERE SeasonRoundID = ?';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$seasonRoundId]);

    return (int)$stmt->rowCount();
}

function mlQaAssertMatchingRoundIds(array $qaRound, array $liveRound): void
{
    $qaSeasonRoundId = (int)($qaRound['SeasonRoundID'] ?? 0);
    $liveSeasonRoundId = (int)($liveRound['SeasonRoundID'] ?? 0);

    if ($qaSeasonRoundId !== $liveSeasonRoundId) {
        throw new RuntimeException('QA push forward expects matching live/QA SeasonRoundID values. Push Live Data to QA first, then try again.');
    }
}

function mlQaRollbackLatestRoundToStage(PDO $pdo, string $targetStage): array
{
    $stages = mlQaGetRollbackStages();
    if (!isset($stages[$targetStage])) {
        throw new RuntimeException('Invalid QA rollback stage.');
    }

    $requiredTables = [
        'QA_ML_SeasonRounds',
        'QA_ML_Seasons',
        'QA_ML_RoundSongs',
        'QA_ML_RoundVotes',
        'QA_ML_RoundVoteSubmissions',
        'QA_ML_RoundPlaylists',
        'QA_ML_RoundPlaylistItems',
        'QA_ML_DiscordEventLog',
        'QA_ML_Settings',
    ];

    foreach ($requiredTables as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            throw new RuntimeException('Missing required QA table: ' . $tableName . '. Run qa_clone_setup.sql and push live data first.');
        }
    }

    $currentRound = mlQaGetCurrentRound($pdo);
    if (!$currentRound) {
        throw new RuntimeException('No QA round data was found to roll back.');
    }

    $targetRound = $currentRound;
    $latestRoundTouched = false;

    if ($targetStage === 'voting_previous') {
        $previousRound = mlQaGetPreviousRound($pdo, $currentRound);
        if (!$previousRound) {
            throw new RuntimeException('No previous QA round was found to move back to voting.');
        }

        $targetRound = $previousRound;
        $latestRoundTouched = true;
    }

    $seasonRoundId = (int)$targetRound['SeasonRoundID'];
    $seasonId = (int)$targetRound['SeasonID'];

    $deleted = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
        'discord_events' => 0,
    ];

    $pdo->beginTransaction();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        if ($targetStage === 'submission') {
            $deleted = mlQaAddCounts($deleted, mlQaDeleteRoundData($pdo, $seasonRoundId, [
                'playlist_items',
                'votes',
                'vote_submissions',
                'discord_events',
                'playlists',
                'songs',
            ]));

            $updateRoundStmt = $pdo->prepare("
                UPDATE QA_ML_SeasonRounds
                SET RoundState = 'submission',
                    SongsDue = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY),
                    VotesDue = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY)
                WHERE SeasonRoundID = ?
            ");
            $updateRoundStmt->execute([$seasonRoundId]);
        } elseif ($targetStage === 'voting' || $targetStage === 'voting_previous') {
            $playlistCountStmt = $pdo->prepare('SELECT COUNT(*) FROM QA_ML_RoundPlaylists WHERE SeasonRoundID = ?');
            $playlistCountStmt->execute([$seasonRoundId]);
            if ((int)$playlistCountStmt->fetchColumn() <= 0) {
                throw new RuntimeException('This QA round does not have a playlist. Push live data again or push forward to voting before rolling back to voting.');
            }

            $deleted = mlQaAddCounts($deleted, mlQaDeleteRoundData($pdo, $seasonRoundId, [
                'votes',
                'vote_submissions',
                'discord_events',
            ]));

            $updateRoundStmt = $pdo->prepare("
                UPDATE QA_ML_SeasonRounds
                SET RoundState = 'voting',
                    SongsDue = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY),
                    VotesDue = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                WHERE SeasonRoundID = ?
            ");
            $updateRoundStmt->execute([$seasonRoundId]);

            if ($targetStage === 'voting_previous') {
                $currentSeasonRoundId = (int)$currentRound['SeasonRoundID'];
                $deleted = mlQaAddCounts($deleted, mlQaDeleteRoundData($pdo, $currentSeasonRoundId, [
                    'playlist_items',
                    'votes',
                    'vote_submissions',
                    'discord_events',
                    'playlists',
                    'songs',
                ]));

                $resetCurrentStmt = $pdo->prepare("
                    UPDATE QA_ML_SeasonRounds
                    SET RoundState = 'submission',
                        SongsDue = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 14 DAY),
                        VotesDue = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 21 DAY)
                    WHERE SeasonRoundID = ?
                ");
                $resetCurrentStmt->execute([$currentSeasonRoundId]);
            }
        }

        $pdo->exec('UPDATE QA_ML_Seasons SET IsActive = 0');
        $activateSeasonStmt = $pdo->prepare('UPDATE QA_ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
        $activateSeasonStmt->execute([$seasonId]);
        mlQaSetCurrentSeasonRoundId($pdo, $seasonRoundId);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();

        $targetRound['TargetStage'] = $targetStage;
        $targetRound['TargetStageLabel'] = $stages[$targetStage]['label'];

        return [
            'round' => $targetRound,
            'latest_round_touched' => $latestRoundTouched,
            'target_stage' => $targetStage,
            'target_stage_label' => $stages[$targetStage]['label'],
            'deleted_songs' => $deleted['songs'],
            'deleted_votes' => $deleted['votes'],
            'deleted_vote_submissions' => $deleted['vote_submissions'],
            'deleted_playlists' => $deleted['playlists'],
            'deleted_playlist_items' => $deleted['playlist_items'],
            'deleted_discord_events' => $deleted['discord_events'],
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
function mlQaPushCurrentRoundForwardToStage(PDO $pdo, string $targetStage): array
{
    $stages = mlQaGetPushForwardStages();
    if (!isset($stages[$targetStage])) {
        throw new RuntimeException('Invalid QA push-forward stage.');
    }

    $currentRound = mlQaGetCurrentRound($pdo);
    if (!$currentRound) {
        throw new RuntimeException('No QA round data was found to push forward.');
    }

    $liveRound = mlQaGetMatchingLiveRound($pdo, $currentRound);
    if (!$liveRound) {
        throw new RuntimeException('No matching live round was found for ' . $currentRound['SeasonName'] . ' / Round ' . (int)$currentRound['RoundNumber'] . '.');
    }

    mlQaAssertMatchingRoundIds($currentRound, $liveRound);

    $seasonRoundId = (int)$currentRound['SeasonRoundID'];
    $seasonId = (int)$currentRound['SeasonID'];

    $deleted = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
        'discord_events' => 0,
    ];
    $copied = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
    ];

    $pdo->beginTransaction();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        if ($targetStage === 'voting') {
            $deleted = mlQaAddCounts($deleted, mlQaDeleteRoundData($pdo, $seasonRoundId, [
                'playlist_items',
                'votes',
                'vote_submissions',
                'discord_events',
                'playlists',
                'songs',
            ]));

            $copied['songs'] = mlQaCopyRoundRows($pdo, 'ML_RoundSongs', 'QA_ML_RoundSongs', $seasonRoundId);
            $copied['playlists'] = mlQaCopyRoundRows($pdo, 'ML_RoundPlaylists', 'QA_ML_RoundPlaylists', $seasonRoundId);
            $copied['playlist_items'] = mlQaCopyRoundRows($pdo, 'ML_RoundPlaylistItems', 'QA_ML_RoundPlaylistItems', $seasonRoundId);

            if ($copied['songs'] <= 0) {
                throw new RuntimeException('The matching live round does not have song submissions to push into QA.');
            }
            if ($copied['playlists'] <= 0) {
                throw new RuntimeException('The matching live round does not have a playlist to push into QA. Generate the live playlist first or use rollback to song submission.');
            }

            $updateRoundStmt = $pdo->prepare("
                UPDATE QA_ML_SeasonRounds
                SET RoundState = 'voting',
                    SongsDue = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY),
                    VotesDue = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
                WHERE SeasonRoundID = ?
            ");
            $updateRoundStmt->execute([$seasonRoundId]);
        } elseif ($targetStage === 'closed') {
            $deleted = mlQaAddCounts($deleted, mlQaDeleteRoundData($pdo, $seasonRoundId, [
                'playlist_items',
                'votes',
                'vote_submissions',
                'discord_events',
                'playlists',
                'songs',
            ]));

            $copied['songs'] = mlQaCopyRoundRows($pdo, 'ML_RoundSongs', 'QA_ML_RoundSongs', $seasonRoundId);
            $copied['playlists'] = mlQaCopyRoundRows($pdo, 'ML_RoundPlaylists', 'QA_ML_RoundPlaylists', $seasonRoundId);
            $copied['playlist_items'] = mlQaCopyRoundRows($pdo, 'ML_RoundPlaylistItems', 'QA_ML_RoundPlaylistItems', $seasonRoundId);
            $copied['votes'] = mlQaCopyRoundRows($pdo, 'ML_RoundVotes', 'QA_ML_RoundVotes', $seasonRoundId);
            $copied['vote_submissions'] = mlQaCopyRoundRows($pdo, 'ML_RoundVoteSubmissions', 'QA_ML_RoundVoteSubmissions', $seasonRoundId);

            if ($copied['songs'] <= 0) {
                throw new RuntimeException('The matching live round does not have song submissions to push into QA.');
            }
            if ($copied['playlists'] <= 0) {
                throw new RuntimeException('The matching live round does not have a playlist to push into QA.');
            }

            $updateRoundStmt = $pdo->prepare("
                UPDATE QA_ML_SeasonRounds
                SET RoundState = 'closed',
                    SongsDue = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY),
                    VotesDue = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
                WHERE SeasonRoundID = ?
            ");
            $updateRoundStmt->execute([$seasonRoundId]);
        }

        $pdo->exec('UPDATE QA_ML_Seasons SET IsActive = 0');
        $activateSeasonStmt = $pdo->prepare('UPDATE QA_ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
        $activateSeasonStmt->execute([$seasonId]);

        mlQaSetCurrentSeasonRoundId($pdo, $seasonRoundId);

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();

        $currentRound['TargetStage'] = $targetStage;
        $currentRound['TargetStageLabel'] = $stages[$targetStage]['label'];

        return [
            'round' => $currentRound,
            'target_stage' => $targetStage,
            'target_stage_label' => $stages[$targetStage]['label'],
            'copied_songs' => $copied['songs'],
            'copied_votes' => $copied['votes'],
            'copied_vote_submissions' => $copied['vote_submissions'],
            'copied_playlists' => $copied['playlists'],
            'copied_playlist_items' => $copied['playlist_items'],
            'deleted_songs' => $deleted['songs'],
            'deleted_votes' => $deleted['votes'],
            'deleted_vote_submissions' => $deleted['vote_submissions'],
            'deleted_playlists' => $deleted['playlists'],
            'deleted_playlist_items' => $deleted['playlist_items'],
            'deleted_discord_events' => $deleted['discord_events'],
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
            $message = 'Live data was copied into all QA_ML_* tables. QA-specific Discord settings were preserved.';
        } elseif ($action === 'rollback_latest_round') {
            if (!mlIsQaMode()) {
                throw new RuntimeException('Open QA Tools in QA mode before running a QA rollback.');
            }

            $targetStage = isset($_POST['rollback_stage']) ? trim((string)$_POST['rollback_stage']) : 'submission';
            $rollbackResult = mlQaRollbackLatestRoundToStage($livePdo, $targetStage);
            $round = $rollbackResult['round'];
            $message = 'QA rollback complete for ' . $round['SeasonName'] . ' / Round ' . (int)$round['RoundNumber'] . ' - ' . $round['Title'] . ' to ' . $rollbackResult['target_stage_label'] . '.';
            $info = 'Deleted ' . (int)$rollbackResult['deleted_songs'] . ' songs, '
                . (int)$rollbackResult['deleted_votes'] . ' votes, '
                . (int)$rollbackResult['deleted_vote_submissions'] . ' vote submissions, '
                . (int)$rollbackResult['deleted_playlists'] . ' playlists, '
                . (int)$rollbackResult['deleted_playlist_items'] . ' playlist items, and '
                . (int)$rollbackResult['deleted_discord_events'] . ' Discord log entries. The round was moved to ' . $rollbackResult['target_stage_label'] . ' and that season was set active in QA.';
        } elseif ($action === 'push_forward_latest_round') {
            if (!mlIsQaMode()) {
                throw new RuntimeException('Open QA Tools in QA mode before pushing QA forward.');
            }

            $targetStage = isset($_POST['push_forward_stage']) ? trim((string)$_POST['push_forward_stage']) : 'voting';
            $pushResult = mlQaPushCurrentRoundForwardToStage($livePdo, $targetStage);
            $round = $pushResult['round'];
            $message = 'QA push-forward complete for ' . $round['SeasonName'] . ' / Round ' . (int)$round['RoundNumber'] . ' - ' . $round['Title'] . ' to ' . $pushResult['target_stage_label'] . '.';
            $info = 'Copied ' . (int)$pushResult['copied_songs'] . ' songs, '
                . (int)$pushResult['copied_playlists'] . ' playlists, '
                . (int)$pushResult['copied_playlist_items'] . ' playlist items, '
                . (int)$pushResult['copied_votes'] . ' votes, and '
                . (int)$pushResult['copied_vote_submissions'] . ' vote submissions from live into QA. Replaced existing QA data for that round and set that season active in QA.';
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

$qaRollbackStages = mlQaGetRollbackStages();
$qaPushForwardStages = mlQaGetPushForwardStages();
$currentQaRound = mlQaGetCurrentRound($livePdo);
$qaCurrentSeasonRoundId = mlQaGetCurrentSeasonRoundId($livePdo);
$latestQaRound = mlQaGetLatestRound($livePdo);
$previousQaRound = $currentQaRound ? mlQaGetPreviousRound($livePdo, $currentQaRound) : null;
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
            <h2 style="margin-top:0;">QA Round Stage Controls</h2>
            <?php if ($currentQaRound): ?>
                <p style="margin:8px 0 8px;opacity:.9;">
                    Current QA round: <strong><?= htmlspecialchars((string)$currentQaRound['SeasonName']) ?></strong>
                    / Round <strong><?= (int)$currentQaRound['RoundNumber'] ?></strong>
                    - <?= htmlspecialchars((string)$currentQaRound['Title']) ?>
                    <?php if (!empty($currentQaRound['RoundState'])): ?>
                        <span style="opacity:.75;">(stored state: <?= htmlspecialchars((string)$currentQaRound['RoundState']) ?>)</span>
                    <?php endif; ?>
                    <?php if ($qaCurrentSeasonRoundId > 0): ?>
                        <span style="opacity:.65;">(QA override pinned)</span>
                    <?php endif; ?>
                </p>
                <?php if ($latestQaRound && (int)$latestQaRound['SeasonRoundID'] !== (int)$currentQaRound['SeasonRoundID']): ?>
                    <p style="margin:0 0 8px;opacity:.72;">
                        Latest created QA round: <strong><?= htmlspecialchars((string)$latestQaRound['SeasonName']) ?></strong>
                        / Round <strong><?= (int)$latestQaRound['RoundNumber'] ?></strong>
                        - <?= htmlspecialchars((string)$latestQaRound['Title']) ?>
                    </p>
                <?php endif; ?>
                <?php if ($previousQaRound): ?>
                    <p style="margin:0 0 14px;opacity:.72;">
                        Previous QA round: <strong><?= htmlspecialchars((string)$previousQaRound['SeasonName']) ?></strong>
                        / Round <strong><?= (int)$previousQaRound['RoundNumber'] ?></strong>
                        - <?= htmlspecialchars((string)$previousQaRound['Title']) ?>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p style="margin:8px 0 14px;opacity:.9;">No QA round data found yet.</p>
            <?php endif; ?>

            <p style="margin:0 0 14px;opacity:.8;">
                Use rollback to destructively move QA backward, or push forward to copy live round data into QA and advance the QA stage.
            </p>

            <?php if (!mlIsQaMode()): ?>
                <div class="status-banner" style="margin:0;">Open this page with <code>?testing=qa</code> before using QA stage controls.</div>
            <?php elseif ($currentQaRound): ?>
                <div class="admin-grid" style="align-items:start;">
                    <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="admin-form-stack" style="margin:0;">
                        <input type="hidden" name="qa_action" value="rollback_latest_round">

                        <div>
                            <label class="admin-label" for="rollback_stage">Rollback target</label>
                            <select name="rollback_stage" id="rollback_stage" class="admin-input">
                                <?php foreach ($qaRollbackStages as $stageKey => $stage): ?>
                                    <option value="<?= htmlspecialchars($stageKey) ?>"><?= htmlspecialchars($stage['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="note" style="margin:0;">
                            <strong>Song Submission - Current Round:</strong> deletes songs and all downstream QA data.<br>
                            <strong>Voting - Current Round:</strong> keeps songs and playlist, deletes vote data.<br>
                            <strong>Voting - Previous Round:</strong> targets the previous QA round and clears current-round QA data.
                        </div>

                        <button type="submit" class="button-secondary" onclick="return confirm('Rollback QA to the selected stage? This may delete QA_ML_* data for the affected round.');">Rollback QA Stage</button>
                    </form>

                    <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="admin-form-stack" style="margin:0;">
                        <input type="hidden" name="qa_action" value="push_forward_latest_round">

                        <div>
                            <label class="admin-label" for="push_target_stage">Push-forward target</label>
                            <select name="push_forward_stage" id="push_target_stage" class="admin-input">
                                <?php foreach ($qaPushForwardStages as $stageKey => $stage): ?>
                                    <option value="<?= htmlspecialchars($stageKey) ?>"><?= htmlspecialchars($stage['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="note" style="margin:0;">
                            <strong>Push Forward to Voting:</strong> copies live songs and playlist data into QA.<br>
                            <strong>Push Forward to Closed / Results:</strong> copies live songs, playlist, votes, and vote submissions into QA.
                        </div>

                        <button type="submit" class="button-primary" onclick="return confirm('Push live round data into QA and move QA to the selected stage? Existing QA data for that round will be replaced.');">Push QA Forward</button>
                    </form>
                </div>
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
