<?php
session_start();

$demoEmail = 'student@campuscoin.com';
$demoPassword = '123456';

function requireLogin()
{
    if (!isset($_SESSION['campus_coin_logged_in']) || $_SESSION['campus_coin_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin()
{
    if (!isset($_SESSION['campus_coin_admin']) || $_SESSION['campus_coin_admin'] !== true) {
        header('Location: login.php');
        exit;
    }
}
?>
