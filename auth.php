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
        $_SESSION['guide_id'] = $user['role'] === 'guide' ? (int)($user['guide_id'] ?? 0) : null;
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
$current_user_guide_id = $current_user_role === 'guide' ? (int)($_SESSION['guide_id'] ?? 0) : null;
if ($current_user_role === 'guide' && !$current_user_guide_id) {
    $stmt = $pdo->prepare('SELECT guide_id FROM users WHERE id=?'); $stmt->execute([(int)$current_user_id]);
    $current_user_guide_id = (int)$stmt->fetchColumn();
    $_SESSION['guide_id'] = $current_user_guide_id;
}
?>
