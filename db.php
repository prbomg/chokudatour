<?php
$host = 'localhost';
$db   = 'cc47946_devcrm';
$user = 'cc47946_devcrm';
$pass = '146580Serg!';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

try { $pdo->exec("ALTER TABLE participants ADD COLUMN phone VARCHAR(50) DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE participants ADD COLUMN email VARCHAR(100) DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE participants ADD COLUMN notes TEXT"); } catch (Exception $e) {}

// Таблица пользователей с полями для восстановления пароля
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'guide') NOT NULL DEFAULT 'guide',
    remember_token VARCHAR(255) DEFAULT NULL,
    reset_token VARCHAR(255) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL
)");

// Безопасное добавление полей сброса для старой таблицы users, если она уже существовала
try { $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE users ADD COLUMN reset_expires DATETIME DEFAULT NULL"); } catch (Exception $e) {}

if ($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() == 0) {
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Главный Админ', 'admin@site.ru', '$hash', 'admin')");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS statuses (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT DEFAULT 0)");
if ($pdo->query("SELECT COUNT(*) FROM statuses")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO statuses (name, sort_order) VALUES ('Бронь', 1), ('Предоплата', 2), ('Оплачено', 3), ('Отмена', 4)");
}

$pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT DEFAULT 0)");
if ($pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO expense_categories (name, sort_order) VALUES ('Аренда транспорта', 1), ('Бензин', 2), ('Билеты в музей', 3), ('Обед', 4), ('Зарплата гида', 5), ('Другое', 6)");
}

$tables_to_patch = ['tours_catalog', 'guides', 'sources', 'statuses', 'expense_categories'];
foreach ($tables_to_patch as $tbl) {
    try { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN sort_order INT DEFAULT 0"); } catch (Exception $e) {}
}
?>