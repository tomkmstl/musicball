<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

mlRequireAuthenticatedUser($pdo);

$redirectStatus = $_SERVER['REQUEST_METHOD'] === 'POST' ? 307 : 302;
header('Location: ' . mlUrl('league.php?view=songs'), true, $redirectStatus);
exit;
