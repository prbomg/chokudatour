<?php
session_start();
require_once 'db.php';

// Удаляем токен из БД и очищаем куки
if (isset($_COOKIE['remember_token'])) {
    $pdo->prepare("UPDATE users SET remember_token = NULL WHERE remember_token = ?")->execute([$_COOKIE['remember_token']]);
    setcookie('remember_token', '', time() - 3600, "/");
}

session_unset();
session_destroy();
header("Location: login.php");
exit;
?>