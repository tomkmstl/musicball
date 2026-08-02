<?php
// Standards-based Web Push configuration for Musicball.
// Keep private VAPID values in config/push_secrets.php or environment variables.

$pushSecretsFile = __DIR__ . '/push_secrets.php';
if (is_file($pushSecretsFile)) {
    require_once $pushSecretsFile;
}

function mlPushConfigValue(string $localConstant, string $environmentKey, string $default = ''): string
{
    if (defined($localConstant)) {
        return trim((string)constant($localConstant));
    }

    $value = getenv($environmentKey);
    if ($value === false) {
        return $default;
    }

    return trim((string)$value);
}

function mlPushConfigFlag(string $localConstant, string $environmentKey, bool $default = false): bool
{
    $fallback = $default ? '1' : '0';
    $value = strtolower(mlPushConfigValue($localConstant, $environmentKey, $fallback));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function mlPushLiveEnabled(): bool
{
    return mlPushConfigFlag('MUSICBALL_PUSH_ENABLED_LOCAL', 'MUSICBALL_PUSH_ENABLED', false);
}

function mlPushQaEnabled(): bool
{
    return mlPushConfigFlag('MUSICBALL_PUSH_QA_ENABLED_LOCAL', 'MUSICBALL_PUSH_QA_ENABLED', false);
}

function mlPushVapidPublicKey(): string
{
    return mlPushConfigValue('MUSICBALL_PUSH_VAPID_PUBLIC_KEY_LOCAL', 'MUSICBALL_PUSH_VAPID_PUBLIC_KEY');
}

function mlPushVapidPrivateKey(): string
{
    return mlPushConfigValue('MUSICBALL_PUSH_VAPID_PRIVATE_KEY_LOCAL', 'MUSICBALL_PUSH_VAPID_PRIVATE_KEY');
}

function mlPushVapidSubject(): string
{
    $subject = mlPushConfigValue(
        'MUSICBALL_PUSH_VAPID_SUBJECT_LOCAL',
        'MUSICBALL_PUSH_VAPID_SUBJECT',
        'https://musicball.net'
    );

    if (str_starts_with($subject, 'mailto:') || str_starts_with($subject, 'https://')) {
        return $subject;
    }

    return 'https://musicball.net';
}
