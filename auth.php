<?php
session_start();
require_once 'db.php';

// Проверка куки "Запомнить меня"
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->execute([$_COOKIE['remember_token']]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
    }
}

// Если после всех проверок сессии нет — отправляем на страницу входа
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Переменные для удобного использования на страницах
$current_user_id = $_SESSION['user_id'];
$current_user_role = $_SESSION['user_role'];
$current_user_name = $_SESSION['user_name'];
?>