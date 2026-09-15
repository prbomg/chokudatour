<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/participant_seats.php';
require_once 'db.php';

function icsEscape($value): string
{
    return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'], (string)$value);
}

function icsTextLine(string $name, $value): void
{
    $line = $name . ':' . icsEscape($value);
    $first = true;
    while ($line !== '') {
        $limit = $first ? 73 : 72;
        $chunk = mb_strcut($line, 0, $limit, 'UTF-8');
        echo ($first ? '' : ' ') . $chunk . "\r\n";
        $line = substr($line, strlen($chunk));
        $first = false;
    }
}

// Проверяем / создаем секретный токен для Админа
$admin_token = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'admin_sync_token'")->fetchColumn();
if (!$admin_token) {
    $admin_token = bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('admin_sync_token', ?)")->execute([$admin_token]);
}

$token = $_GET['token'] ?? '';
if (empty($token)) {
    http_response_code(403);
    die('Токен не указан');
}

$is_admin = hash_equals((string)$admin_token, (string)$token);
$guide_name = '';

if (!$is_admin) {
    // Ищем конкретного гида
    $stmt = $pdo->prepare("SELECT name FROM guides WHERE sync_token = ?");
    $stmt->execute([$token]);
    $guide_name = $stmt->fetchColumn();

    if (!$guide_name) {
        http_response_code(403);
        die('Неверный токен');
    }
}

// Заголовки для правильного распознавания файла как календаря
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="schedule.ics"');

echo "BEGIN:VCALENDAR\r\n";
echo "VERSION:2.0\r\n";
echo "PRODID:-//ChokudaTour CRM//RU\r\n";
echo "CALSCALE:GREGORIAN\r\n";
echo "METHOD:PUBLISH\r\n";
icsTextLine('X-WR-CALNAME', $is_admin ? 'CRM Все Экскурсии (АДМИН)' : 'Расписание: ' . $guide_name);
echo "X-WR-TIMEZONE:Europe/Moscow\r\n";

// --- 1. ВЫВОДИМ ЭКСКУРСИИ ---
if ($is_admin) {
    // Для админа выбираем ВСЕ экскурсии
    $sql = "SELECT e.id, e.tour_date, e.time, e.guide, e.notes, t.name AS tour_name, t.duration
            FROM events e 
            JOIN tours_catalog t ON e.tour_id = t.id 
            WHERE e.tour_date >= CURDATE() - INTERVAL 15 DAY";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    // Для гида — только его
    $sql = "SELECT e.id, e.tour_date, e.time, e.guide, e.notes, t.name AS tour_name, t.duration
            FROM events e 
            JOIN tours_catalog t ON e.tour_id = t.id 
            WHERE e.guide = ? AND e.tour_date >= CURDATE() - INTERVAL 15 DAY";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$guide_name]);
}

$events = $stmt->fetchAll();

foreach ($events as $ev) {
    $event_id = $ev['id'];
    $tour_date = date('Ymd', strtotime($ev['tour_date']));
    $tour_date_end = date('Ymd', strtotime($ev['tour_date'] . ' +1 day'));
    
    // Получаем участников
    $p_stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id = ? AND status != 'Отмена'");
    $p_stmt->execute([$event_id]);
    $participants = $p_stmt->fetchAll();
    
    $total_seats = 0;
    $desc = "МАРШРУТ: " . $ev['tour_name'] . "\n";
    $desc .= "ГИД: " . ($ev['guide'] ?: 'Не назначен') . "\n";
    $desc .= "Длительность: " . ($ev['duration'] ?: 'не указана') . "\n\n";
    if (!empty($ev['notes'])) $desc .= "ПРИМЕЧАНИЕ: " . $ev['notes'] . "\n\n";
    $desc .= $participants ? "ТУРИСТЫ:\n" : "ТУРИСТОВ ПОКА НЕТ\n";
    
    foreach ($participants as $p) {
        $total_seats += participantSeats($p);
        $desc .= "👤 " . $p['client_name'] . " (" . participantSeats($p) . " чел.)\n";
        $desc .= "📞 Тел: " . $p['phone'] . "\n";
        if (!empty($p['notes'])) {
            $clean_note = str_replace(["\r", "\n"], " ", $p['notes']);
            $desc .= "💬 " . $clean_note . "\n";
        }
        $desc .= "\n";
    }
    
    $summary = "📍 " . $ev['tour_name'];
    if ($is_admin) {
        $summary .= " [" . ($ev['guide'] ?: 'Без гида') . "]";
    }
    $summary .= " ({$total_seats} чел.)";

    echo "BEGIN:VEVENT\r\n";
    echo "UID:event-{$event_id}@chokudatour.ru\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    $eventTime = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', (string)($ev['time'] ?? '')) ? $ev['time'] : '';
    if ($eventTime !== '') {
        $start = new DateTimeImmutable($ev['tour_date'] . ' ' . $eventTime, new DateTimeZone('Europe/Moscow'));
        preg_match('/([0-9]+(?:[.,][0-9]+)?)/', (string)($ev['duration'] ?? ''), $durationMatch);
        $durationValue = isset($durationMatch[1]) ? (float)str_replace(',', '.', $durationMatch[1]) : 1;
        $durationMinutes = max(30, (int)round($durationValue * (preg_match('/дн|день|дня/i', (string)($ev['duration'] ?? '')) ? 1440 : 60)));
        echo 'DTSTART;TZID=Europe/Moscow:' . $start->format('Ymd\THis') . "\r\n";
        echo 'DTEND;TZID=Europe/Moscow:' . $start->modify("+{$durationMinutes} minutes")->format('Ymd\THis') . "\r\n";
    } else {
        echo "DTSTART;VALUE=DATE:{$tour_date}\r\n";
        echo "DTEND;VALUE=DATE:{$tour_date_end}\r\n";
    }
    icsTextLine('SUMMARY', $summary);
    icsTextLine('DESCRIPTION', $desc);
    echo "END:VEVENT\r\n";
}

// --- 2. ВЫВОДИМ ОТГУЛЫ ГИДОВ (Для Админа) ---
if ($is_admin) {
    $to_stmt = $pdo->query("SELECT * FROM guide_timeoffs WHERE date_off >= CURDATE() - INTERVAL 15 DAY");
    $timeoffs = $to_stmt->fetchAll();

    foreach ($timeoffs as $to) {
        $t_date = date('Ymd', strtotime($to['date_off']));
        $t_date_end = date('Ymd', strtotime($to['date_off'] . ' +1 day'));
        $t_id = $to['id'];

        echo "BEGIN:VEVENT\r\n";
        echo "UID:timeoff-{$t_id}@chokudatour.ru\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART;VALUE=DATE:{$t_date}\r\n";
        echo "DTEND;VALUE=DATE:{$t_date_end}\r\n";
        icsTextLine('SUMMARY', '🏖️ Отгул: ' . $to['guide_name']);
        icsTextLine('DESCRIPTION', 'Гид ' . $to['guide_name'] . ' в отгуле/отпуске. ' . ($to['reason'] ? 'Причина: ' . $to['reason'] : ''));
        echo "END:VEVENT\r\n";
    }
}

echo "END:VCALENDAR\r\n";
?>
