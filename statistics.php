<?php
require_once __DIR__ . '/gameplay/bootstrap.php';

mlRequireAuthenticatedUser($pdo);

$legacyView = isset($_GET['view']) ? strtolower(trim((string)$_GET['view'])) : 'standings';
if ($legacyView === 'round' || !in_array($legacyView, ['standings', 'leaders', 'trends'], true)) {
    $legacyView = 'standings';
}

$params = ['view' => $legacyView];
$seasonKey = isset($_GET['season_id']) ? trim((string)$_GET['season_id']) : '';
if ($seasonKey === 'all' || (ctype_digit($seasonKey) && (int)$seasonKey > 0)) {
    $params['season_id'] = $seasonKey;
}

$sortKey = isset($_GET['sort']) ? trim((string)$_GET['sort']) : '';
if (in_array($sortKey, ['points', 'round_wins', 'total_voters', 'podiums', 'best_round_score', 'holdouts'], true)) {
    $params['sort'] = $sortKey;
}

$sortDir = isset($_GET['dir']) ? strtolower(trim((string)$_GET['dir'])) : '';
if ($sortDir === 'asc' || $sortDir === 'desc') {
    $params['dir'] = $sortDir;
}

header('Location: ' . mlUrl('league.php?' . http_build_query($params)), true, 302);
exit;
