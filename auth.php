<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/auth_helpers.php';

// Проверка куки "Запомнить меня"
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE remember_token = ?");
    $stmt->execute([hash('sha256', (string)$_COOKIE['remember_token'])]);
    $user = $stmt->fetch();
    
    if ($user) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['name'];
        issueRememberToken($pdo, (int)$user['id']);
    } else {
        clearRememberToken($pdo);
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
