<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

mlRequireAuthenticatedUser($pdo);

header('Location: ' . mlUrl('library.php'), true, 302);
exit;
