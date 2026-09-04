<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/participant_seats.php';
require_once 'db.php';

// Проверяем / создаем секретный токен для Админа
$admin_token = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'admin_sync_token'")->fetchColumn();
if (!$admin_token) {
    $admin_token = substr(md5(uniqid(rand(), true)), 0, 20);
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('admin_sync_token', ?)")->execute([$admin_token]);
}

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die('Токен не указан');
}

$is_admin = ($token === $admin_token);
$guide_name = '';

if (!$is_admin) {
    // Ищем конкретного гида
    $stmt = $pdo->prepare("SELECT name FROM guides WHERE sync_token = ?");
    $stmt->execute([$token]);
    $guide_name = $stmt->fetchColumn();

    if (!$guide_name) {
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
echo "X-WR-CALNAME:" . ($is_admin ? "CRM Все Экскурсии (АДМИН)" : "Расписание: " . $guide_name) . "\r\n";
echo "X-WR-TIMEZONE:Europe/Moscow\r\n";

// --- 1. ВЫВОДИМ ЭКСКУРСИИ ---
if ($is_admin) {
    // Для админа выбираем ВСЕ экскурсии
    $sql = "SELECT e.id, e.tour_date, e.guide, e.notes, t.name AS tour_name, t.duration 
            FROM events e 
            JOIN tours_catalog t ON e.tour_id = t.id 
            WHERE e.tour_date >= CURDATE() - INTERVAL 15 DAY";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
} else {
    // Для гида — только его
    $sql = "SELECT e.id, e.tour_date, e.guide, e.notes, t.name AS tour_name, t.duration 
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
    
    if (empty($participants)) continue;
    
    $total_seats = 0;
    
    $desc = "МАРШРУТ: " . $ev['tour_name'] . "\\n";
    $desc .= "ГИД: " . ($ev['guide'] ?: 'Не назначен') . "\\n";
    $desc .= "Длительность: " . ($ev['duration'] ?: 'не указана') . "\\n\\n";
    $desc .= "ТУРИСТЫ:\\n";
    
    foreach ($participants as $p) {
        $total_seats += participantSeats($p);
        $desc .= "👤 " . $p['client_name'] . " (" . participantSeats($p) . " чел.)\\n";
        $desc .= "📞 Тел: " . $p['phone'] . "\\n";
        if (!empty($p['notes'])) {
            $clean_note = str_replace(["\r", "\n"], " ", $p['notes']);
            $desc .= "💬 " . $clean_note . "\\n";
        }
        $desc .= "\\n";
    }
    
    $summary = "📍 " . $ev['tour_name'];
    if ($is_admin) {
        $summary .= " [" . ($ev['guide'] ?: 'Без гида') . "]";
    }
    $summary .= " ({$total_seats} чел.)";

    echo "BEGIN:VEVENT\r\n";
    echo "UID:event-{$event_id}@chokudatour.ru\r\n";
    echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
    echo "DTSTART;VALUE=DATE:{$tour_date}\r\n";
    echo "DTEND;VALUE=DATE:{$tour_date_end}\r\n";
    echo "SUMMARY:{$summary}\r\n";
    echo "DESCRIPTION:{$desc}\r\n";
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
        echo "SUMMARY:🏖️ Отгул: " . $to['guide_name'] . "\r\n";
        echo "DESCRIPTION:Гид " . $to['guide_name'] . " в отгуле/отпуске. " . ($to['reason'] ? "Причина: " . $to['reason'] : "") . "\r\n";
        echo "END:VEVENT\r\n";
    }
}

echo "END:VCALENDAR\r\n";
?>