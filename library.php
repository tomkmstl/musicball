<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

mlRequireAuthenticatedUser($pdo);

$params = ['view' => 'songs'];
$statusType = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$statusMessage = isset($_GET['message']) ? trim((string)$_GET['message']) : '';
if (in_array($statusType, ['success', 'error'], true) && $statusMessage !== '') {
    $params['status'] = $statusType;
    $params['message'] = $statusMessage;
}

$redirectStatus = $_SERVER['REQUEST_METHOD'] === 'POST' ? 307 : 302;
header('Location: ' . mlUrl('league.php?' . http_build_query($params)), true, $redirectStatus);
exit;
