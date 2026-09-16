<?php
require_once __DIR__ . '/app_config.php';
$config = appConfig();
$host = requiredConfig($config, 'DB_HOST');
$db   = requiredConfig($config, 'DB_NAME');
$user = requiredConfig($config, 'DB_USER');
$pass = requiredConfig($config, 'DB_PASSWORD');

$pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

require_once __DIR__ . '/migrations.php';
if (!defined('CHOKUDATOUR_SKIP_AUTO_MIGRATIONS')) {
    runDatabaseMigrations($pdo);
}

// Создание первого администратора — настройка приложения, а не изменение схемы.
if (!defined('CHOKUDATOUR_SKIP_AUTO_MIGRATIONS') && migrationTableExists($pdo, 'users') && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
    $initialEmail = trim((string)$config['INITIAL_ADMIN_EMAIL']);
    $initialPassword = (string)$config['INITIAL_ADMIN_PASSWORD'];
    if ($initialEmail !== '' && $initialPassword !== '') {
        $pdo->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?,'admin')")
            ->execute([(string)$config['INITIAL_ADMIN_NAME'], $initialEmail, password_hash($initialPassword, PASSWORD_DEFAULT)]);
    }
}
