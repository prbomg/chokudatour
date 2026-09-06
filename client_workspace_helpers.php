<?php

function ensureClientWorkspace(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_profiles (phone VARCHAR(50) PRIMARY KEY, tags VARCHAR(255) DEFAULT '', global_note TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS global_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM global_settings WHERE setting_key='client_tags'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $pdo->prepare('INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?)')->execute(['client_tags', 'VIP,Лояльный,Семья с детьми,Сложный клиент,Черный список']);
    }
}

function clientTagsFromString($value): array
{
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$value)))));
}

function validateClientTag($value): string
{
    $tag = trim((string)$value);
    if ($tag === '' || mb_strlen($tag) > 50 || str_contains($tag, ',')) throw new InvalidArgumentException('Название тега должно содержать от 1 до 50 символов без запятых.');
    return $tag;
}

function clientTagStyle(string $tag): string
{
    $hue = abs(crc32($tag)) % 360;
    return "--tag-bg:hsl({$hue},65%,94%);--tag-color:hsl({$hue},55%,29%)";
}

function clientMoney($amount): string
{
    return number_format((float)$amount, 0, ',', ' ') . ' ₽';
}

function clientCountPhrase(int $count, array $forms): string
{
    $mod100 = $count % 100; $mod10 = $count % 10;
    $form = ($mod100 >= 11 && $mod100 <= 14) ? $forms[2] : ($mod10 === 1 ? $forms[0] : (($mod10 >= 2 && $mod10 <= 4) ? $forms[1] : $forms[2]));
    return $count . ' ' . $form;
}

function clientListUrl(array $input): string
{
    $filters = [];
    $search = trim((string)($input['search'] ?? ''));
    $tag = trim((string)($input['tag'] ?? ''));
    $tourId = (int)($input['tour_id'] ?? 0);
    if ($search !== '') $filters['search'] = $search;
    if ($tourId > 0) $filters['tour_id'] = $tourId;
    if ($tag !== '') $filters['tag'] = $tag;
    $query = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
    return 'clients.php' . ($query === '' ? '' : '?' . $query);
}
