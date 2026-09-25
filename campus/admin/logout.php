<?php
require_once '../config.php';
unset($_SESSION['campus_coin_admin']);
unset($_SESSION['campus_coin_admin_name']);
header('Location: login.php');
exit;
