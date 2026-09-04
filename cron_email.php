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
    $date_val = !empty($ev['event_date_formatted']) ? trim($ev['event_date_formatted']) : '';
    
    // ВЕРСТКА КАРТОЧКИ ТУРА (Modern SaaS Style)
    $html = "
    <div style='background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; margin-bottom: 24px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);'>
        
        <div style='background: #ffffff; padding: 24px; border-bottom: 1px solid #f1f5f9;'>
            " . ($date_val ? "<div style='display: inline-block; background: #eff6ff; color: #4338ca; font-size: 13px; font-weight: 700; padding: 6px 14px; border-radius: 20px; margin-bottom: 16px; text-transform: uppercase; letter-spacing: 0.05em;'>📅 {$date_val}</div>" : "") . "
            
            <h3 style='margin: 0 0 16px 0; font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.3;'>" . htmlspecialchars($ev['tour_name'] ?? 'Маршрут') . "</h3>
            
            <div style='display: block; font-size: 14px; color: #475569;'>
                <span style='display: inline-block; background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 16px; border-radius: 8px; margin-right: 8px; margin-bottom: 8px;'>
                    <span style='color: #64748b;'>⏱ Старт:</span> <strong style='color: #0f172a; font-size: 15px;'>{$time_val}</strong>
                </span>
                <span style='display: inline-block; background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 16px; border-radius: 8px; margin-bottom: 8px;'>
                    <span style='color: #64748b;'>👤 Гид:</span> <strong style='color: #0f172a; font-size: 15px;'>" . htmlspecialchars($guide_name) . "</strong>
                </span>
            </div>
        </div>
        
        <div style='padding: 24px;'>";

    try {
        $stmt_part = $pdo->prepare("SELECT * FROM participants WHERE event_id = ?");
        $stmt_part->execute([$ev['id']]);
        $participants = $stmt_part->fetchAll();

        $count_pax = count($participants);
        if ($count_pax > 0) {
            $html .= "<h4 style='font-size: 12px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 16px 0;'>Список туристов ({$count_pax} броней):</h4>";
            
            foreach ($participants as $p) {
                // Ищем имя
                $p_name = 'Без имени';
                foreach (['client_name', 'name', 'fio', 'full_name', 'client', 'tourist'] as $col) {
                    if (!empty($p[$col])) { $p_name = htmlspecialchars($p[$col]); break; }
                }
                
                // Ищем телефон
                $p_phone = 'Без телефона';
                foreach (['phone', 'tel', 'telephone', 'contact'] as $col) {
                    if (!empty($p[$col])) { $p_phone = htmlspecialchars($p[$col]); break; }
                }

                // СТРОГИЙ ПРИОРИТЕТ ДЛЯ КОЛИЧЕСТВА МЕСТ
                $p_places_val = $p['places'] ?? $p['seats'] ?? 1;
                $p_places = "👤 " . htmlspecialchars($p_places_val) . " чел.";

                // Ищем стоимость
                $p_amount = '';
                foreach (['price', 'amount', 'sum', 'total', 'to_pay', 'payment', 'debt', 'cost'] as $col) {
                    if (isset($p[$col]) && $p[$col] !== '') { $p_amount = "💰 " . number_format($p[$col], 0, '', ' ') . " ₽"; break; }
                }

                // Стиль статуса
                $status_tag = "";
                if (!empty($p['status'])) {
                    $s_text = htmlspecialchars($p['status']);
                    $bg = '#e2e8f0'; $col = '#334155';
                    if (mb_stripos($s_text, 'оплач') !== false || mb_stripos($s_text, 'предоп') !== false) { $bg = '#d1fae5'; $col = '#065f46'; }
                    elseif (mb_stripos($s_text, 'бронь') !== false) { $bg = '#fef3c7'; $col = '#92400e'; }
                    elseif (mb_stripos($s_text, 'отмен') !== false) { $bg = '#fee2e2'; $col = '#991b1b'; }
                    $status_tag = "<span style='display: inline-block; background: {$bg}; color: {$col}; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; margin-right: 8px; margin-bottom: 8px;'>{$s_text}</span>";
                }

                // Заметка
                $note = !empty($p['note']) ? "<div style='color: #64748b; font-size: 14px; margin-top: 8px; background: #f8fafc; padding: 10px 14px; border-radius: 8px; border-left: 3px solid #cbd5e1;'>📝 " . htmlspecialchars($p['note']) . "</div>" : "";

                $fin_pills = array_filter([$p_places, $p_amount]);
                $fin_html = !empty($fin_pills) ? "<span style='display: inline-block; background: #f1f5f9; color: #475569; font-weight: 700; font-size: 13px; padding: 4px 12px; border-radius: 6px; margin-bottom: 8px;'>" . implode(" &nbsp;•&nbsp; ", $fin_pills) . "</span>" : "";

                $html .= "
                <div style='padding: 16px 0; border-bottom: 1px dashed #e2e8f0;'>
                    <div style='margin-bottom: 8px;'>
                        <strong style='font-size: 16px; color: #0f172a;'>{$p_name}</strong> 
                        <span style='font-size: 14px; color: #64748b; margin-left: 6px;'>{$p_phone}</span>
                    </div>
                    <div style='margin-bottom: 0;'>
                        {$status_tag} {$fin_html}
                    </div>
                    {$note}
                </div>";
            }
        } else {
            $html .= "
            <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; text-align: center;'>
                <span style='color: #dc2626; font-size: 14px; font-weight: 600;'>❌ На этот тур пока нет записанных туристов</span>
            </div>";
        }
    } catch (\PDOException $e) {
        $html .= "<div style='color: #ef4444; font-size: 13px;'>❌ Ошибка загрузки участников</div>";
    }

    $html .= "
        </div>
    </div>";
    return $html;
}

function sendStyledEmail($to, $subject, $title_header, $body_content) {
    $message = "
    <!DOCTYPE html>
    <html lang='ru'>
    <head>
        <meta charset='utf-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    </head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f3f4f6; margin: 0; padding: 40px 10px; -webkit-font-smoothing: antialiased;'>
        
        <div style='max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);'>
            
            <!-- Заголовок письма -->
            <div style='background-color: #0f172a; padding: 40px 30px; text-align: center;'>
                <div style='width: 48px; height: 48px; background: #334155; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px;'>
                    <span style='font-size: 24px;'>📊</span>
                </div>
                <h1 style='margin: 0; font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: -0.02em;'>{$title_header}</h1>
                <p style='margin: 8px 0 0 0; font-size: 15px; color: #94a3b8; font-weight: 500;'>Автоматическая сводка из вашей CRM</p>
            </div>
            
            <!-- Тело письма (Карточки туров) -->
            <div style='padding: 30px 20px; background: #fafafa;'>
                {$body_content}
            </div>
            
            <!-- Подвал письма -->
            <div style='text-align: center; padding: 30px 20px; background: #ffffff; border-top: 1px solid #f1f5f9;'>
                <p style='margin: 0; font-size: 13px; color: #94a3b8; line-height: 1.6;'>
                    Это автоматическое уведомление.<br>
                    Пожалуйста, не отвечайте на это письмо.
                </p>
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
        $body_content .= "<h2 style='font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 20px 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;'>🔥 Сегодня (" . date('d.m.Y') . ")</h2>";
        foreach ($events_today as $ev) { $body_content .= renderTourCardHTML($ev, $pdo, $time_col); }
    } else {
        $body_content .= "
        <div style='background: #ffffff; padding: 24px; border-radius: 16px; margin-bottom: 30px; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);'>
            <div style='font-size: 32px; margin-bottom: 12px;'>☕</div>
            <h3 style='margin: 0 0 8px 0; font-size: 16px; color: #0f172a;'>Сегодня экскурсий нет</h3>
            <p style='margin: 0; font-size: 14px; color: #64748b;'>Можно немного передохнуть.</p>
        </div>";
    }

    if (count($events_tom) > 0) {
        $body_content .= "<h2 style='font-size: 20px; font-weight: 800; color: #0f172a; margin: 40px 0 20px 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;'>🔔 Завтра (" . date('d.m.Y', strtotime('+1 day')) . ")</h2>";
        foreach ($events_tom as $ev) { $body_content .= renderTourCardHTML($ev, $pdo, $time_col); }
    }

    $subject = "📅 Сводка туров: сегодня (" . date('d.m') . ") и завтра (" . date('d.m', strtotime('+1 day')) . ")";
    $sent = sendStyledEmail($admin_email, $subject, "Ежедневный дайджест", $body_content);
    echo $sent ? "Ежедневное письмо успешно отправлено!" : "Ошибка отправки почты.";

} elseif ($action === 'weekly') {
    // ТУРЫ НА ПРЕДСТОЯЩУЮ НЕДЕЛЮ (Пн - Вс)
    $monday_ts = strtotime('monday this week');
    $sunday_ts = strtotime('sunday this week');
    
    $start_week = date('Y-m-d', $monday_ts);
    $end_week = date('Y-m-d', $sunday_ts);

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

    $body_content = "<h2 style='font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 20px 0; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;'>📊 Расписание (" . date('d.m', $monday_ts) . " — " . date('d.m.Y', $sunday_ts) . ")</h2>";
    foreach ($events_week as $ev) {
        // Добавляем красивую форматированную дату с днем недели
        $ru_days = ['Mon'=>'Пн', 'Tue'=>'Вт', 'Wed'=>'Ср', 'Thu'=>'Чт', 'Fri'=>'Пт', 'Sat'=>'Сб', 'Sun'=>'Вс'];
        $day_key = date('D', strtotime($ev[$date_col]));
        $ev['event_date_formatted'] = date('d.m', strtotime($ev[$date_col])) . " (" . $ru_days[$day_key] . ")";
        
        $body_content .= renderTourCardHTML($ev, $pdo, $time_col);
    }

    $subject = "🗓️ Недельный план туров (" . date('d.m', $monday_ts) . " — " . date('d.m', $sunday_ts) . ")";
    $sent = sendStyledEmail($admin_email, $subject, "План туров на неделю", $body_content);
    echo $sent ? "Недельный отчет успешно отправлен!" : "Ошибка отправки почты.";
}
?>