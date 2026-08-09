<?php
// Shared Music League configuration and helper functions.

require_once __DIR__ . '/config/connections/conn_musicball.php';

if (!isset($GLOBALS['tpodConnection']) || !($GLOBALS['tpodConnection'] instanceof PDO)) {
    die('Database connection ($GLOBALS["tpodConnection"]) is not available. Check conn_lawson.php.');
}

class MlQAPdoProxy extends PDO
{
    /** @var PDO */
    private $inner;

    public function __construct(PDO $inner)
    {
        $this->inner = $inner;
    }

    private function rewriteSqlTables(string $sql): string
    {
        $parts = preg_split("/('(?:''|\\'|[^'])*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!is_array($parts)) {
            return $sql;
        }

        foreach ($parts as $index => $part) {
            if (($index % 2) === 1) {
                continue;
            }

            $parts[$index] = preg_replace('/(?<!QA_)\bML_([A-Za-z0-9_]+)\b/', 'QA_ML_$1', $part);
        }

        return implode('', $parts);
    }

    public function prepare($statement, $driver_options = [])
    {
        return $this->inner->prepare($this->rewriteSqlTables((string)$statement), $driver_options);
    }

    public function query(string $query, ?int $fetchMode = null, ...$fetchModeArgs)
    {
        $query = $this->rewriteSqlTables($query);

        if ($fetchMode === null) {
            return $this->inner->query($query);
        }

        return $this->inner->query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec($statement)
    {
        return $this->inner->exec($this->rewriteSqlTables((string)$statement));
    }

    public function lastInsertId($name = null)
    {
        return $this->inner->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        return $this->inner->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollBack(): bool
    {
        return $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function getAttribute($attribute)
    {
        return $this->inner->getAttribute($attribute);
    }

    public function setAttribute($attribute, $value): bool
    {
        return $this->inner->setAttribute($attribute, $value);
    }

    public function quote($string, $type = PDO::PARAM_STR)
    {
        return $this->inner->quote($string, $type);
    }

    public function errorCode(): ?string
    {
        return $this->inner->errorCode();
    }

    public function errorInfo(): array
    {
        $info = $this->inner->errorInfo();
        return is_array($info) ? $info : [];
    }
}

function mlResolveTestingMode(): string
{
    $requested = isset($_GET['testing']) ? strtolower(trim((string)$_GET['testing'])) : '';

    if ($requested === 'qa') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['ml_testing_mode'] = 'qa';
        }
        return 'qa';
    }

    if ($requested === 'live') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['ml_testing_mode']);
        }
        return 'live';
    }

    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['ml_testing_mode']) && $_SESSION['ml_testing_mode'] === 'qa') {
        return 'qa';
    }

    return 'live';
}

function mlIsQaMode(): bool
{
    static $isQaMode = null;

    if ($isQaMode !== null) {
        return $isQaMode;
    }

    $isQaMode = (mlResolveTestingMode() === 'qa');
    return $isQaMode;
}

$GLOBALS['ml_live_pdo'] = $GLOBALS['tpodConnection'];
$pdo = mlIsQaMode() ? new MlQAPdoProxy($GLOBALS['tpodConnection']) : $GLOBALS['tpodConnection'];
$totalPlayers = 12;

function mlGetLivePdo(): PDO
{
    return $GLOBALS['ml_live_pdo'];
}

function mlGetPdoDataMode(PDO $pdo): string
{
    if ($pdo instanceof MlQAPdoProxy) {
        return 'qa';
    }

    if (isset($GLOBALS['ml_live_pdo']) && $pdo === $GLOBALS['ml_live_pdo']) {
        return 'live';
    }

    return 'unknown';
}

function mlGetSeasonConfig(PDO $pdo, int $seasonId, string $configKey, $default = null) {
    $stmt = $pdo->prepare("\n        SELECT ConfigValue\n        FROM ML_Config\n        WHERE SeasonID = ?\n          AND ConfigKey = ?\n        LIMIT 1\n    ");
    $stmt->execute([$seasonId, $configKey]);
    $value = $stmt->fetchColumn();

    if ($value === false) {
        return $default;
    }

    return $value;
}

function mlSetSeasonConfig(PDO $pdo, int $seasonId, string $configKey, string $configValue): void {
    $stmt = $pdo->prepare("\n        INSERT INTO ML_Config (SeasonID, ConfigKey, ConfigValue)\n        VALUES (?, ?, ?)\n        ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)\n    ");
    $stmt->execute([$seasonId, $configKey, $configValue]);
}


function mlGetSettingValue(PDO $pdo, string $settingKey, $default = null) {
    $stmt = $pdo->prepare("
        SELECT SettingValue
        FROM ML_Settings
        WHERE SettingKey = ?
        LIMIT 1
    " );
    $stmt->execute([$settingKey]);
    $value = $stmt->fetchColumn();

    if ($value === false) {
        return $default;
    }

    return $value;
}

function mlSetSettingValue(PDO $pdo, string $settingKey, ?string $settingValue): void {
    $stmt = $pdo->prepare("
        INSERT INTO ML_Settings (SettingKey, SettingValue)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue)
    ");
    $stmt->execute([$settingKey, $settingValue]);
}

function mlGetIntSetting(PDO $pdo, string $settingKey, int $default = 0): int {
    $value = mlGetSettingValue($pdo, $settingKey, (string)$default);
    return is_numeric($value) ? (int)$value : $default;
}


function mlGetQaCurrentSeasonRoundId(PDO $pdo): int
{
    if (!mlIsQaMode()) {
        return 0;
    }

    $value = mlGetIntSetting($pdo, 'qa_current_season_round_id', 0);
    return $value > 0 ? $value : 0;
}

function mlNormalizeFsPath(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function mlGetAppBasePath(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $appDir = mlNormalizeFsPath((string)(realpath(__DIR__) ?: __DIR__));
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? mlNormalizeFsPath((string)(realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'])) : '';

    if ($documentRoot !== '' && strpos($appDir, $documentRoot) === 0) {
        $relative = trim(substr($appDir, strlen($documentRoot)), '/');
        $basePath = ($relative === '') ? '' : '/' . $relative;
        return $basePath;
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/') {
        $basePath = '';
    } else {
        if (substr($scriptDir, -15) === '/season-builder') {
            $scriptDir = substr($scriptDir, 0, -15);
        }
        $basePath = $scriptDir;
    }

    return $basePath;
}

function mlUrl(string $path = ''): string
{
    $basePath = mlGetAppBasePath();
    $path = ltrim($path, '/');
    $queryString = '';

    if ($path !== '' && strpos($path, '?') !== false) {
        [$path, $queryString] = explode('?', $path, 2);
    }

    if ($path === '') {
        $url = ($basePath === '') ? '/' : ($basePath . '/');
    } else {
        $url = ($basePath === '' ? '' : $basePath) . '/' . $path;
    }

    $params = [];
    if ($queryString !== '') {
        parse_str($queryString, $params);
    }

    if (isset($params['testing'])) {
        $requestedMode = strtolower((string)$params['testing']);
        if ($requestedMode !== 'qa' && $requestedMode !== 'live') {
            unset($params['testing']);
        }
    } elseif (mlIsQaMode()) {
        $params['testing'] = 'qa';
    }

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function mlAssetUrl(string $path): string
{
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    $version = 'v=' . (is_file($fullPath) ? (string)filemtime($fullPath) : '1');

    $assetUrl = mlUrl($path);

    return $assetUrl . (strpos($assetUrl, '?') === false ? '?' : '&') . $version;
}

$activeSeason = mlGetCurrentSeason($pdo);


function mlGetThemeMode(): string
{
    $theme = isset($_COOKIE['ml_theme']) ? strtolower(trim((string)$_COOKIE['ml_theme'])) : 'dark';
    return $theme === 'light' ? 'light' : 'dark';
}

function mlSetThemeMode(string $theme): void
{
    $theme = strtolower(trim($theme)) === 'light' ? 'light' : 'dark';
    setcookie('ml_theme', $theme, time() + (86400 * 365 * 5), '/');
    $_COOKIE['ml_theme'] = $theme;
}

function mlGetThemeBodyClass(): string
{
    return mlGetThemeMode() === 'light' ? 'theme-light' : 'theme-dark';
}

function mlUsersHasProfileImageColumn(PDO $pdo): bool
{
    static $hasColumn = null;

    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_Users LIKE 'ProfileImageFilename'");
        $hasColumn = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function mlUsersHasShortDisplayNameColumn(PDO $pdo): bool
{
    static $hasColumn = null;

    if ($hasColumn !== null) {
        return $hasColumn;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_Users LIKE 'ShortDisplayName'");
        $hasColumn = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function mlResolveUserProfileFilename(int $userId, ?string $storedFilename = null): string
{
    $candidate = trim((string)$storedFilename);
    if ($candidate === '') {
        $candidate = 'profile_' . $userId . '.png';
    }

    return basename($candidate);
}

function mlGetUserProfilePath(int $userId, ?string $storedFilename = null): string
{
    return 'uploads/profiles/' . mlResolveUserProfileFilename($userId, $storedFilename);
}

function mlProfileImageIsAnimatedGif(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    $chunk = '';
    $frames = 0;

    while (!feof($handle) && $frames < 2) {
        $chunk .= (string)fread($handle, 1024 * 100);
        $frames += preg_match_all('#\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk);

        if (strlen($chunk) > 200000) {
            $chunk = substr($chunk, -100000);
        }
    }

    fclose($handle);

    return $frames > 1;
}

function mlCreateImageResourceFromFile(string $path, string $mimeType)
{
    switch ($mimeType) {
        case 'image/jpeg':
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;
        case 'image/png':
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false;
        case 'image/webp':
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        case 'image/gif':
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false;
        default:
            return false;
    }
}

function mlWriteImageResourceToFile($image, string $destinationPath, string $mimeType): bool
{
    switch ($mimeType) {
        case 'image/jpeg':
            return function_exists('imagejpeg') ? @imagejpeg($image, $destinationPath, 82) : false;
        case 'image/png':
            if (!function_exists('imagepng')) {
                return false;
            }
            imagealphablending($image, false);
            imagesavealpha($image, true);
            return @imagepng($image, $destinationPath, 6);
        case 'image/webp':
            return function_exists('imagewebp') ? @imagewebp($image, $destinationPath, 82) : false;
        case 'image/gif':
            return function_exists('imagegif') ? @imagegif($image, $destinationPath) : false;
        default:
            return false;
    }
}

function mlResizeImageToFit(string $sourcePath, string $destinationPath, int $maxWidth, int $maxHeight, array $imageInfo, ?string &$error = null): bool
{
    $width = isset($imageInfo[0]) ? (int)$imageInfo[0] : 0;
    $height = isset($imageInfo[1]) ? (int)$imageInfo[1] : 0;
    $mimeType = isset($imageInfo['mime']) ? strtolower((string)$imageInfo['mime']) : '';

    if ($width <= 0 || $height <= 0 || $mimeType === '') {
        $error = 'The uploaded file is not a valid image.';
        return false;
    }

    if ($mimeType === 'image/gif' && mlProfileImageIsAnimatedGif($sourcePath)) {
        if (!@copy($sourcePath, $destinationPath)) {
            $error = 'The photo could not be saved.';
            return false;
        }
        return true;
    }

    $sourceImage = mlCreateImageResourceFromFile($sourcePath, $mimeType);
    if (!$sourceImage) {
        $error = 'Your server could not process that image format.';
        return false;
    }

    $scale = min($maxWidth / $width, $maxHeight / $height, 1);
    $targetWidth = max(1, (int)round($width * $scale));
    $targetHeight = max(1, (int)round($height * $scale));

    if ($scale >= 1) {
        $targetWidth = $width;
        $targetHeight = $height;
    }

    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$targetImage) {
        imagedestroy($sourceImage);
        $error = 'The photo could not be resized.';
        return false;
    }

    if ($mimeType === 'image/png' || $mimeType === 'image/webp' || $mimeType === 'image/gif') {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    if (!imagecopyresampled($targetImage, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
        imagedestroy($sourceImage);
        imagedestroy($targetImage);
        $error = 'The photo could not be resized.';
        return false;
    }

    $saved = mlWriteImageResourceToFile($targetImage, $destinationPath, $mimeType);

    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    if (!$saved) {
        $error = 'The resized photo could not be saved.';
        return false;
    }

    return true;
}

function mlGetLeagueName(PDO $pdo): string
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    try {
        $value = mlGetSettingValue($pdo, 'league_name', 'Music Ball');

        $cached = $value ? (string)$value : 'Music Ball';
    } catch (Exception $e) {
        $cached = 'Music Ball';
    }

    return $cached;
}


function mlGetPlaylistBuildModeLabel(PDO $pdo): string
{
    return mlGetPlaylistBuildMode($pdo) === 'wait' ? 'Wait for everyone' : 'Build at Songs Due';
}

function mlUsersHasIsAdminColumn(PDO $pdo): bool
{
    static $cache = [];

    $key = spl_object_hash($pdo);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_Users LIKE 'IsAdmin'");
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function mlUserIsAdmin(array $user): bool
{
    return !empty($user['IsAdmin']) && (int)$user['IsAdmin'] === 1;
}

function mlIsAdminUserId(PDO $pdo, int $userId): bool
{
    if ($userId <= 0 || !mlUsersHasIsAdminColumn($pdo)) {
        return false;
    }

    static $cache = [];
    $pdoKey = spl_object_hash($pdo);

    if (isset($cache[$pdoKey]) && array_key_exists($userId, $cache[$pdoKey])) {
        return $cache[$pdoKey][$userId];
    }

    $stmt = $pdo->prepare('SELECT IsAdmin FROM ML_Users WHERE UserID = ? LIMIT 1');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    $isAdmin = ((int)$value === 1);

    if (!isset($cache[$pdoKey])) {
        $cache[$pdoKey] = [];
    }
    $cache[$pdoKey][$userId] = $isAdmin;

    return $isAdmin;
}

function mlGetCurrentSeason(PDO $pdo): ?array
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $stmt = $pdo->query("
        SELECT SeasonID, SeasonName, IsActive
        FROM ML_Seasons
        WHERE IsActive = 1
        ORDER BY SeasonID DESC
        LIMIT 1
    ");
    $cached = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($cached === null) {
        $fallbackStmt = $pdo->query("
            SELECT SeasonID, SeasonName, IsActive
            FROM ML_Seasons
            ORDER BY SeasonID DESC
            LIMIT 1
        ");
        $cached = $fallbackStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    return $cached;
}

function mlGetNextSeason(PDO $pdo): ?array
{
    static $cached = false;

    if ($cached !== false) {
        return $cached;
    }

    $currentSeason = mlGetCurrentSeason($pdo);
    $currentSeasonId = $currentSeason ? (int)$currentSeason['SeasonID'] : 0;

    $stmt = $pdo->prepare("
        SELECT SeasonID, SeasonName, IsActive
        FROM ML_Seasons
        WHERE IsActive = 0
          AND SeasonID > ?
        ORDER BY SeasonID DESC
        LIMIT 1
    ");
    $stmt->execute([$currentSeasonId]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    return $cached;
}

function mlGetSeasonSubmissionCount(PDO $pdo, int $seasonId): int
{
    static $cache = [];

    if (isset($cache[$seasonId])) {
        return $cache[$seasonId];
    }

    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT UserID) FROM ML_Submissions WHERE SeasonID = ?');
    $stmt->execute([$seasonId]);
    $cache[$seasonId] = (int)$stmt->fetchColumn();

    return $cache[$seasonId];
}

function mlGetTotalUserCount(PDO $pdo): int
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $cached = (int)$pdo->query('SELECT COUNT(*) FROM ML_Users')->fetchColumn();
    return $cached;
}

function mlIsSeasonVotingOpen(PDO $pdo, int $seasonId): bool
{
    return ((string)mlGetSeasonConfig($pdo, $seasonId, 'voting_open', '0') === '1');
}

function mlIsSeasonVotingComplete(PDO $pdo, int $seasonId): bool
{
    $totalUsers = mlGetTotalUserCount($pdo);
    if ($totalUsers <= 0) {
        return false;
    }

    return mlGetSeasonSubmissionCount($pdo, $seasonId) >= $totalUsers;
}

function mlGetVotingSeason(PDO $pdo): ?array
{
    $nextSeason = mlGetNextSeason($pdo);
    if (!$nextSeason) {
        return null;
    }

    $nextSeasonId = (int)$nextSeason['SeasonID'];
    if (!mlIsSeasonVotingOpen($pdo, $nextSeasonId)) {
        return null;
    }

    $nextSeason['VotingOpen'] = true;
    $nextSeason['VotingComplete'] = mlIsSeasonVotingComplete($pdo, $nextSeasonId);

    return $nextSeason;
}

function mlWasSeasonVotingClosedEarly(PDO $pdo, int $seasonId): bool
{
    return !mlIsSeasonVotingOpen($pdo, $seasonId)
        && !mlIsSeasonVotingComplete($pdo, $seasonId)
        && mlGetSeasonSubmissionCount($pdo, $seasonId) > 0;
}

function mlCanStartNextSeason(PDO $pdo, int $seasonId): bool
{
    return mlIsSeasonVotingComplete($pdo, $seasonId) || mlWasSeasonVotingClosedEarly($pdo, $seasonId);
}

if (!$activeSeason) {
    die('No season was found in ML_Seasons.');
}

$seasonId = (int)$activeSeason['SeasonID'];
$seasonName = (string)$activeSeason['SeasonName'];

$votingOpenValue = mlGetSeasonConfig($pdo, $seasonId, 'voting_open', '1');
$votingOpen = ((string)$votingOpenValue === '1');
$nextSeason = mlGetNextSeason($pdo);
$votingSeason = mlGetVotingSeason($pdo);
