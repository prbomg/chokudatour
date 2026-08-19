<?php
// cron_email.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// === 1. НАСТРОЙКИ БАЗЫ ДАННЫХ И ПОЧТЫ ===
$host = 'localhost';
$db   = 'cc47946_devcrm'; // Укажи имя базы
$user = 'cc47946_devcrm'; // Укажи пользователя БД
$pass = '146580Serg!';    // Укажи пароль БД
$charset = 'utf8mb4';

$admin_email = 'rubcov@my.com'; // УКАЖИ СВОЙ EMAIL

// === 2. СЕКРЕТНЫЙ ТОКЕН ЗАЩИТЫ ===
$secret_token = 'super_secret_tour_2026'; 
if (!isset($_GET['token']) || $_GET['token'] !== $secret_token) {
    die('Доступ закрыт.');
}

// Подключаемся к базе
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Ошибка подключения к БД: " . $e->getMessage());
}

// Авто-добавление колонки дефолтного времени в tours_catalog
try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN default_start_time VARCHAR(50) DEFAULT '10:00'"); } catch(PDOException $e) {}

// === 3. УМНЫЙ ПОИСК КОЛОНОК ===
$events_cols = $pdo->query("SHOW COLUMNS FROM events")->fetchAll(PDO::FETCH_COLUMN);

$date_col = 'date';
foreach(['date', 'event_date', 'tour_date', 'start_date', 'day'] as $col) {
    if(in_array($col, $events_cols)) { $date_col = $col; break; }
}

$time_col = '';
foreach(['time', 'event_time', 'tour_time', 'start_time'] as $col) {
    if(in_array($col, $events_cols)) { $time_col = $col; break; }
}

$action = $_GET['action'] ?? 'daily'; // daily, weekly, today, tomorrow

// === 4. ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ===
function getTourStartTime($event, $time_col) {
    if ($time_col && !empty($event[$time_col])) {
        return htmlspecialchars($event[$time_col]);
    }
    if (!empty($event['default_start_time'])) {
        return htmlspecialchars($event['default_start_time']);
    }
    return '10:00 (по умолч.)';
}

function getGuideName($ev, $pdo) {
    $guide_val = '';
    foreach (['guide_id', 'guide', 'guide_name'] as $col) {
        if (isset($ev[$col]) && $ev[$col] !== '') { $guide_val = $ev[$col]; break; }
    }
    if ($guide_val === '') return 'Не назначен';
    if (is_numeric($guide_val) && $guide_val > 0) {
        try {
            $stmt = $pdo->prepare("SELECT name FROM guides WHERE id = ?");
            $stmt->execute([$guide_val]);
            return $stmt->fetchColumn() ?: 'Не назначен';
        } catch (\Exception $e) { return 'Не назначен'; }
    }
    return $guide_val;
}

function renderTourCardHTML($ev, $pdo, $time_col) {
    $guide_name = getGuideName($ev, $pdo);
    $time_val = getTourStartTime($ev, $time_col);
    $date_val = !empty($ev['event_date_formatted']) ? $ev['event_date_formatted'] : '';
    
    // Используем блочную верстку (без flex), чтобы на мобилках ничего не ломалось
    $html = "
    <div style='background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.02);'>
        
        <div style='border-bottom: 2px solid #F1F5F9; padding-bottom: 12px; margin-bottom: 14px;'>
            <h3 style='margin: 0 0 8px 0; font-size: 18px; font-weight: 800; color: #0F172A; line-height: 1.3;'>" . htmlspecialchars($ev['tour_name'] ?? 'Маршрут') . "</h3>
            " . ($date_val ? "<div style='display: inline-block; background: #EEF2FF; color: #4F46E5; font-weight: 800; font-size: 12px; padding: 4px 10px; border-radius: 99px;'>📅 {$date_val}</div>" : "") . "
        </div>
        
        <div style='margin-bottom: 16px; font-size: 14px; color: #475569;'>
            <div style='display: inline-block; background: #F8FAFC; padding: 8px 14px; border-radius: 8px; border: 1px solid #E2E8F0; margin-bottom: 8px; margin-right: 8px;'>
                ⏱ <b>Время:</b> <span style='color: #4F46E5; font-weight: 800;'>{$time_val}</span>
            </div>
            <div style='display: inline-block; background: #F8FAFC; padding: 8px 14px; border-radius: 8px; border: 1px solid #E2E8F0; margin-bottom: 8px;'>
                👤 <b>Гид:</b> <span style='color: #0F172A; font-weight: 700;'>" . htmlspecialchars($guide_name) . "</span>
            </div>
        </div>";

    try {
        $stmt_part = $pdo->prepare("SELECT * FROM participants WHERE event_id = ?");
        $stmt_part->execute([$ev['id']]);
        $participants = $stmt_part->fetchAll();

        if (count($participants) > 0) {
            $html .= "<div style='font-size: 13px; font-weight: 800; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px;'>Список туристов:</div>";
            $html .= "<ul style='margin: 0; padding: 0; list-style: none;'>";
            foreach ($participants as $p) {
                $p_name = 'Без имени';
                foreach (['name', 'fio', 'full_name', 'client', 'client_name', 'tourist'] as $col) {
                    if (!empty($p[$col])) { $p_name = htmlspecialchars($p[$col]); break; }
                }
                $p_phone = 'Без телефона';
                foreach (['phone', 'tel', 'telephone', 'contact'] as $col) {
                    if (!empty($p[$col])) { $p_phone = htmlspecialchars($p[$col]); break; }
                }

                $p_places = '';
                foreach (['people_count', 'people', 'count', 'guests', 'places', 'tickets', 'qty', 'seats', 'persons'] as $col) {
                    if (!empty($p[$col])) { $p_places = "👥 " . htmlspecialchars($p[$col]) . " чел."; break; }
                }

                $p_amount = '';
                foreach (['price', 'amount', 'sum', 'total', 'to_pay', 'payment', 'debt', 'cost'] as $col) {
                    if (isset($p[$col]) && $p[$col] !== '') { $p_amount = "💰 " . number_format($p[$col], 0, '', ' ') . " ₽"; break; }
                }

                $status_tag = !empty($p['status']) ? "<span style='display: inline-block; background: #E2E8F0; color: #334155; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; margin-right: 6px; margin-bottom: 4px;'>" . htmlspecialchars($p['status']) . "</span>" : "";
                $note = !empty($p['note']) ? "<div style='color: #64748B; font-size: 13px; margin-top: 6px; line-height: 1.4; border-left: 2px solid #CBD5E1; padding-left: 8px;'>📝 " . htmlspecialchars($p['note']) . "</div>" : "";

                $fin_pills = array_filter([$p_places, $p_amount]);
                $fin_html = !empty($fin_pills) ? "<span style='display: inline-block; background: #ECFDF5; color: #047857; font-weight: 800; font-size: 12px; padding: 3px 8px; border-radius: 6px; border: 1px solid #A7F3D0; margin-bottom: 4px;'>" . implode(" • ", $fin_pills) . "</span>" : "";

                $html .= "
                <li style='padding: 14px 12px; background: #F8FAFC; border-radius: 8px; margin-bottom: 10px; border: 1px solid #F1F5F9; display: block;'>
                    <div style='font-size: 15px; font-weight: 700; color: #0F172A; margin-bottom: 8px;'>
                        {$p_name} <span style='font-weight: 500; color: #64748B; font-size: 14px;'>({$p_phone})</span>
                    </div>
                    <div style='margin-bottom: 4px;'>
                        {$status_tag} {$fin_html}
                    </div>
                    {$note}
                </li>";
            }
            $html .= "</ul>";
        } else {
            $html .= "<div style='color: #EF4444; font-size: 13px; font-weight: 600; padding: 10px 14px; background: #FEF2F2; border-radius: 6px; border: 1px solid #FECACA;'>❌ Нет записанных туристов</div>";
        }
    } catch (\PDOException $e) {
        $html .= "<div style='color: #EF4444; font-size: 13px;'>❌ Ошибка участников</div>";
    }

    $html .= "</div>";
    return $html;
}

function sendStyledEmail($to, $subject, $title_header, $body_content) {
    $message = "
    <!DOCTYPE html>
    <html lang='ru'>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #F1F5F9; margin: 0; padding: 30px 10px; -webkit-font-smoothing: antialiased; }
            .mail-card { max-width: 600px; margin: 0 auto; background: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; }
            .header-banner { background: linear-gradient(135deg, #4F46E5 0%, #3730A3 100%); padding: 30px 25px; text-align: center; color: #FFFFFF; }
            .header-banner h1 { margin: 0; font-size: 22px; font-weight: 900; letter-spacing: -0.02em; line-height: 1.2; }
            .header-banner p { margin: 8px 0 0 0; font-size: 14px; opacity: 0.85; font-weight: 500; }
            .mail-body { padding: 30px 25px; background: #F8FAFC; }
            .mail-footer { text-align: center; padding: 20px; font-size: 12px; color: #94A3B8; background: #FFFFFF; border-top: 1px solid #E2E8F0; line-height: 1.5; }
            
            /* Адаптив для телефонов */
            @media only screen and (max-width: 600px) {
                body { padding: 0 !important; background-color: #FFFFFF !important; }
                .mail-card { border-radius: 0 !important; border: none !important; box-shadow: none !important; width: 100% !important; max-width: 100% !important; }
                .header-banner { padding: 25px 15px !important; }
                .mail-body { padding: 20px 15px !important; }
            }
        </style>
    </head>
    <body>
        <div class='mail-card'>
            <div class='header-banner'>
                <h1>{$title_header}</h1>
                <p>CRM Авторские туры • Автоматическая сводка</p>
            </div>
            <div class='mail-body'>
                {$body_content}
            </div>
            <div class='mail-footer'>
                Сообщение сгенерировано системой управления турами.<br>
                Пожалуйста, не отвечайте на это письмо.
            </div>
        </div>
    </body>
    </html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: CRM Туры <no-reply@" . $_SERVER['HTTP_HOST'] . ">\r\n";

    return mail($to, $subject, $message, $headers);
}

// === 5. ЛОГИКА ВЫБОРКИ ПО РЕЖИМАМ ===

$order_by_sql = $time_col ? "ORDER BY e.{$time_col} ASC" : "";

if ($action === 'daily' || $action === 'today_tomorrow') {
    // 1. ТУРЫ НА СЕГОДНЯ
    $today = date('Y-m-d');
    $stmt_today = $pdo->prepare("
        SELECT e.*, t.name as tour_name, t.default_start_time 
        FROM events e 
        LEFT JOIN tours_catalog t ON e.tour_id = t.id 
        WHERE e.{$date_col} = ? {$order_by_sql}
    ");
    $stmt_today->execute([$today]);
    $events_today = $stmt_today->fetchAll();

    // 2. ТУРЫ НА ЗАВТРА (Напоминание за 1 сутки)
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $stmt_tom = $pdo->prepare("
        SELECT e.*, t.name as tour_name, t.default_start_time 
        FROM events e 
        LEFT JOIN tours_catalog t ON e.tour_id = t.id 
        WHERE e.{$date_col} = ? {$order_by_sql}
    ");
    $stmt_tom->execute([$tomorrow]);
    $events_tom = $stmt_tom->fetchAll();

    if (count($events_today) === 0 && count($events_tom) === 0) {
        echo "На сегодня и завтра туров нет. Письмо не отправлено."; exit;
    }

    $body_content = "";
    if (count($events_today) > 0) {
        $body_content .= "<h2 style='font-size: 18px; font-weight: 900; color: #0F172A; margin: 0 0 15px 0;'>🔥 Сегодня (" . date('d.m.Y') . "):</h2>";
        foreach ($events_today as $ev) { $body_content .= renderTourCardHTML($ev, $pdo, $time_col); }
    } else {
        $body_content .= "<div style='background:#FFF; padding:15px; border-radius:10px; margin-bottom:25px; color:#64748B; font-size:14px; text-align:center; border: 1px solid #E2E8F0;'>Сегодня экскурсий нет.</div>";
    }

    if (count($events_tom) > 0) {
        $body_content .= "<h2 style='font-size: 18px; font-weight: 900; color: #0F172A; margin: 25px 0 15px 0;'>🔔 Завтра (" . date('d.m.Y', strtotime('+1 day')) . "):</h2>";
        foreach ($events_tom as $ev) { $body_content .= renderTourCardHTML($ev, $pdo, $time_col); }
    }

    $subject = "📅 Сводка туров на сегодня (" . date('d.m') . ") и завтра (" . date('d.m', strtotime('+1 day')) . ")";
    $sent = sendStyledEmail($admin_email, $subject, "Ежедневный дайджест туров", $body_content);
    echo $sent ? "Ежедневное письмо успешно отправлено!" : "Ошибка отправки почты.";

} elseif ($action === 'weekly') {
    // ТУРЫ НА ПРЕДСТОЯЩУЮ НЕДЕЛЮ (Пн - Вс)
    $monday_ts = strtotime('monday this week');
    $sunday_ts = strtotime('sunday this week');
    
    $start_week = date('Y-m-d', $monday_ts);
    $end_week = date('Y-m-d', $sunday_ts);

    // ИСПРАВЛЕНИЕ ОШИБКИ 1064: Безопасное формирование ORDER BY
    $order_by_weekly = "ORDER BY e.{$date_col} ASC" . ($time_col ? ", e.{$time_col} ASC" : "");

    $stmt_week = $pdo->prepare("
        SELECT e.*, t.name as tour_name, t.default_start_time 
        FROM events e 
        LEFT JOIN tours_catalog t ON e.tour_id = t.id 
        WHERE e.{$date_col} BETWEEN ? AND ? 
        {$order_by_weekly}
    ");
    $stmt_week->execute([$start_week, $end_week]);
    $events_week = $stmt_week->fetchAll();

    if (count($events_week) === 0) {
        echo "На предстоящую неделю (" . date('d.m', $monday_ts) . " - " . date('d.m', $sunday_ts) . ") туров нет. Письмо не отправлено."; exit;
    }

    $body_content = "<h2 style='font-size: 18px; font-weight: 900; color: #0F172A; margin: 0 0 20px 0;'>📊 Расписание на неделю (" . date('d.m', $monday_ts) . " — " . date('d.m.Y', $sunday_ts) . "):</h2>";
    foreach ($events_week as $ev) {
        $ev['event_date_formatted'] = date('d.m (D)', strtotime($ev[$date_col]));
        $body_content .= renderTourCardHTML($ev, $pdo, $time_col);
    }

    $subject = "🗓️ Недельный план туров (" . date('d.m', $monday_ts) . " — " . date('d.m', $sunday_ts) . ")";
    $sent = sendStyledEmail($admin_email, $subject, "План туров на неделю", $body_content);
    echo $sent ? "Недельный отчет успешно отправлен!" : "Ошибка отправки почты.";
}
?>