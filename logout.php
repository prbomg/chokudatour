<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/auth_helpers.php';

// Удаляем токен из БД и очищаем куки
clearRememberToken($pdo);

session_unset();
session_destroy();
header("Location: login.php");
exit;
?>
