<?php
require_once __DIR__ . '/app_config.php';
$config = appConfig();
$host = requiredConfig($config, 'DB_HOST');
$db   = requiredConfig($config, 'DB_NAME');
$user = requiredConfig($config, 'DB_USER');
$pass = requiredConfig($config, 'DB_PASSWORD');
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
try { $pdo->exec("ALTER TABLE participants ADD COLUMN ticket_token VARCHAR(64) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN max_group_size INT DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN is_archived TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE events ADD COLUMN time VARCHAR(50) DEFAULT ''"); } catch (Exception $e) {}
$missingTicketTokens = $pdo->query("SELECT id FROM participants WHERE ticket_token IS NULL OR ticket_token='' LIMIT 1000")->fetchAll(PDO::FETCH_COLUMN);
$ticketTokenUpdate = $pdo->prepare('UPDATE participants SET ticket_token=? WHERE id=?');
foreach ($missingTicketTokens as $participantId) $ticketTokenUpdate->execute([bin2hex(random_bytes(16)), (int)$participantId]);

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
try { $pdo->exec("ALTER TABLE users ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}

if ($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() == 0) {
    $initialEmail = trim($config['INITIAL_ADMIN_EMAIL']);
    $initialPassword = (string)$config['INITIAL_ADMIN_PASSWORD'];
    if ($initialEmail !== '' && $initialPassword !== '') {
        $hash = password_hash($initialPassword, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')")
            ->execute([$config['INITIAL_ADMIN_NAME'], $initialEmail, $hash]);
    }
}

$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT
)");
try { $pdo->exec("ALTER TABLE expenses ADD COLUMN receipt_path VARCHAR(255) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE expenses ADD COLUMN category VARCHAR(100) DEFAULT 'Прочее'"); } catch (Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS statuses (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, sort_order INT DEFAULT 0)");
if ($pdo->query("SELECT COUNT(*) FROM statuses")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO statuses (name, sort_order) VALUES ('Бронь', 1), ('Предоплата', 2), ('Оплачено', 3), ('Оплата на месте', 4), ('Отмена', 5)");
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM statuses WHERE name=?'); $stmt->execute(['Оплата на месте']);
if (!$stmt->fetchColumn()) $pdo->prepare('INSERT INTO statuses (name, sort_order) VALUES (?, ?)')->execute(['Оплата на месте', 4]);

$pdo->exec("CREATE TABLE IF NOT EXISTS booking_sources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, sort_order INT DEFAULT 999)");
if ($pdo->query('SELECT COUNT(*) FROM booking_sources')->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO booking_sources (name, sort_order) VALUES ('Прямые',1),('Трипстер',2),('Спутник 8',3),('CRM',4),('Сайт',5)");
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
