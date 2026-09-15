<?php
function appConfig(): array
{
    static $config;
    if ($config !== null) return $config;
    $local = [];
    $localFile = __DIR__ . '/config.local.php';
    if (is_file($localFile)) {
        $loaded = require $localFile;
        if (is_array($loaded)) $local = $loaded;
    }
    $read = static function (string $key, string $default = '') use ($local): string {
        $env = getenv($key);
        if ($env !== false && $env !== '') return $env;
        return isset($local[$key]) ? (string)$local[$key] : $default;
    };
    return $config = [
        'DB_HOST' => $read('DB_HOST', 'localhost'),
        'DB_NAME' => $read('DB_NAME'),
        'DB_USER' => $read('DB_USER'),
        'DB_PASSWORD' => $read('DB_PASSWORD'),
        'CRON_TOKEN' => $read('CRON_TOKEN'),
        'ADMIN_EMAIL' => $read('ADMIN_EMAIL'),
        'INITIAL_ADMIN_NAME' => $read('INITIAL_ADMIN_NAME', 'Администратор'),
        'INITIAL_ADMIN_EMAIL' => $read('INITIAL_ADMIN_EMAIL'),
        'INITIAL_ADMIN_PASSWORD' => $read('INITIAL_ADMIN_PASSWORD'),
    ];
}

function requiredConfig(array $config, string $key): string
{
    $value = (string)($config[$key] ?? '');
    if (trim($value) === '') throw new RuntimeException("Не задан параметр конфигурации {$key}.");
    return $value;
}
