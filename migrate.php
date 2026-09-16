<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('CHOKUDATOUR_SKIP_AUTO_MIGRATIONS', true);
require_once __DIR__ . '/db.php';

$command = $argv[1] ?? 'up';
$migrations = applicationMigrations();
$latest = $migrations ? max(array_keys($migrations)) : 0;

if ($command === 'status') {
    $current = databaseSchemaVersion($pdo);
    $pending = databasePendingMigrations($pdo);
    echo "Текущая версия: {$current}" . PHP_EOL;
    echo "Последняя версия: {$latest}" . PHP_EOL;
    echo $pending ? 'Ожидают применения: ' . implode(', ', $pending) . PHP_EOL : "Структура базы актуальна." . PHP_EOL;
    exit($pending ? 1 : 0);
}

if ($command !== 'up') {
    fwrite(STDERR, "Использование: php migrate.php [up|status]" . PHP_EOL);
    exit(2);
}

$applied = runDatabaseMigrations($pdo);
if ($applied) {
    echo 'Применены миграции: ' . implode(', ', $applied) . PHP_EOL;
} else {
    echo "Новых миграций нет." . PHP_EOL;
}
echo 'Версия структуры базы: ' . databaseSchemaVersion($pdo) . PHP_EOL;
