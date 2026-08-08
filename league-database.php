<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

mlRequireAuthenticatedUser($pdo);

header('Location: ' . mlUrl('league.php?view=songs'), true, 302);
exit;
