<?php
require_once 'session_boot.php';

$_GET['testing'] = 'qa';
require_once 'config.php';
require_once __DIR__ . '/gameplay/bootstrap.php';
require_once __DIR__ . '/integrations/push/push.php';

$currentUserId = isset($_SESSION['UserID']) ? (int)$_SESSION['UserID'] : 0;
if (!mlIsAdminUserId($pdo, $currentUserId)) {
    header('Location: ' . mlUrl('index.php'));
    exit;
}

// The admin test targets this device's real subscription. Rewind snapshots
// must not determine whether a live PWA subscription is available.
$livePdo = mlGetLivePdo();
$pushStorageReady = mlPushStorageReady($livePdo);
$pushReady = mlPushServerReady($livePdo);
if (empty($_SESSION['ml_push_csrf']) || !is_string($_SESSION['ml_push_csrf'])) {
    $_SESSION['ml_push_csrf'] = bin2hex(random_bytes(24));
}
$pushCsrfToken = (string)$_SESSION['ml_push_csrf'];

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
        throw new RuntimeException('Missing required QA table: QA_ML_Settings. Run qa_clone_setup.sql first.');
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
        throw new RuntimeException('Missing table needed for the QA timeline rebuild: ' . $liveTable . ' / ' . $qaTable . '.');
    }

    $sql = 'INSERT INTO ' . mlQaQuoteIdentifier($qaTable)
        . ' SELECT * FROM ' . mlQaQuoteIdentifier($liveTable)
        . ' WHERE SeasonRoundID = ?';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$seasonRoundId]);

    return (int)$stmt->rowCount();
}

function mlQaGetTimeMachineStages(): array
{
    return [
        'submission' => [
            'label' => 'Songs',
            'description' => 'The selected round is accepting songs. Its song deadline will be 10 minutes away.',
        ],
        'voting' => [
            'label' => 'Voting',
            'description' => 'The selected round has a playlist and its voting deadline will be 10 minutes away.',
        ],
        'closed' => [
            'label' => 'Closed',
            'description' => 'The selected round is closed. If another round follows it, that round\'s song deadline will be 10 minutes away.',
        ],
    ];
}

function mlQaGetRoundProgressScenarios(): array
{
    return [
        'none' => [
            'label' => 'None',
        ],
        'everyone_except_me' => [
            'label' => 'All but me',
        ],
        'everyone' => [
            'label' => 'All',
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

function mlQaApplySongSubmissionScenario(PDO $pdo, int $seasonRoundId, int $currentUserId, string $scenario): array
{
    $scenarios = mlQaGetRoundProgressScenarios();
    if (!isset($scenarios[$scenario])) {
        throw new RuntimeException('Invalid QA round-progress scenario.');
    }

    $pdo->prepare('DELETE FROM QA_ML_RoundSongs WHERE SeasonRoundID = ?')->execute([$seasonRoundId]);

    $expectedPlayers = mlQaGetExpectedPlayerCountForTools($pdo);
    if ($scenario === 'none') {
        return ['submitted' => 0, 'expected' => $expectedPlayers];
    }

    $users = $pdo->query('SELECT UserID FROM QA_ML_Users ORDER BY UserID ASC')->fetchAll(PDO::FETCH_COLUMN);
    $userIds = array_values(array_map('intval', is_array($users) ? $users : []));
    if ($expectedPlayers <= 0 || count($userIds) !== $expectedPlayers) {
        throw new RuntimeException(
            'QA has ' . count($userIds) . ' users, but gameplay expects ' . $expectedPlayers
            . '. Align the expected-player count before generating round-progress scenarios.'
        );
    }
    if (!in_array($currentUserId, $userIds, true)) {
        throw new RuntimeException('The signed-in admin is not present in QA_ML_Users and cannot be the remaining player.');
    }

    $requiredUserIds = $scenario === 'everyone_except_me'
        ? array_values(array_filter($userIds, static fn(int $userId): bool => $userId !== $currentUserId))
        : $userIds;

    if (!$requiredUserIds) {
        return ['submitted' => 0, 'expected' => $expectedPlayers];
    }

    $sourceSongStmt = $pdo->prepare('SELECT UserID FROM ML_RoundSongs WHERE SeasonRoundID = ?');
    $sourceSongStmt->execute([$seasonRoundId]);
    $sourceUserIds = array_values(array_unique(array_map('intval', $sourceSongStmt->fetchAll(PDO::FETCH_COLUMN))));
    $missingUserIds = array_values(array_diff($requiredUserIds, $sourceUserIds));
    if ($missingUserIds) {
        throw new RuntimeException(
            'The source round is missing song submissions for ' . count($missingUserIds)
            . ' required player' . (count($missingUserIds) === 1 ? '' : 's')
            . '. This scenario cannot be created without inventing songs.'
        );
    }

    $placeholders = implode(', ', array_fill(0, count($requiredUserIds), '?'));
    $copyStmt = $pdo->prepare(
        'INSERT INTO QA_ML_RoundSongs SELECT * FROM ML_RoundSongs'
        . ' WHERE SeasonRoundID = ? AND UserID IN (' . $placeholders . ')'
    );
    $copyStmt->execute(array_merge([$seasonRoundId], $requiredUserIds));
    $submitted = (int)$copyStmt->rowCount();

    if ($submitted !== count($requiredUserIds)) {
        throw new RuntimeException('The source round did not provide one song submission for every required player.');
    }

    return ['submitted' => $submitted, 'expected' => $expectedPlayers];
}

function mlQaApplyVotingScenario(PDO $pdo, int $seasonRoundId, int $currentUserId, string $scenario): array
{
    $scenarios = mlQaGetRoundProgressScenarios();
    if (!isset($scenarios[$scenario])) {
        throw new RuntimeException('Invalid QA round-progress scenario.');
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
            . '. Align the expected-player count before generating round-progress scenarios.'
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
    string $progressScenario,
    int $currentUserId,
    array $tables
): array
{
    $stages = mlQaGetTimeMachineStages();
    if (!isset($stages[$targetStage])) {
        throw new RuntimeException('Invalid QA time-machine stage.');
    }
    if ($targetStage !== 'closed' && !isset(mlQaGetRoundProgressScenarios()[$progressScenario])) {
        throw new RuntimeException('Invalid QA round-progress scenario.');
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
            throw new RuntimeException('Missing required table: ' . $tableName . '. Run qa_clone_setup.sql first.');
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
                throw new RuntimeException('A QA round is missing from the selected season after the source reset.');
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

        if ($targetStage === 'submission') {
            $participation = mlQaApplySongSubmissionScenario(
                $pdo,
                $seasonRoundId,
                $currentUserId,
                $progressScenario
            );
            $copied['songs'] += (int)$participation['submitted'];
        } elseif ($targetStage === 'voting') {
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
            $participation = mlQaApplyVotingScenario($pdo, $seasonRoundId, $currentUserId, $progressScenario);
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
    if (isset($_SESSION['ml_round_songs'][$currentUserId])) {
        unset($_SESSION['ml_round_songs'][$currentUserId]);
    }

    return [
        'round' => $liveRound,
        'stage' => $targetStage,
        'stage_label' => $stages[$targetStage]['label'],
        'progress_scenario' => $targetStage === 'closed' ? '' : $progressScenario,
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

$message = '';
$error = '';
$info = '';
$selectedTimeMachineRoundId = isset($_POST['time_machine_round_id']) ? (int)$_POST['time_machine_round_id'] : 0;
$selectedTimeMachineStage = isset($_POST['time_machine_stage']) ? trim((string)$_POST['time_machine_stage']) : 'voting';
$selectedRoundProgress = isset($_POST['round_progress']) ? trim((string)$_POST['round_progress']) : 'everyone_except_me';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['qa_action']) ? trim((string)$_POST['qa_action']) : '';

    try {
        if ($action === 'push_live_to_qa') {
            mlQaPushLiveToQa($livePdo, $mlQaTables);
            if (isset($_SESSION['ml_round_votes'][$currentUserId])) {
                unset($_SESSION['ml_round_votes'][$currentUserId]);
            }
            if (isset($_SESSION['ml_round_songs'][$currentUserId])) {
                unset($_SESSION['ml_round_songs'][$currentUserId]);
            }
            $message = 'QA was restored to the current ML_* snapshot.';
        } elseif ($action === 'apply_time_machine') {
            $timeMachineResult = mlQaApplyTimeMachine(
                $livePdo,
                $selectedTimeMachineRoundId,
                $selectedTimeMachineStage,
                $selectedRoundProgress,
                $currentUserId,
                $mlQaTables
            );
            $round = $timeMachineResult['round'];
            $message = 'Loaded ' . $round['SeasonName']
                . ' / Round ' . (int)$round['RoundNumber'] . ' - ' . $round['Title']
                . ' in the ' . $timeMachineResult['stage_label'] . ' phase.';
            $info = ucfirst((string)$timeMachineResult['deadline_label']) . ': '
                . $timeMachineResult['deadline_utc'] . ' UTC. ';

            if (in_array($timeMachineResult['stage'], ['submission', 'voting'], true)) {
                $info .= (int)$timeMachineResult['submitted'] . ' of '
                    . (int)$timeMachineResult['expected'] . ' players have submitted '
                    . ($timeMachineResult['stage'] === 'submission' ? 'a song.' : 'votes.');
                if ($timeMachineResult['progress_scenario'] === 'everyone_except_me') {
                    $info .= ' You are the remaining player.';
                }
            } else {
                $info .= 'The complete season schedule was shifted from the untouched source dates.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$qaTimeMachineStages = mlQaGetTimeMachineStages();
$qaRoundProgressScenarios = mlQaGetRoundProgressScenarios();
$qaAvailableRounds = mlQaGetAvailableRounds($livePdo);
$currentQaRound = mlQaGetCurrentRound($livePdo);

if (!isset($qaTimeMachineStages[$selectedTimeMachineStage])) {
    $selectedTimeMachineStage = 'voting';
}
if (!isset($qaRoundProgressScenarios[$selectedRoundProgress])) {
    $selectedRoundProgress = 'everyone_except_me';
}

if ($selectedTimeMachineRoundId <= 0) {
    $availableRoundIds = array_map(
        static fn(array $round): int => (int)$round['SeasonRoundID'],
        $qaAvailableRounds
    );
    $currentQaRoundId = $currentQaRound ? (int)$currentQaRound['SeasonRoundID'] : 0;

    if ($currentQaRoundId > 0 && in_array($currentQaRoundId, $availableRoundIds, true)) {
        $selectedTimeMachineRoundId = $currentQaRoundId;
    } elseif ($qaAvailableRounds) {
        $preferredSeasonId = (int)$qaAvailableRounds[0]['SeasonID'];
        foreach ($qaAvailableRounds as $availableRound) {
            if ((int)$availableRound['IsActive'] === 1) {
                $preferredSeasonId = (int)$availableRound['SeasonID'];
                break;
            }
        }

        foreach ($qaAvailableRounds as $availableRound) {
            if ((int)$availableRound['SeasonID'] === $preferredSeasonId) {
                $selectedTimeMachineRoundId = (int)$availableRound['SeasonRoundID'];
            }
        }
    }
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
    <div class="card admin-card qa-rewind-card">
        <div class="admin-page-topline admin-page-topline-compact">
            <div class="admin-page-intro">
                <h1>QA Tools</h1>
            </div>
        </div>

        <section class="admin-section-divider">
        <div class="qa-rewind-kicker">Past-state testing</div>
        <h2>Musicball Rewind</h2>
        <p>Load a past round exactly where you need it.</p>

        <div class="qa-rewind-toolbar">
            <span class="pill pill-open">QA mode</span>
            <div class="qa-rewind-toolbar-actions">
                <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>">
                    <input type="hidden" name="qa_action" value="push_live_to_qa">
                    <button
                        type="submit"
                        class="button-secondary"
                        onclick="return confirm('Restore QA to the current ML snapshot? Existing QA test changes will be replaced.');"
                    >Restore baseline</button>
                </form>
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

        <?php if (!$qaAvailableRounds): ?>
            <div class="status-banner error qa-rewind-empty">No started rounds were found in this environment's ML_* source snapshot. Refresh the source data if this is unexpected.</div>
        <?php else: ?>
            <form method="post" action="<?= htmlspecialchars(mlUrl('qa_tools.php?testing=qa')) ?>" class="qa-rewind-form">
                <input type="hidden" name="qa_action" value="apply_time_machine">

                <div>
                    <label class="admin-label" for="time_machine_round_id">Round</label>
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

                <div class="qa-rewind-options" id="qa-rewind-options">
                    <fieldset class="qa-rewind-fieldset">
                        <legend class="admin-label">Round Phase</legend>
                        <div class="qa-rewind-choices">
                            <?php foreach ($qaTimeMachineStages as $stageKey => $stage): ?>
                                <label class="qa-rewind-choice">
                                    <input
                                        type="radio"
                                        name="time_machine_stage"
                                        value="<?= htmlspecialchars($stageKey) ?>"
                                        <?= $selectedTimeMachineStage === $stageKey ? 'checked' : '' ?>
                                    >
                                    <span><?= htmlspecialchars($stage['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <fieldset class="qa-rewind-fieldset" id="qa-round-progress">
                        <legend class="admin-label">Round Progress</legend>
                        <div class="qa-rewind-choices">
                            <?php foreach ($qaRoundProgressScenarios as $scenarioKey => $scenario): ?>
                                <label class="qa-rewind-choice">
                                    <input
                                        type="radio"
                                        name="round_progress"
                                        value="<?= htmlspecialchars($scenarioKey) ?>"
                                        <?= $selectedRoundProgress === $scenarioKey ? 'checked' : '' ?>
                                    >
                                    <span><?= htmlspecialchars($scenario['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                </div>

                <div class="qa-rewind-footer">
                    <div class="note qa-rewind-placement">You will be placed in the round 10 minutes before the next deadline.</div>
                    <button
                        type="submit"
                        class="button-primary qa-rewind-load"
                        onclick="return confirm('Load this QA state? Existing QA test changes will be replaced.');"
                    >Load state</button>
                </div>
            </form>
        <?php endif; ?>
        </section>

        <section class="admin-section-divider">
            <div class="qa-rewind-kicker">Push notifications</div>
            <h2>Push Notification Test</h2>
            <p>Send any supported notification to this admin device. Push Notifications must be on for this device in Live Mode Settings.</p>

            <div class="admin-push-test-control" data-push-admin-test>
                <div class="admin-push-test-status" data-push-admin-status>Checking this device...</div>
                <div class="admin-inline-form admin-inline-form-wrap">
                    <div class="admin-inline-field">
                        <label class="admin-label" for="admin_push_test_notification">Notification type</label>
                        <select id="admin_push_test_notification" class="admin-input admin-select-compact" data-push-admin-type>
                            <?php foreach (mlPushTestNotificationOptions() as $notificationType => $notificationLabel): ?>
                                <option value="<?= htmlspecialchars($notificationType) ?>"><?= htmlspecialchars($notificationLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="button-secondary" data-push-admin-send disabled>Send Test</button>
                </div>
            </div>

            <?php if (!$pushStorageReady): ?>
                <p class="admin-form-top-sm">Push notification storage is not ready yet.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
(() => {
    const phaseInputs = Array.from(document.querySelectorAll('input[name="time_machine_stage"]'));
    const progressFieldset = document.getElementById('qa-round-progress');
    const options = document.getElementById('qa-rewind-options');

    if (!phaseInputs.length || !progressFieldset || !options) {
        return;
    }

    const syncProgressVisibility = () => {
        const selectedPhase = phaseInputs.find((input) => input.checked);
        const isClosed = selectedPhase && selectedPhase.value === 'closed';
        progressFieldset.hidden = isClosed;
        options.classList.toggle('is-closed', Boolean(isClosed));
    };

    phaseInputs.forEach((input) => input.addEventListener('change', syncProgressVisibility));
    syncProgressVisibility();
})();
</script>
<script>
window.ML_PUSH_ADMIN_TEST = <?= json_encode([
    'ready' => $pushReady,
    'endpoint' => mlUrl('integrations/push/subscription.php'),
    'csrfToken' => $pushCsrfToken,
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= htmlspecialchars(mlAssetUrl('assets/js/push-admin-test.js')) ?>" defer></script>
</body>
</html>
