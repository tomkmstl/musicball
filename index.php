<?php
require_once 'session_boot.php';
require_once 'config.php';

if (isset($_SESSION['UserID']) || isset($_SESSION['ml_user_id'])) {
    header('Location: ' . mlUrl('season.php'));
    exit;
}

header('Location: ' . mlUrl('home.php'));
exit;