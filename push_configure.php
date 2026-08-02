<?php
// One-time CLI setup for an environment's private Web Push configuration.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script is for CLI use only.';
    exit;
}

$enableLive = false;
$enableQa = false;

foreach (array_slice($argv ?? [], 1) as $argument) {
    if ($argument === '--enable-live') {
        $enableLive = true;
    } elseif ($argument === '--enable-qa') {
        $enableQa = true;
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
        exit(2);
    }
}

$autoloadPath = __DIR__ . '/vendor/autoload.php';
$secretsPath = __DIR__ . '/config/push_secrets.php';

if (!is_file($autoloadPath)) {
    fwrite(STDERR, 'Run composer install before configuring push notifications.' . PHP_EOL);
    exit(1);
}

if (is_file($secretsPath)) {
    fwrite(STDERR, 'Push secrets already exist. The existing VAPID keys were left unchanged.' . PHP_EOL);
    exit(1);
}

require_once $autoloadPath;

function mlPushConfigureBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function mlPushConfigureCreateVapidKeys(): array
{
    try {
        return Minishlink\WebPush\VAPID::createVapidKeys();
    } catch (Throwable $libraryError) {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw $libraryError;
        }

        $bundledOpenSslConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (!is_file($bundledOpenSslConfig)) {
            throw $libraryError;
        }

        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config' => $bundledOpenSslConfig,
        ]);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        $ec = is_array($details) && isset($details['ec']) && is_array($details['ec'])
            ? $details['ec']
            : [];

        $privateKey = isset($ec['d']) ? str_pad((string)$ec['d'], 32, "\0", STR_PAD_LEFT) : '';
        $publicX = isset($ec['x']) ? str_pad((string)$ec['x'], 32, "\0", STR_PAD_LEFT) : '';
        $publicY = isset($ec['y']) ? str_pad((string)$ec['y'], 32, "\0", STR_PAD_LEFT) : '';

        if (strlen($privateKey) !== 32 || strlen($publicX) !== 32 || strlen($publicY) !== 32) {
            throw $libraryError;
        }

        return [
            'publicKey' => mlPushConfigureBase64UrlEncode("\x04" . $publicX . $publicY),
            'privateKey' => mlPushConfigureBase64UrlEncode($privateKey),
        ];
    }
}

try {
    $keys = mlPushConfigureCreateVapidKeys();
    $publicKey = trim((string)($keys['publicKey'] ?? ''));
    $privateKey = trim((string)($keys['privateKey'] ?? ''));

    if ($publicKey === '' || $privateKey === '') {
        throw new RuntimeException('The Web Push library did not return a complete VAPID key pair.');
    }

    $contents = "<?php\n"
        . "// Environment-specific Web Push secrets. This file is ignored by Git.\n"
        . "define('MUSICBALL_PUSH_ENABLED_LOCAL', " . ($enableLive ? 'true' : 'false') . ");\n"
        . "define('MUSICBALL_PUSH_QA_ENABLED_LOCAL', " . ($enableQa ? 'true' : 'false') . ");\n"
        . "define('MUSICBALL_PUSH_VAPID_PUBLIC_KEY_LOCAL', " . var_export($publicKey, true) . ");\n"
        . "define('MUSICBALL_PUSH_VAPID_PRIVATE_KEY_LOCAL', " . var_export($privateKey, true) . ");\n"
        . "define('MUSICBALL_PUSH_VAPID_SUBJECT_LOCAL', 'https://musicball.net');\n";

    $bytesWritten = file_put_contents($secretsPath, $contents, LOCK_EX);
    if ($bytesWritten === false) {
        throw new RuntimeException('The private push configuration file could not be written.');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        @chmod($secretsPath, 0600);
    }

    echo 'Push configuration created. Live: '
        . ($enableLive ? 'enabled' : 'off')
        . '; QA: '
        . ($enableQa ? 'enabled' : 'off')
        . '.' . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Push configuration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
