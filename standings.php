<?php
$target = 'statistics.php?view=standings';

if (isset($_SERVER['QUERY_STRING']) && trim((string)$_SERVER['QUERY_STRING']) !== '') {
    $target .= '&' . (string)$_SERVER['QUERY_STRING'];
}

header('Location: ' . $target);
exit;
