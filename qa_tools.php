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
    'ML_SeasonRoundOptionChoices',
    'ML_SeasonRoundOptionVotes',
    'ML_Seasons',
    'ML_Settings',
    'ML_SpotifyTokens',
    'ML_Submissions',
    'ML_Users',
    'ML_UserSeasonPlaylistPins',
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

function mlQaReplaceQaTablesFromSource(PDO $pdo, array $tables): array
{
    foreach ($tables as $liveTable) {
        $qaTable = 'QA_' . $liveTable;

        if (!mlQaTableExists($pdo, $liveTable)) {
            throw new RuntimeException('Missing source table: ' . $liveTable . '.');
        }
        if (!mlQaTableExists($pdo, $qaTable)) {
            throw new RuntimeException('Missing QA table: ' . $qaTable . '. Run qa_clone_setup.sql first.');
        }
    }

    $copied = [];
    foreach ($tables as $liveTable) {
        $qaTable = 'QA_' . $liveTable;
        $pdo->exec('DELETE FROM ' . mlQaQuoteIdentifier($qaTable));
        $inserted = $pdo->exec(
            'INSERT INTO ' . mlQaQuoteIdentifier($qaTable)
            . ' SELECT * FROM ' . mlQaQuoteIdentifier($liveTable)
        );
        $copied[$liveTable] = ($inserted === false) ? 0 : (int)$inserted;
    }

    return $copied;
}

function mlQaPushLiveToQa(PDO $pdo, array $tables): void
{
    $qaEnvironmentSettings = mlQaGetEnvironmentSpecificSettingRows($pdo);

    $pdo->beginTransaction();

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        mlQaReplaceQaTablesFromSource($pdo, $tables);

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

function mlQaGetTimeMachineStages(): array
{
    return [
        'submission' => [
            'label' => 'Song Submission',
            'description' => 'The selected round is accepting songs. Its song deadline will be 10 minutes away.',
        ],
        'voting' => [
            'label' => 'Voting',
            'description' => 'The selected round has a playlist and its voting deadline will be 10 minutes away.',
        ],
        'closed' => [
            'label' => 'Closed / Results',
            'description' => 'The selected round is closed. If another round follows it, that round\'s song deadline will be 10 minutes away.',
        ],
    ];
}

function mlQaGetVotingScenarios(): array
{
    return [
        'none' => [
            'label' => 'Nobody has voted',
            'description' => 'Voting starts with no submitted ballots.',
        ],
        'everyone_except_me' => [
            'label' => 'Everyone except me has voted',
            'description' => 'Every player except the signed-in admin has a deterministic submitted ballot.',
        ],
        'everyone' => [
            'label' => 'Everyone has voted',
            'description' => 'Every player has a deterministic submitted ballot, so results are final immediately.',
        ],
    ];
}

function mlQaGetAvailableRounds(PDO $pdo): array
{
    foreach (['ML_SeasonRounds', 'ML_Seasons'] as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            return [];
        }
    }

    $stmt = $pdo->query("
        SELECT sr.SeasonRoundID,
               sr.SeasonID,
               sr.RoundNumber,
               sr.Title,
               s.SeasonName,
               s.IsActive
        FROM ML_SeasonRounds sr
        INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
        WHERE (
            sr.VotesDue IS NOT NULL
            AND sr.VotesDue < UTC_TIMESTAMP()
        ) OR (
            s.IsActive = 1
            AND NOT EXISTS (
                SELECT 1
                FROM ML_SeasonRounds previous_sr
                WHERE previous_sr.SeasonID = sr.SeasonID
                  AND previous_sr.RoundNumber < sr.RoundNumber
                  AND (
                      previous_sr.VotesDue IS NULL
                      OR previous_sr.VotesDue >= UTC_TIMESTAMP()
                  )
            )
        )
        ORDER BY sr.SeasonID DESC, sr.RoundNumber ASC, sr.SeasonRoundID ASC
    ");

    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

function mlQaGetLiveRoundById(PDO $pdo, int $seasonRoundId): ?array
{
    if ($seasonRoundId <= 0) {
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
        INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
        WHERE sr.SeasonRoundID = ?
        LIMIT 1
    ");
    $stmt->execute([$seasonRoundId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function mlQaSourceRoundHasStarted(PDO $pdo, int $seasonRoundId): bool
{
    if ($seasonRoundId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM ML_SeasonRounds sr
        INNER JOIN ML_Seasons s ON s.SeasonID = sr.SeasonID
        WHERE sr.SeasonRoundID = ?
          AND (
              (
                  sr.VotesDue IS NOT NULL
                  AND sr.VotesDue < UTC_TIMESTAMP()
              ) OR (
                  s.IsActive = 1
                  AND NOT EXISTS (
                      SELECT 1
                      FROM ML_SeasonRounds previous_sr
                      WHERE previous_sr.SeasonID = sr.SeasonID
                        AND previous_sr.RoundNumber < sr.RoundNumber
                        AND (
                            previous_sr.VotesDue IS NULL
                            OR previous_sr.VotesDue >= UTC_TIMESTAMP()
                        )
                  )
              )
          )
        LIMIT 1
    ");
    $stmt->execute([$seasonRoundId]);

    return $stmt->fetchColumn() !== false;
}

function mlQaGetSourceRoundCounts(PDO $pdo, int $seasonRoundId): array
{
    $tables = [
        'songs' => 'ML_RoundSongs',
        'playlists' => 'ML_RoundPlaylists',
        'playlist_items' => 'ML_RoundPlaylistItems',
        'votes' => 'ML_RoundVotes',
        'vote_submissions' => 'ML_RoundVoteSubmissions',
    ];
    $counts = [];

    foreach ($tables as $key => $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            throw new RuntimeException('Missing source table: ' . $tableName . '.');
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . mlQaQuoteIdentifier($tableName) . ' WHERE SeasonRoundID = ?'
        );
        $stmt->execute([$seasonRoundId]);
        $counts[$key] = (int)$stmt->fetchColumn();
    }

    return $counts;
}

function mlQaGetLiveSeasonRounds(PDO $pdo, int $seasonId): array
{
    if ($seasonId <= 0 || !mlQaTableExists($pdo, 'ML_SeasonRounds')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT SeasonRoundID, SeasonID, RoundNumber, Title, RoundState, SongsDue, VotesDue
        FROM ML_SeasonRounds
        WHERE SeasonID = ?
        ORDER BY RoundNumber ASC, SeasonRoundID ASC
    ");
    $stmt->execute([$seasonId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function mlQaCreateUtcDate($value, string $label): DateTimeImmutable
{
    $value = trim((string)$value);
    if ($value === '') {
        throw new RuntimeException('The source schedule is missing ' . $label . '.');
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        throw new RuntimeException('The source schedule has an invalid ' . $label . '.');
    }
}

function mlQaGetDatabaseNow(PDO $pdo): DateTimeImmutable
{
    $value = $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    return mlQaCreateUtcDate($value, 'database time');
}

function mlQaFormatUtc(DateTimeImmutable $date): string
{
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function mlQaShiftUtcDate($value, int $offsetSeconds, string $label): string
{
    return mlQaFormatUtc(mlQaCreateUtcDate($value, $label)->modify(($offsetSeconds >= 0 ? '+' : '') . $offsetSeconds . ' seconds'));
}

function mlQaGetExpectedPlayerCountForTools(PDO $pdo): int
{
    global $totalPlayers;

    if (isset($totalPlayers) && (int)$totalPlayers > 0) {
        return (int)$totalPlayers;
    }

    if (!mlQaTableExists($pdo, 'QA_ML_Users')) {
        return 0;
    }

    return (int)$pdo->query('SELECT COUNT(*) FROM QA_ML_Users')->fetchColumn();
}

function mlQaDeleteSeasonGameplayData(PDO $pdo, array $rounds): array
{
    $deleted = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
        'discord_events' => 0,
    ];

    foreach ($rounds as $round) {
        $seasonRoundId = (int)($round['SeasonRoundID'] ?? 0);
        if ($seasonRoundId <= 0) {
            continue;
        }

        $deleted = mlQaAddCounts($deleted, mlQaDeleteRoundData($pdo, $seasonRoundId, [
            'playlist_items',
            'votes',
            'vote_submissions',
            'discord_events',
            'playlists',
            'songs',
        ]));
    }

    return $deleted;
}

function mlQaCopyRoundDataGroups(PDO $pdo, int $seasonRoundId, array $groups): array
{
    $copied = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
    ];

    $tables = [
        'songs' => ['ML_RoundSongs', 'QA_ML_RoundSongs'],
        'playlists' => ['ML_RoundPlaylists', 'QA_ML_RoundPlaylists'],
        'playlist_items' => ['ML_RoundPlaylistItems', 'QA_ML_RoundPlaylistItems'],
        'votes' => ['ML_RoundVotes', 'QA_ML_RoundVotes'],
        'vote_submissions' => ['ML_RoundVoteSubmissions', 'QA_ML_RoundVoteSubmissions'],
    ];

    foreach ($groups as $group) {
        if (!isset($tables[$group])) {
            continue;
        }

        $copied[$group] += mlQaCopyRoundRows($pdo, $tables[$group][0], $tables[$group][1], $seasonRoundId);
    }

    return $copied;
}

function mlQaMarkHistoricalDiscordEvents(PDO $pdo, int $seasonRoundId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO QA_ML_DiscordEventLog (SeasonRoundID, EventKey, MessageText)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE MessageText = VALUES(MessageText)
    ");

    foreach (['submission_open_qa', 'voting_open_qa', 'all_votes_in_qa', 'round_closed_qa'] as $eventKey) {
        $stmt->execute([$seasonRoundId, $eventKey, 'QA time machine: historical event already complete.']);
    }
}

function mlQaBuildDeterministicScores(array $candidateSongIds, int $totalPoints, int $maxPerSong, int $rotation): array
{
    if (!$candidateSongIds || $totalPoints <= 0 || $maxPerSong <= 0) {
        throw new RuntimeException('The QA ballot does not have enough eligible songs for the configured voting rules.');
    }

    if ((count($candidateSongIds) * $maxPerSong) < $totalPoints) {
        throw new RuntimeException('The configured voting total cannot fit on this QA ballot without exceeding the per-song maximum.');
    }

    $candidateSongIds = array_values(array_map('intval', $candidateSongIds));
    $scores = array_fill_keys($candidateSongIds, 0);
    $candidateCount = count($candidateSongIds);
    $remaining = $totalPoints;
    $cursor = $rotation % $candidateCount;

    while ($remaining > 0) {
        $assignedThisPass = 0;
        for ($index = 0; $index < $candidateCount && $remaining > 0; $index++) {
            $songId = $candidateSongIds[($cursor + $index) % $candidateCount];
            if ($scores[$songId] >= $maxPerSong) {
                continue;
            }

            $scores[$songId]++;
            $remaining--;
            $assignedThisPass++;
        }

        if ($assignedThisPass === 0) {
            throw new RuntimeException('The QA ballot generator could not assign all configured voting points.');
        }

        $cursor = ($cursor + 1) % $candidateCount;
    }

    return $scores;
}

function mlQaApplyVotingScenario(PDO $pdo, int $seasonRoundId, int $currentUserId, string $scenario): array
{
    $scenarios = mlQaGetVotingScenarios();
    if (!isset($scenarios[$scenario])) {
        throw new RuntimeException('Invalid QA voting scenario.');
    }

    $pdo->prepare('DELETE FROM QA_ML_RoundVotes WHERE SeasonRoundID = ?')->execute([$seasonRoundId]);
    $pdo->prepare('DELETE FROM QA_ML_RoundVoteSubmissions WHERE SeasonRoundID = ?')->execute([$seasonRoundId]);

    if ($scenario === 'none') {
        return ['submitted' => 0, 'expected' => mlQaGetExpectedPlayerCountForTools($pdo)];
    }

    $users = $pdo->query('SELECT UserID FROM QA_ML_Users ORDER BY UserID ASC')->fetchAll(PDO::FETCH_COLUMN);
    $userIds = array_values(array_map('intval', is_array($users) ? $users : []));
    $expectedPlayers = mlQaGetExpectedPlayerCountForTools($pdo);

    if ($expectedPlayers <= 0 || count($userIds) !== $expectedPlayers) {
        throw new RuntimeException(
            'QA has ' . count($userIds) . ' users, but gameplay expects ' . $expectedPlayers
            . '. Align the expected-player count before generating voting scenarios.'
        );
    }

    if (!in_array($currentUserId, $userIds, true)) {
        throw new RuntimeException('The signed-in admin is not present in QA_ML_Users and cannot be the remaining voter.');
    }

    $songStmt = $pdo->prepare('SELECT RoundSongID, UserID FROM QA_ML_RoundSongs WHERE SeasonRoundID = ? ORDER BY RoundSongID ASC');
    $songStmt->execute([$seasonRoundId]);
    $songs = $songStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$songs) {
        throw new RuntimeException('The selected QA round has no songs for generated ballots.');
    }

    $totalPoints = max(1, (int)mlQaGetSettingValue($pdo, 'votes_per_round', '12'));
    $configuredMax = (int)mlQaGetSettingValue($pdo, 'vote_max_per_song', '0');
    $maxPerSong = $configuredMax > 0 ? min($configuredMax, $totalPoints, 10) : min($totalPoints, 10);

    $insertVoteStmt = $pdo->prepare("
        INSERT INTO QA_ML_RoundVotes (SeasonRoundID, VoterUserID, RoundSongID, Score, Comment)
        VALUES (?, ?, ?, ?, '')
    ");
    $insertSubmissionStmt = $pdo->prepare("
        INSERT INTO QA_ML_RoundVoteSubmissions (SeasonRoundID, UserID)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE SubmittedAt = CURRENT_TIMESTAMP
    ");

    $submitted = 0;
    foreach ($userIds as $userIndex => $voterUserId) {
        if ($scenario === 'everyone_except_me' && $voterUserId === $currentUserId) {
            continue;
        }

        $candidateSongIds = [];
        foreach ($songs as $song) {
            if ((int)$song['UserID'] !== $voterUserId) {
                $candidateSongIds[] = (int)$song['RoundSongID'];
            }
        }

        $scores = mlQaBuildDeterministicScores($candidateSongIds, $totalPoints, $maxPerSong, $userIndex);
        foreach ($candidateSongIds as $roundSongId) {
            $insertVoteStmt->execute([
                $seasonRoundId,
                $voterUserId,
                $roundSongId,
                (int)($scores[$roundSongId] ?? 0),
            ]);
        }

        $insertSubmissionStmt->execute([$seasonRoundId, $voterUserId]);
        $submitted++;
    }

    return ['submitted' => $submitted, 'expected' => $expectedPlayers];
}

function mlQaApplyTimeMachine(
    PDO $pdo,
    int $seasonRoundId,
    string $targetStage,
    string $votingScenario,
    int $currentUserId,
    array $tables
): array
{
    $stages = mlQaGetTimeMachineStages();
    if (!isset($stages[$targetStage])) {
        throw new RuntimeException('Invalid QA time-machine stage.');
    }

    $requiredTables = [
        'ML_SeasonRounds',
        'ML_RoundSongs',
        'ML_RoundVotes',
        'ML_RoundVoteSubmissions',
        'ML_RoundPlaylists',
        'ML_RoundPlaylistItems',
        'QA_ML_SeasonRounds',
        'QA_ML_Seasons',
        'QA_ML_RoundSongs',
        'QA_ML_RoundVotes',
        'QA_ML_RoundVoteSubmissions',
        'QA_ML_RoundPlaylists',
        'QA_ML_RoundPlaylistItems',
        'QA_ML_DiscordEventLog',
        'QA_ML_Settings',
        'QA_ML_Users',
    ];

    foreach ($requiredTables as $tableName) {
        if (!mlQaTableExists($pdo, $tableName)) {
            throw new RuntimeException('Missing required table: ' . $tableName . '. Run qa_clone_setup.sql and push live data first.');
        }
    }

    $liveRound = mlQaGetLiveRoundById($pdo, $seasonRoundId);
    if (!$liveRound) {
        throw new RuntimeException('The selected round was not found in this environment\'s ML_* source snapshot.');
    }
    if (!mlQaSourceRoundHasStarted($pdo, $seasonRoundId)) {
        throw new RuntimeException('The selected source round has not started yet and cannot be used by the QA time machine.');
    }

    $seasonId = (int)$liveRound['SeasonID'];
    $targetRoundNumber = (int)$liveRound['RoundNumber'];
    $sourceCounts = mlQaGetSourceRoundCounts($pdo, $seasonRoundId);
    if (
        in_array($targetStage, ['voting', 'closed'], true)
        && ($sourceCounts['songs'] <= 0 || $sourceCounts['playlists'] <= 0)
    ) {
        throw new RuntimeException(
            'Source Season ' . $seasonId . ' / Round ' . $targetRoundNumber
            . ' has ' . $sourceCounts['songs'] . ' song rows and '
            . $sourceCounts['playlists'] . ' playlist rows in this environment\'s ML_* tables. '
            . ucfirst($targetStage) . ' requires both. If this source snapshot is stale, refresh musicball_future '
            . 'from the musicball database, then try again. No QA data was changed.'
        );
    }

    $sourceRounds = mlQaGetLiveSeasonRounds($pdo, $seasonId);
    if (!$sourceRounds) {
        throw new RuntimeException('The selected season does not have a source schedule to rebase.');
    }

    $targetIndex = null;
    foreach ($sourceRounds as $index => $sourceRound) {
        if ((int)$sourceRound['SeasonRoundID'] === $seasonRoundId) {
            $targetIndex = $index;
            break;
        }
    }
    if ($targetIndex === null) {
        throw new RuntimeException('The selected round is missing from the source season schedule.');
    }

    $pinnedSeasonRoundId = $seasonRoundId;
    if ($targetStage === 'closed' && isset($sourceRounds[$targetIndex + 1])) {
        $pinnedSeasonRoundId = (int)$sourceRounds[$targetIndex + 1]['SeasonRoundID'];
    }

    $databaseNow = mlQaGetDatabaseNow($pdo);
    $desiredNextDeadline = $databaseNow->modify('+10 minutes');
    $sourceAnchor = null;
    $anchorLabel = '';

    if ($targetStage === 'submission') {
        $sourceAnchor = mlQaCreateUtcDate($sourceRounds[$targetIndex]['SongsDue'] ?? null, 'song deadline for the selected round');
        $anchorLabel = 'song deadline';
    } elseif ($targetStage === 'voting') {
        $sourceAnchor = mlQaCreateUtcDate($sourceRounds[$targetIndex]['VotesDue'] ?? null, 'voting deadline for the selected round');
        $anchorLabel = 'voting deadline';
    } elseif (isset($sourceRounds[$targetIndex + 1])) {
        $sourceAnchor = mlQaCreateUtcDate($sourceRounds[$targetIndex + 1]['SongsDue'] ?? null, 'song deadline for the next round');
        $anchorLabel = 'next round song deadline';
    } else {
        $sourceAnchor = mlQaCreateUtcDate($sourceRounds[$targetIndex]['VotesDue'] ?? null, 'voting deadline for the final round');
        $desiredNextDeadline = $databaseNow->modify('-10 minutes');
        $anchorLabel = 'final voting deadline';
    }

    $offsetSeconds = $desiredNextDeadline->getTimestamp() - $sourceAnchor->getTimestamp();
    $rebasedRounds = [];
    foreach ($sourceRounds as $sourceRound) {
        $roundId = (int)$sourceRound['SeasonRoundID'];
        $roundNumber = (int)$sourceRound['RoundNumber'];
        $rebasedRounds[$roundId] = [
            'SeasonRoundID' => $roundId,
            'RoundNumber' => $roundNumber,
            'SongsDue' => mlQaShiftUtcDate($sourceRound['SongsDue'] ?? null, $offsetSeconds, 'song deadline for Round ' . $roundNumber),
            'VotesDue' => mlQaShiftUtcDate($sourceRound['VotesDue'] ?? null, $offsetSeconds, 'voting deadline for Round ' . $roundNumber),
            'RoundState' => $roundNumber < $targetRoundNumber
                ? 'closed'
                : ($roundNumber === $targetRoundNumber ? $targetStage : 'submission'),
        ];
    }

    $rebasedTarget = $rebasedRounds[$seasonRoundId];
    $rebasedSongsDue = mlQaCreateUtcDate($rebasedTarget['SongsDue'], 'rebased song deadline');
    $rebasedVotesDue = mlQaCreateUtcDate($rebasedTarget['VotesDue'], 'rebased voting deadline');
    if ($targetStage === 'submission' && $rebasedSongsDue <= $databaseNow) {
        throw new RuntimeException('The source schedule cannot place this round in song submission with ten minutes remaining.');
    }
    if ($targetStage === 'voting' && ($rebasedSongsDue >= $databaseNow || $rebasedVotesDue <= $databaseNow)) {
        throw new RuntimeException('The source schedule cannot place this round in voting with ten minutes remaining.');
    }
    if ($targetStage === 'closed' && $rebasedVotesDue >= $databaseNow) {
        throw new RuntimeException('The source schedule cannot close this round while keeping the next deadline ten minutes away.');
    }

    $deleted = [];
    $copied = [
        'songs' => 0,
        'votes' => 0,
        'vote_submissions' => 0,
        'playlists' => 0,
        'playlist_items' => 0,
    ];
    $participation = ['submitted' => 0, 'expected' => 0];
    $sourceResetCounts = [];
    $qaEnvironmentSettings = mlQaGetEnvironmentSpecificSettingRows($pdo);

    $pdo->beginTransaction();
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $sourceResetCounts = mlQaReplaceQaTablesFromSource($pdo, $tables);
        mlQaRestoreEnvironmentSpecificSettingRows($pdo, $qaEnvironmentSettings);
        $participation['expected'] = mlQaGetExpectedPlayerCountForTools($pdo);

        $deleted = mlQaDeleteSeasonGameplayData($pdo, $sourceRounds);

        $updateRoundStmt = $pdo->prepare("
            UPDATE QA_ML_SeasonRounds
            SET RoundState = ?, SongsDue = ?, VotesDue = ?
            WHERE SeasonRoundID = ? AND SeasonID = ?
        ");
        foreach ($rebasedRounds as $roundId => $rebasedRound) {
            $updateRoundStmt->execute([
                $rebasedRound['RoundState'],
                $rebasedRound['SongsDue'],
                $rebasedRound['VotesDue'],
                $roundId,
                $seasonId,
            ]);
            if ($updateRoundStmt->rowCount() === 0 && !mlQaGetRoundById($pdo, $roundId)) {
                throw new RuntimeException('A QA round is missing from the selected season. Push live data to QA and try again.');
            }
        }

        foreach ($sourceRounds as $sourceRound) {
            $roundId = (int)$sourceRound['SeasonRoundID'];
            $roundNumber = (int)$sourceRound['RoundNumber'];
            if ($roundNumber >= $targetRoundNumber) {
                continue;
            }

            $roundCopied = mlQaCopyRoundDataGroups($pdo, $roundId, [
                'songs', 'playlists', 'playlist_items', 'votes', 'vote_submissions',
            ]);
            $copied = mlQaAddCounts($copied, $roundCopied);
            mlQaMarkHistoricalDiscordEvents($pdo, $roundId);
        }

        if ($targetStage === 'voting') {
            $targetCopied = mlQaCopyRoundDataGroups($pdo, $seasonRoundId, ['songs', 'playlists', 'playlist_items']);
            $copied = mlQaAddCounts($copied, $targetCopied);
            if ($targetCopied['songs'] <= 0 || $targetCopied['playlists'] <= 0) {
                throw new RuntimeException(
                    'Source preflight found ' . $sourceCounts['songs'] . ' song rows and '
                    . $sourceCounts['playlists'] . ' playlist rows, but the QA copy produced '
                    . $targetCopied['songs'] . ' songs and ' . $targetCopied['playlists']
                    . ' playlists. All QA changes were rolled back.'
                );
            }
            $participation = mlQaApplyVotingScenario($pdo, $seasonRoundId, $currentUserId, $votingScenario);
        } elseif ($targetStage === 'closed') {
            $targetCopied = mlQaCopyRoundDataGroups($pdo, $seasonRoundId, [
                'songs', 'playlists', 'playlist_items', 'votes', 'vote_submissions',
            ]);
            $copied = mlQaAddCounts($copied, $targetCopied);
            if ($targetCopied['songs'] <= 0 || $targetCopied['playlists'] <= 0) {
                throw new RuntimeException(
                    'Source preflight found ' . $sourceCounts['songs'] . ' song rows and '
                    . $sourceCounts['playlists'] . ' playlist rows, but the QA copy produced '
                    . $targetCopied['songs'] . ' songs and ' . $targetCopied['playlists']
                    . ' playlists. All QA changes were rolled back.'
                );
            }
            $participation['submitted'] = $targetCopied['vote_submissions'];
        }

        $pdo->exec('UPDATE QA_ML_Seasons SET IsActive = 0');
        $activateSeasonStmt = $pdo->prepare('UPDATE QA_ML_Seasons SET IsActive = 1 WHERE SeasonID = ?');
        $activateSeasonStmt->execute([$seasonId]);
        mlQaSetCurrentSeasonRoundId($pdo, $pinnedSeasonRoundId);
        mlQaSetSettingValue($pdo, 'qa_time_machine_stage', $targetStage);
        mlQaSetSettingValue($pdo, 'qa_time_machine_anchor', mlQaFormatUtc($desiredNextDeadline));

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

    if (isset($_SESSION['ml_round_votes'][$currentUserId])) {
        unset($_SESSION['ml_round_votes'][$currentUserId]);
    }

    return [
        'round' => $liveRound,
        'stage' => $targetStage,
        'stage_label' => $stages[$targetStage]['label'],
        'voting_scenario' => $targetStage === 'voting' ? $votingScenario : '',
        'deadline_label' => $anchorLabel,
        'deadline_utc' => mlQaFormatUtc($desiredNextDeadline),
        'pinned_season_round_id' => $pinnedSeasonRoundId,
        'offset_seconds' => $offsetSeconds,
        'deleted' => $deleted,
        'copied' => $copied,
        'source_counts' => $sourceCounts,
        'source_reset_counts' => $sourceResetCounts,
        'submitted' => (int)$participation['submitted'],
        'expected' => (int)$participation['expected'],
    ];
}

function mlQaGetRoundScenarioStatus(PDO $pdo, ?array $round, int $currentUserId): array
{
    if (!$round) {
        return [];
    }

    $seasonRoundId = (int)$round['SeasonRoundID'];
    $stage = (string)($round['RoundState'] ?? '');
    $deadlineValue = '';
    $deadlineLabel = '';

    if ($stage === 'submission') {
        $deadlineValue = (string)($round['SongsDue'] ?? '');
        $deadlineLabel = 'Song deadline';
    } elseif ($stage === 'voting') {
        $deadlineValue = (string)($round['VotesDue'] ?? '');
        $deadlineLabel = 'Voting deadline';
    } elseif ($stage === 'closed') {
        $nextStmt = $pdo->prepare("
            SELECT SongsDue
            FROM QA_ML_SeasonRounds
            WHERE SeasonID = ? AND RoundNumber > ?
            ORDER BY RoundNumber ASC, SeasonRoundID ASC
            LIMIT 1
        ");
        $nextStmt->execute([(int)$round['SeasonID'], (int)$round['RoundNumber']]);
        $nextDeadline = $nextStmt->fetchColumn();
        if ($nextDeadline !== false) {
            $deadlineValue = (string)$nextDeadline;
            $deadlineLabel = 'Next round song deadline';
        } else {
            $deadlineValue = (string)($round['VotesDue'] ?? '');
            $deadlineLabel = 'Final voting deadline';
        }
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM QA_ML_RoundVoteSubmissions WHERE SeasonRoundID = ?');
    $countStmt->execute([$seasonRoundId]);
    $submitted = (int)$countStmt->fetchColumn();

    $meStmt = $pdo->prepare('SELECT 1 FROM QA_ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ? LIMIT 1');
    $meStmt->execute([$seasonRoundId, $currentUserId]);

    return [
        'stage' => $stage,
        'deadline_label' => $deadlineLabel,
        'deadline_utc' => $deadlineValue,
        'submitted' => $submitted,
        'expected' => mlQaGetExpectedPlayerCountForTools($pdo),
        'current_user_submitted' => (bool)$meStmt->fetchColumn(),
    ];
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
$selectedTimeMachineRoundId = isset($_POST['time_machine_round_id']) ? (int)$_POST['time_machine_round_id'] : 0;
$selectedTimeMachineStage = isset($_POST['time_machine_stage']) ? trim((string)$_POST['time_machine_stage']) : 'voting';
$selectedVotingScenario = isset($_POST['voting_scenario']) ? trim((string)$_POST['voting_scenario']) : 'everyone_except_me';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['qa_action']) ? trim((string)$_POST['qa_action']) : '';

    try {
        if ($action === 'push_live_to_qa') {
            mlQaPushLiveToQa($livePdo, $mlQaTables);
            $message = 'This environment\'s ML_* snapshot was copied into all configured QA_ML_* mirror tables. QA-specific Discord settings were preserved.';
        } elseif ($action === 'apply_time_machine') {
            if (!mlIsQaMode()) {
                throw new RuntimeException('Open QA Tools in QA mode before using the QA time machine.');
            }

            $timeMachineResult = mlQaApplyTimeMachine(
                $livePdo,
                $selectedTimeMachineRoundId,
                $selectedTimeMachineStage,
                $selectedVotingScenario,
                $currentUserId,
                $mlQaTables
            );
            $round = $timeMachineResult['round'];
            $message = 'QA time machine rebuilt ' . $round['SeasonName']
                . ' / Round ' . (int)$round['RoundNumber'] . ' - ' . $round['Title']
                . ' at ' . $timeMachineResult['stage_label'] . '.';
            $info = ucfirst((string)$timeMachineResult['deadline_label']) . ': '
                . $timeMachineResult['deadline_utc'] . ' UTC. '
                . 'QA was reset from this environment\'s ML_* snapshot before the timeline was rebuilt. ';

            if ($timeMachineResult['stage'] === 'voting') {
                $info .= (int)$timeMachineResult['submitted'] . ' of '
                    . (int)$timeMachineResult['expected'] . ' players have submitted votes.';
                if ($timeMachineResult['voting_scenario'] === 'everyone_except_me') {
                    $info .= ' The signed-in user is the remaining voter.';
                }
            } else {
                $info .= 'The complete season schedule was shifted from the untouched source dates.';
            }
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
$qaTimeMachineStages = mlQaGetTimeMachineStages();
$qaVotingScenarios = mlQaGetVotingScenarios();
$qaAvailableRounds = mlQaGetAvailableRounds($livePdo);
$currentQaRound = mlQaGetCurrentRound($livePdo);
$qaCurrentSeasonRoundId = mlQaGetCurrentSeasonRoundId($livePdo);
$latestQaRound = mlQaGetLatestRound($livePdo);
$previousQaRound = $currentQaRound ? mlQaGetPreviousRound($livePdo, $currentQaRound) : null;
$qaRoundScenarioStatus = mlQaGetRoundScenarioStatus($livePdo, $currentQaRound, $currentUserId);
$qaUserCount = mlQaGetTableCount($livePdo, 'QA_ML_Users');

if ($selectedTimeMachineRoundId <= 0 && $currentQaRound) {
    $selectedTimeMachineRoundId = (int)$currentQaRound['SeasonRoundID'];
}

$qaRoundsBySeason = [];
foreach ($qaAvailableRounds as $availableRound) {
    $seasonKey = (int)$availableRound['SeasonID'];
    if (!isset($qaRoundsBySeason[$seasonKey])) {
        $qaRoundsBySeason[$seasonKey] = [
            'label' => (string)$availableRound['SeasonName'],
            'rounds' => [],
        ];
    }
    $qaRoundsBySeason[$seasonKey]['rounds'][] = $availableRound;
}
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
                <p style="margin:8px 0 0;opacity:.85;">Reset QA from this environment's ML_* source snapshot, then launch the app in QA mode.</p>
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
                <button type="submit" class="button-primary">Reset QA from ML Snapshot</button>
            </form>
            <a href="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="button-secondary">Open QA Tools in QA Mode</a>
            <a href="<?= htmlspecialchars(mlUrl('season.php?testing=qa')) ?>" class="button-secondary">Open QA App</a>
            <a href="<?= htmlspecialchars(mlUrl('season.php?testing=live')) ?>" class="button-secondary">Open Live App</a>
        </div>

        <div class="card" style="margin:0 0 24px;padding:18px;">
            <h2 style="margin-top:0;">QA Time Machine</h2>
            <p style="margin:8px 0 16px;opacity:.85;">
                Rebuild a season around any source round. Every rebuild first resets the configured QA mirror tables from this environment's ML_* snapshot. Earlier rounds retain their completed results, later rounds become genuinely upcoming, and the complete QA schedule is shifted together from the untouched source dates.
            </p>

            <div class="note" style="margin:0 0 16px;">
                <strong>Source freshness:</strong> On mb-future, the ML_* tables are only as current as the last controlled refresh from the musicball database. This page does not perform that cross-database refresh.
            </div>

            <?php if (!mlIsQaMode()): ?>
                <div class="status-banner" style="margin:0;">Open this page with <code>?testing=qa</code> before rebuilding a QA timeline.</div>
            <?php elseif (!$qaAvailableRounds): ?>
                <div class="status-banner error" style="margin:0;">No started rounds were found in this environment's ML_* source snapshot. Refresh the source data if this is unexpected.</div>
            <?php else: ?>
                <?php if ($qaUserCount !== null && $qaRoundScenarioStatus && (int)$qaUserCount !== (int)$qaRoundScenarioStatus['expected']): ?>
                    <div class="status-banner error" style="margin:0 0 14px;">
                        QA contains <?= (int)$qaUserCount ?> users, but gameplay expects <?= (int)$qaRoundScenarioStatus['expected'] ?>. Voting presets will remain blocked until these counts match.
                    </div>
                <?php endif; ?>

                <?php if ($currentQaRound && $qaRoundScenarioStatus): ?>
                    <div class="note" style="margin:0 0 16px;">
                        <strong>Current QA position:</strong>
                        <?= htmlspecialchars((string)$currentQaRound['SeasonName']) ?> /
                        Round <?= (int)$currentQaRound['RoundNumber'] ?> -
                        <?= htmlspecialchars((string)$currentQaRound['Title']) ?> /
                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$qaRoundScenarioStatus['stage']))) ?>.
                        <?php if ($qaRoundScenarioStatus['deadline_utc'] !== ''): ?>
                            <br><strong><?= htmlspecialchars((string)$qaRoundScenarioStatus['deadline_label']) ?>:</strong>
                            <?= htmlspecialchars((string)$qaRoundScenarioStatus['deadline_utc']) ?> UTC.
                        <?php endif; ?>
                        <?php if ($qaRoundScenarioStatus['stage'] === 'voting'): ?>
                            <br><strong>Participation:</strong>
                            <?= (int)$qaRoundScenarioStatus['submitted'] ?> of <?= (int)$qaRoundScenarioStatus['expected'] ?> submitted
                            <?= $qaRoundScenarioStatus['current_user_submitted'] ? '(you have submitted).' : '(you have not submitted).' ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="admin-form-stack" style="margin:0;">
                    <input type="hidden" name="qa_action" value="apply_time_machine">

                    <div class="admin-grid" style="align-items:start;">
                        <div>
                            <label class="admin-label" for="time_machine_round_id">Season and round</label>
                            <select name="time_machine_round_id" id="time_machine_round_id" class="admin-input" required>
                                <?php foreach ($qaRoundsBySeason as $seasonGroup): ?>
                                    <optgroup label="<?= htmlspecialchars((string)$seasonGroup['label']) ?>">
                                        <?php foreach ($seasonGroup['rounds'] as $availableRound): ?>
                                            <option
                                                value="<?= (int)$availableRound['SeasonRoundID'] ?>"
                                                <?= (int)$availableRound['SeasonRoundID'] === $selectedTimeMachineRoundId ? 'selected' : '' ?>
                                            >Round <?= (int)$availableRound['RoundNumber'] ?> - <?= htmlspecialchars((string)$availableRound['Title']) ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="admin-label" for="time_machine_stage">Place the season at</label>
                            <select name="time_machine_stage" id="time_machine_stage" class="admin-input">
                                <?php foreach ($qaTimeMachineStages as $stageKey => $stage): ?>
                                    <option value="<?= htmlspecialchars($stageKey) ?>" <?= $selectedTimeMachineStage === $stageKey ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($stage['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="admin-label" for="voting_scenario">Voting participation</label>
                        <select name="voting_scenario" id="voting_scenario" class="admin-input">
                            <?php foreach ($qaVotingScenarios as $scenarioKey => $scenario): ?>
                                <option value="<?= htmlspecialchars($scenarioKey) ?>" <?= $selectedVotingScenario === $scenarioKey ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($scenario['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="note" style="margin-top:8px;">Used only when the selected position is Voting. Generated ballots obey the QA league's current point total, per-song maximum, and self-voting restriction.</div>
                    </div>

                    <div class="note" style="margin:0;">
                        <strong>Ten-minute placement:</strong> Song Submission ends in 10 minutes; Voting ends in 10 minutes; Closed places the next round's song deadline 10 minutes away. For a closed final round, voting ended 10 minutes ago.
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button
                            type="submit"
                            class="button-primary"
                            onclick="return confirm('Reset all configured QA mirror tables from the local ML snapshot, then rebuild this timeline? Existing QA test changes will be replaced.');"
                        >Rebuild QA Timeline</button>
                        <a href="<?= htmlspecialchars(mlUrl('season.php?testing=qa')) ?>" class="button-secondary">Open QA App</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <details style="margin:0 0 24px;">
            <summary style="cursor:pointer;font-weight:700;margin:0 0 10px;">Existing single-round controls</summary>
        <div class="card" style="margin:0;padding:18px;">
            <h2 style="margin-top:0;">Single-Round Stage Controls</h2>
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
        </details>

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
