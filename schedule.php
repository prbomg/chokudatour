<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>Доступ закрыт. Только для администратора.</h2>");
}

// --- АВТО-ОБНОВЛЕНИЕ БАЗЫ ДАННЫХ ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS global_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("INSERT IGNORE INTO global_settings (setting_key, setting_value) VALUES ('working_days', '2,3,4,5,6,7')");
    $pdo->exec("ALTER TABLE blocked_dates ADD COLUMN action_type ENUM('close', 'open') NOT NULL DEFAULT 'close'");
    $pdo->exec("ALTER TABLE blocked_dates ADD COLUMN tours VARCHAR(255) DEFAULT 'all'");
} catch (PDOException $e) {}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS guide_timeoffs (id INT AUTO_INCREMENT PRIMARY KEY, guide_name VARCHAR(255) NOT NULL, date_off DATE NOT NULL, reason VARCHAR(255))");
} catch (PDOException $e) {}

$ym = $_GET['ym'] ?? date('Y-m');

// --- ГЕНЕРАЦИЯ РАСПИСАНИЯ ТУРОВ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_schedule'])) {
    $tour_id = (int)($_POST['tour_id'] ?? 0);
    $guide = trim($_POST['guide'] ?? 'Не назначен');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $days = $_POST['days'] ?? []; 

    if ($tour_id > 0 && !empty($start_date) && !empty($end_date) && !empty($days)) {
        $created_count = 0;
        $current_ts = strtotime($start_date);
        $end_ts = strtotime($end_date);

        $check_stmt = $pdo->prepare("SELECT id FROM events WHERE tour_id = ? AND tour_date = ?");
        $insert_stmt = $pdo->prepare("INSERT INTO events (tour_id, tour_date, guide) VALUES (?, ?, ?)");

        while ($current_ts <= $end_ts) {
            $day_of_week = date('N', $current_ts);
            $date_str = date('Y-m-d', $current_ts);

            if (in_array($day_of_week, $days)) {
                $check_stmt->execute([$tour_id, $date_str]);
                if (!$check_stmt->fetch()) {
                    $insert_stmt->execute([$tour_id, $date_str, $guide]);
                    $created_count++;
                }
            }
            $current_ts = strtotime('+1 day', $current_ts);
        }
        header("Location: schedule.php?ym={$ym}&msg=schedule_generated&count=" . $created_count); exit;
    }
}

// --- СОХРАНЕНИЕ ГРАФИКА РАБОЧИХ ДНЕЙ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_working_days'])) {
    $wd_arr = $_POST['wd'] ?? [];
    $wd_str = implode(',', $wd_arr);
    $pdo->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = 'working_days'")->execute([$wd_str]);
    header("Location: schedule.php?ym={$ym}&msg=wd_saved"); exit;
}

// --- ПРАВИЛА И ИСКЛЮЧЕНИЯ ДЛЯ ДАТ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_date_rule'])) {
    $b_date = $_POST['rule_date'] ?? '';
    $action_type = $_POST['action_type'] ?? 'close';
    $reason = trim($_POST['rule_reason'] ?? '');
    $tours_arr = $_POST['tours'] ?? [];
    $tours_val = (in_array('all', $tours_arr) || empty($tours_arr)) ? 'all' : implode(',', $tours_arr);

    if ($b_date !== '') {
        $pdo->prepare("INSERT INTO blocked_dates (block_date, reason, action_type, tours) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), action_type=VALUES(action_type), tours=VALUES(tours)")->execute([$b_date, $reason, $action_type, $tours_val]);
        header("Location: schedule.php?ym={$ym}&msg=rule_saved"); exit;
    }
}
if (isset($_GET['del_rule'])) {
    $pdo->prepare("DELETE FROM blocked_dates WHERE block_date = ?")->execute([$_GET['del_rule']]);
    header("Location: schedule.php?ym={$ym}&msg=rule_deleted"); exit;
}

// --- ОТГУЛЫ ГИДОВ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_timeoff'])) {
    $g_name = trim($_POST['guide_name'] ?? '');
    $d_start = $_POST['timeoff_date'] ?? '';
    $reason = trim($_POST['timeoff_reason'] ?? '');

    if ($g_name && $d_start) {
        $pdo->prepare("INSERT INTO guide_timeoffs (guide_name, date_off, reason) VALUES (?, ?, ?)")->execute([$g_name, $d_start, $reason]);
    }
    header("Location: schedule.php?ym={$ym}&msg=timeoff_saved"); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_multi_timeoff'])) {
    $g_name = trim($_POST['guide_name'] ?? '');
    $d_start = $_POST['date_start'] ?? '';
    $d_end = $_POST['date_end'] ?: $d_start; 
    $reason = trim($_POST['reason'] ?? '');

    if ($g_name && $d_start) {
        $start = new DateTime($d_start);
        $end = new DateTime($d_end);
        if ($end < $start) $end = clone $start;

        while ($start <= $end) {
            $pdo->prepare("INSERT INTO guide_timeoffs (guide_name, date_off, reason) VALUES (?, ?, ?)")
                ->execute([$g_name, $start->format('Y-m-d'), $reason]);
            $start->modify('+1 day');
        }
    }
    header("Location: schedule.php?ym={$ym}&msg=timeoff_saved"); exit;
}

if (isset($_GET['del_timeoff'])) {
    $pdo->prepare("DELETE FROM guide_timeoffs WHERE id = ?")->execute([(int)$_GET['del_timeoff']]);
    header("Location: schedule.php?ym={$ym}&msg=timeoff_deleted"); exit;
}

// --- УДАЛЕНИЕ ЗАПЛАНИРОВАННОГО ТУРА ---
if (isset($_GET['del_event'])) {
    $event_id = (int)$_GET['del_event'];
    $check_p = $pdo->prepare("SELECT COUNT(*) FROM participants WHERE event_id = ? AND status != 'Отмена'");
    $check_p->execute([$event_id]);
    if ($check_p->fetchColumn() > 0) {
        header("Location: schedule.php?ym={$ym}&msg=cannot_delete_has_tourists"); exit;
    } else {
        $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$event_id]);
        header("Location: schedule.php?ym={$ym}&msg=event_deleted"); exit;
    }
}

// --- ПОДГОТОВКА ДАННЫХ ДЛЯ КАЛЕНДАРЯ ---
$timestamp = strtotime($ym . '-01');
if ($timestamp === false) {
    $ym = date('Y-m');
    $timestamp = strtotime($ym . '-01');
}

$months_ru = [
    '01'=>'Январь', '02'=>'Февраль', '03'=>'Март', '04'=>'Апрель', '05'=>'Май', '06'=>'Июнь', 
    '07'=>'Июль', '08'=>'Август', '09'=>'Сентябрь', '10'=>'Октябрь', '11'=>'Ноябрь', '12'=>'Декабрь'
];
$today_date = date('Y-m-d');
$prev_month = date('Y-m', strtotime('-1 month', $timestamp));
$next_month = date('Y-m', strtotime('+1 month', $timestamp));
$month_title = ($months_ru[date('m', $timestamp)] ?? '') . ' ' . date('Y', $timestamp);
$days_in_month = date('t', $timestamp);
$first_day_of_week = date('N', $timestamp);

$start_date = $ym . '-01';
$end_date = $ym . '-' . $days_in_month;

// Рабочие дни
$wd_val = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'working_days'")->fetchColumn();
$working_days = $wd_val ? explode(',', $wd_val) : [];

// Справочники
$tours = $pdo->query("SELECT * FROM tours_catalog ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$guides = $pdo->query("SELECT * FROM guides ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Правила на текущий месяц
$stmt_rules = $pdo->prepare("SELECT * FROM blocked_dates WHERE block_date BETWEEN ? AND ?");
$stmt_rules->execute([$start_date, $end_date]);
$rules_raw = $stmt_rules->fetchAll(PDO::FETCH_ASSOC);
$rules_map = [];
foreach ($rules_raw as $r) {
    $rules_map[$r['block_date']] = [
        'action_type' => $r['action_type'],
        'tours' => $r['tours'] === 'all' ? 'all' : explode(',', $r['tours']),
        'reason' => $r['reason']
    ];
}

// Отгулы на текущий месяц
$stmt_to = $pdo->prepare("SELECT * FROM guide_timeoffs WHERE date_off BETWEEN ? AND ?");
$stmt_to->execute([$start_date, $end_date]);
$timeoffs_raw = $stmt_to->fetchAll(PDO::FETCH_ASSOC);
$timeoffs_map = [];
foreach ($timeoffs_raw as $to) {
    $timeoffs_map[$to['date_off']][] = $to;
}

// Туры на текущий месяц
$stmt_ev = $pdo->prepare("SELECT e.*, t.name AS tour_name, COALESCE((SELECT SUM(seats) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as seats_count FROM events e JOIN tours_catalog t ON e.tour_id = t.id WHERE e.tour_date BETWEEN ? AND ? ORDER BY e.tour_date ASC, t.name ASC");
$stmt_ev->execute([$start_date, $end_date]);
$events_raw = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);
$events_map = [];

foreach ($events_raw as $ev) {
    $events_map[$ev['tour_date']][] = $ev;
}

// Статистика для дашборда
$total_scheduled = $pdo->query("SELECT COUNT(*) FROM events WHERE tour_date >= CURDATE()")->fetchColumn();
$empty_events = $pdo->query("SELECT COUNT(*) FROM events e WHERE tour_date >= CURDATE() AND (SELECT COALESCE(SUM(seats),0) FROM participants p WHERE p.event_id = e.id AND p.status != 'Отмена') = 0")->fetchColumn();

// Будущие отгулы и правила для списка
$future_rules = $pdo->query("SELECT * FROM blocked_dates WHERE block_date >= CURDATE() ORDER BY block_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$timeoffs = $pdo->query("SELECT * FROM guide_timeoffs WHERE date_off >= CURDATE() ORDER BY date_off ASC")->fetchAll(PDO::FETCH_ASSOC);

function getGuideColor($guideName) {
    if (empty($guideName) || $guideName === 'Не назначен') return "hsl(215, 16%, 80%)";
    $hash = substr(md5($guideName), 0, 6);
    $hue = hexdec($hash) % 360; 
    return "hsl({$hue}, 70%, 55%)"; 
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки расписания — CRM</title>
    <style>
        /* ПРЕМИУМ ДИЗАЙН (Soft UI & Glassmorphism) */
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05); --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; }
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box;}
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
        
        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { margin-bottom: 25px;}
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); }

        /* Дашборды */
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 22px; box-shadow: var(--shadow-md); position: relative; overflow: hidden; border: 1px solid var(--border);}
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; background: var(--border);}
        .dash-card.blue::before { background: var(--primary); }
        .dash-card.warning::before { background: #F59E0B; }
        .dash-title { font-size: 12px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;}
        .dash-val { font-size: 26px; font-weight: 800; color: var(--text-main); }

        /* Layout Карточек */
        .layout-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; align-items: start;}
        .card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 25px; box-shadow: var(--shadow-md); border: 1px solid var(--border); }
        h3 { margin-top: 0; font-size: 18px; font-weight: 800; border-bottom: 2px solid #F1F5F9; padding-bottom: 12px; color: var(--text-main); display: flex; align-items: center; gap: 10px;}

        /* Формы и Инпуты */
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 20px;}
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }

        input.t-input, select.t-input { 
            width: 100%; box-sizing: border-box; padding: 12px 14px; 
            background: #F8FAFC; border: 1px solid var(--border); 
            border-radius: var(--radius-sm); font-size: 14px; font-family: inherit; 
            outline: none; color: var(--text-main); font-weight: 500; height: 44px; min-width: 0;
        }
        input.t-input:focus, select.t-input:focus { background: #FFFFFF; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }

        .btn-submit { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: var(--radius-sm); font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; transition: var(--transition);}
        .btn-submit:hover { background: var(--primary-hover); }

        /* Кроссбраузерные Чекбоксы (Таблетки) */
        .wd-checkboxes { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px;}
        .cb-wrapper { position: relative; display: inline-block;}
        .hidden-cb { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0;}
        .wd-label-btn { 
            display: inline-block; padding: 10px 16px; border-radius: 99px; 
            background: #F8FAFC; border: 1px solid var(--border); 
            font-size: 13px; font-weight: 700; color: var(--text-muted); 
            cursor: pointer; transition: var(--transition); user-select: none;
        }
        .hidden-cb:checked + .wd-label-btn { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);}
        .wd-label-btn:hover { border-color: var(--primary); }

        /* Управление календарем */
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--border);}
        .calendar-title { font-size: 22px; font-weight: 800; margin: 0; color: var(--text-main); min-width: 200px; text-align: center;}
        .btn-nav { display: inline-flex; align-items: center; gap: 8px; background: #F8FAFC; color: var(--text-main); padding: 10px 18px; border-radius: var(--radius-sm); font-weight: 700; text-decoration: none; font-size: 14px; border: 1px solid var(--border); transition: var(--transition);}
        .btn-nav:hover { background: var(--primary-light); color: var(--primary); }

        .legend-bar { display: flex; gap: 15px; font-size: 13px; color: var(--text-muted); margin-bottom: 20px; flex-wrap: wrap; font-weight: 500;}
        .leg-item { display: flex; align-items: center; gap: 6px; }
        .leg-box { width: 14px; height: 14px; border-radius: 4px; }

        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; }
        .day-header { text-align: center; font-weight: 800; font-size: 12px; color: var(--text-muted); text-transform: uppercase; padding-bottom: 10px; }
        
        .calendar-cell { background: var(--card-bg); border-radius: var(--radius-md); min-height: 140px; padding: 10px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); display: flex; flex-direction: column; gap: 6px; cursor: pointer; transition: var(--transition); position: relative;}
        .calendar-cell:hover { box-shadow: var(--shadow-md); border-color: var(--primary); transform: translateY(-2px);}
        .calendar-cell.empty { background: transparent; border: 1px dashed #CBD5E1; box-shadow: none; pointer-events: none;}
        .calendar-cell.today { border: 2px solid var(--primary); background: var(--primary-light); }
        .calendar-cell.today .date-number { background: var(--primary); color: white; }

        .date-number { align-self: flex-end; font-size: 14px; font-weight: 800; color: var(--text-muted); width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 50%; margin-bottom: 4px; transition: var(--transition);}
        
        .is-closed { background: #FEF2F2; border-color: #FCA5A5; }
        .is-opened { background: #ECFDF5; border-color: #6EE7B7; }
        .not-working { background: #F8FAFC; opacity: 0.7; }
        .cell-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 700; align-self: flex-start; margin-bottom: 4px;}

        /* Карточки туров */
        .tour-chip { background: #FFFFFF; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px; font-size: 11px; font-weight: 600; color: var(--text-main); display: flex; flex-direction: column; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.02);}
        .chip-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 700; font-size: 12px;}
        .chip-meta { display: flex; justify-content: space-between; color: var(--text-muted); font-size: 10px;}
        .timeoff-chip { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; border-radius: var(--radius-sm); padding: 6px 8px; font-size: 11px; font-weight: 700; display: flex; align-items: center; gap: 4px;}

        /* Модалка */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.5); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 20px; box-sizing: border-box;}
        .modal-content { background: var(--card-bg); padding: 30px; border-radius: 24px; max-width: 480px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.2); }
        .modal-title { font-size: 22px; font-weight: 800; margin: 0 0 20px 0; color: var(--text-main);}
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #F1F5F9; padding-bottom: 10px;}
        .tab-btn { background: none; border: none; font-size: 14px; font-weight: 700; color: var(--text-muted); cursor: pointer; padding: 8px 12px; border-radius: var(--radius-sm);}
        .tab-btn.active { background: var(--primary-light); color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .radio-group { display: flex; flex-direction: column; gap: 10px; background: #F8FAFC; padding: 15px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-bottom: 20px;}
        .radio-label { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; cursor: pointer;}
        .tours-list-box { margin-bottom: 20px; border: 1px solid var(--border); padding: 15px; border-radius: var(--radius-sm); max-height: 180px; overflow-y: auto;}

        .btn-cancel { background: transparent; color: var(--text-muted); padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-md); font-weight: 600; font-size: 14px; width: 100%; cursor: pointer; margin-top: 15px;}
        .timeoff-list-item { display: flex; justify-content: space-between; align-items: center; background: #F8FAFC; padding: 10px 15px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-bottom: 10px;}
        
        .btn-icon-del { width: 28px; height: 28px; border-radius: 6px; background: #FEF2F2; color: #EF4444; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; font-weight: bold;}

        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 14px 10px; border-bottom: 1px solid #F1F5F9; font-size: 13px; text-align: left; vertical-align: middle;}
        tr:last-child td { border-bottom: none; }

        /* Toast Уведомления */
        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%); transition: all 0.3s ease; display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        /* Адаптивность */
        @media (max-width: 992px) {
            .layout-grid { grid-template-columns: 1fr; }
            .calendar-grid { grid-template-columns: 1fr; gap: 15px; }
            .day-header { display: none; }
            .calendar-cell { min-height: auto; flex-direction: row; flex-wrap: wrap; align-items: center; padding: 15px;}
            .calendar-cell.empty { display: none; }
            .date-number { order: -1; width: auto; padding: 4px 12px; border-radius: 20px; background: var(--border); margin: 0 15px 0 0;}
            .tour-chip, .timeoff-chip { width: calc(50% - 10px); }
            .cell-badge { align-self: center; margin-bottom: 0; margin-right: 15px;}
            
            .gen-col-side { border-left: none; padding-left: 0; border-top: 2px solid var(--border); padding-top: 30px; margin-top: 20px;}
        }
        @media (max-width: 600px) {
            .tour-chip, .timeoff-chip { width: 100%; }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>Настройки расписания</h2>
    </div>

    <div class="dash-grid">
        <div class="dash-card blue">
            <div class="dash-title">Сформировано туров</div>
            <div class="dash-val"><?= $total_scheduled ?></div>
        </div>
        <div class="dash-card warning">
            <div class="dash-title">Пустых слотов (нет записей)</div>
            <div class="dash-val"><?= $empty_events ?></div>
        </div>
    </div>

    <div class="layout-grid">
        <div class="card" style="margin-bottom:0;">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary);"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                Массовая генерация туров
            </h3>
            <form method="POST">
                <input type="hidden" name="generate_schedule" value="1">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Выберите тур *</label>
                        <select name="tour_id" class="t-input" required>
                            <option value="" disabled selected>Из каталога...</option>
                            <?php foreach ($tours as $t): ?>
                                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Основной гид *</label>
                        <select name="guide" class="t-input" required>
                            <option value="Не назначен">Не назначен</option>
                            <?php foreach ($guides as $g): ?>
                                <option value="<?= htmlspecialchars($g['name'] ?? '') ?>"><?= htmlspecialchars($g['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Начало периода (с) *</label>
                        <input type="date" name="start_date" class="t-input" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Конец периода (по) *</label>
                        <input type="date" name="end_date" class="t-input" value="<?= date('Y-m-d', strtotime('+1 month')) ?>" required>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>По каким дням создавать?</label>
                    <div class="wd-checkboxes">
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="1" class="hidden-cb">
                            <span class="wd-label-btn">Пн</span>
                        </label>
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="2" class="hidden-cb">
                            <span class="wd-label-btn">Вт</span>
                        </label>
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="3" class="hidden-cb">
                            <span class="wd-label-btn">Ср</span>
                        </label>
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="4" class="hidden-cb">
                            <span class="wd-label-btn">Чт</span>
                        </label>
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="5" class="hidden-cb">
                            <span class="wd-label-btn">Пт</span>
                        </label>
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="6" class="hidden-cb" checked>
                            <span class="wd-label-btn">Сб</span>
                        </label>
                        <label class="cb-wrapper">
                            <input type="checkbox" name="days[]" value="7" class="hidden-cb" checked>
                            <span class="wd-label-btn">Вс</span>
                        </label>
                    </div>
                </div>
                <button type="submit" class="btn-submit">Внести в расписание</button>
            </form>
        </div>

        <div class="card gen-col-side" style="margin-bottom:0;">
            <h4 style="margin: 0 0 15px 0; font-size:15px; color:var(--text-main);">Глобальный график</h4>
            <form method="POST">
                <input type="hidden" name="save_working_days" value="1">
                <div class="wd-checkboxes" style="margin-bottom: 15px;">
                    <?php 
                    $days_ru = [1=>'Пн', 2=>'Вт', 3=>'Ср', 4=>'Чт', 5=>'Пт', 6=>'Сб', 7=>'Вс'];
                    foreach ($days_ru as $num => $name): ?>
                    <label class="cb-wrapper">
                        <input type="checkbox" name="wd[]" value="<?= $num ?>" class="hidden-cb" <?= in_array($num, $working_days) ? 'checked' : '' ?>>
                        <span class="wd-label-btn"><?= $name ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn-submit" style="background:var(--card-bg); color:var(--text-main); border:1px solid var(--border); box-shadow:none; width: 100%;">Сохранить график</button>
            </form>

            <h4 style="margin: 30px 0 15px 0; font-size:15px; color:var(--text-main);">Долгий отпуск гида</h4>
            <form method="POST" style="display:flex; flex-direction:column; gap:10px;">
                <input type="hidden" name="save_multi_timeoff" value="1">
                <select name="guide_name" class="t-input" required>
                    <option value="" disabled selected>-- Выберите гида --</option>
                    <?php foreach($guides as $g): ?>
                        <option value="<?= htmlspecialchars($g['name'] ?? '') ?>"><?= htmlspecialchars($g['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <input type="date" name="date_start" class="t-input" style="flex:1; min-width:130px;" required title="Начало">
                    <input type="date" name="date_end" class="t-input" style="flex:1; min-width:130px;" title="Конец">
                </div>
                <input type="text" name="reason" class="t-input" placeholder="Причина (отпуск...)">
                <button type="submit" class="btn-submit" style="background: #10B981;">Отправить в отпуск</button>
            </form>
        </div>
    </div>

    <div class="calendar-header">
        <a href="?ym=<?= $prev_month ?>" class="btn-nav">← Пред.</a>
        <h2 class="calendar-title"><?= htmlspecialchars($month_title) ?></h2>
        <a href="?ym=<?= $next_month ?>" class="btn-nav">След. →</a>
    </div>

    <div class="legend-bar">
        <div class="leg-item"><div class="leg-box" style="background:var(--primary);"></div> Сформирован тур</div>
        <div class="leg-item"><div class="leg-box" style="background:#EF4444;"></div> Закрыто</div>
        <div class="leg-item"><div class="leg-box" style="background:#10B981;"></div> Рабочее исключение</div>
        <div class="leg-item"><div class="leg-box" style="background:#F1F5F9; border:1px dashed #CBD5E1;"></div> Пусто / Нерабочий день</div>
    </div>

    <div class="calendar-grid">
        <div class="day-header">Понедельник</div>
        <div class="day-header">Вторник</div>
        <div class="day-header">Среда</div>
        <div class="day-header">Четверг</div>
        <div class="day-header">Пятница</div>
        <div class="day-header" style="color:#EF4444;">Суббота</div>
        <div class="day-header" style="color:#EF4444;">Воскресенье</div>
        
        <?php
        for ($i = 1; $i < $first_day_of_week; $i++) {
            echo "<div class='calendar-cell empty'></div>";
        }

        for ($day = 1; $day <= $days_in_month; $day++) {
            $date_string = $ym . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
            $is_today = ($date_string === $today_date) ? 'today' : '';
            
            $day_of_week = date('N', strtotime($date_string));
            $is_working_day = in_array($day_of_week, $working_days);
            
            $rule = $rules_map[$date_string] ?? null;
            $day_timeoffs = $timeoffs_map[$date_string] ?? [];
            $day_events = $events_map[$date_string] ?? [];

            $cell_classes = ['calendar-cell', $is_today];
            $badges_html = '';

            if ($rule) {
                if ($rule['action_type'] === 'close') {
                    $cell_classes[] = 'is-closed';
                    $badges_html .= "<span class='cell-badge' style='background:#EF4444; color:white;'>Закрыто</span>";
                } else {
                    $cell_classes[] = 'is-opened';
                    $badges_html .= "<span class='cell-badge' style='background:#10B981; color:white;'>Исключение</span>";
                }
            } elseif (!$is_working_day) {
                $cell_classes[] = 'not-working';
            }

            $classes_str = implode(' ', $cell_classes);
            
            echo "<div class='{$classes_str}' data-date='{$date_string}' onclick='openUnifiedModal(this)'>";
            
            $dow_names = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
            echo "<div class='date-number' data-dow='{$dow_names[$day_of_week]}'>{$day}</div>";
            echo $badges_html;

            foreach ($day_timeoffs as $to) {
                $reason = !empty($to['reason']) ? " (" . htmlspecialchars($to['reason']) . ")" : "";
                echo "<div class='timeoff-chip'>🏖️ " . htmlspecialchars($to['guide_name'] ?? '') . $reason . "</div>";
            }

            foreach ($day_events as $ev) {
                $guide_color = getGuideColor($ev['guide'] ?? '');
                echo "
                <div class='tour-chip' style='border-left: 4px solid {$guide_color};'>
                    <div style='font-weight:700;'>" . htmlspecialchars($ev['tour_name'] ?? '') . "</div>
                    <div class='chip-meta'>
                        <span>" . htmlspecialchars($ev['guide'] ?: 'Без гида') . "</span>
                        <span>👤 " . (int)($ev['seats_count'] ?? 0) . "</span>
                    </div>
                </div>";
            }

            echo "</div>"; 
        }

        $last_day_of_week = date('N', strtotime($end_date));
        if ($last_day_of_week < 7) {
            for ($i = $last_day_of_week; $i < 7; $i++) {
                echo "<div class='calendar-cell empty'></div>";
            }
        }
        ?>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 30px;">
        <div class="card">
            <h3>Будущие правила дат</h3>
            <table>
                <?php foreach ($future_rules as $r): ?>
                <tr>
                    <td>
                        <strong style="color: <?= ($r['action_type'] ?? '')==='open' ? '#10B981' : '#EF4444' ?>;">
                            <?= date('d.m.y', strtotime($r['block_date'])) ?> — <?= ($r['action_type'] ?? '')==='open' ? 'ОТКРЫТО' : 'ЗАКРЫТО' ?>
                        </strong><br>
                        <span style="font-size: 12px; color: var(--text-muted); font-weight:500;">
                            Туры: <?= ($r['tours'] ?? '') === 'all' ? 'Все' : 'Выбранные' ?><br>
                            <?= !empty($r['reason']) ? "Причина: " . htmlspecialchars($r['reason']) : "" ?>
                        </span>
                    </td>
                    <td style="width: 40px; text-align: right;">
                        <a href="?del_rule=<?= $r['block_date'] ?>&ym=<?= $ym ?>" class="btn-icon-del" title="Удалить правило">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($future_rules)): ?>
                <tr><td style="color:var(--text-muted); font-size:13px; text-align:center; border:none; padding-top:20px;">Нет исключений на будущее</td></tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="card">
            <h3 style="color: #059669;">Будущие отгулы гидов</h3>
            <table>
                <?php foreach ($timeoffs as $t): ?>
                <tr>
                    <td>
                        <strong style="color:var(--text-main);"><?= htmlspecialchars($t['guide_name'] ?? '') ?></strong><br>
                        <span style="font-size:12px; color:var(--text-muted); font-weight:500;">
                            <?= date('d.m.Y', strtotime($t['date_off'])) ?>
                            <?= !empty($t['reason']) ? " — " . htmlspecialchars($t['reason']) : "" ?>
                        </span>
                    </td>
                    <td style="width: 40px; text-align: right;">
                        <a href="?del_timeoff=<?= $t['id'] ?>&ym=<?= $ym ?>" class="btn-icon-del" title="Удалить">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($timeoffs)): ?>
                <tr><td style="color:var(--text-muted); font-size:13px; text-align:center; border:none; padding-top:20px;">Нет запланированных отгулов</td></tr>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<script>
    const rulesMap = <?= json_encode($rules_map, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
    const timeoffsMap = <?= json_encode($timeoffs_map, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;
    const monthsRu = <?= json_encode($months_ru, JSON_UNESCAPED_UNICODE) ?>;
</script>

<div id="unifiedModal" class="modal-overlay">
    <div class="modal-content">
        <h3 id="modalTitle" class="modal-title">Настройка даты</h3>
        
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('tab-rules', this)">Правила работы</button>
            <button class="tab-btn" onclick="switchTab('tab-timeoffs', this)">🏖️ Отгулы гидов</button>
        </div>

        <div id="tab-rules" class="tab-content active">
            <form method="POST" action="schedule.php?ym=<?= $ym ?>" style="display:flex; flex-direction:column; gap:12px;">
                <input type="hidden" name="save_date_rule" value="1">
                <input type="hidden" name="rule_date" id="modalDateInput">

                <div class="radio-group" style="background: #F8FAFC; padding: 15px; border-radius: 8px; border: 1px solid var(--border);">
                    <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; margin-bottom: 10px;">
                        <input type="radio" name="action_type" value="close" checked> 🔒 Закрыть день для туров
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-weight:600; cursor:pointer; color: #10B981;">
                        <input type="radio" name="action_type" value="open"> 🔓 Открыть как рабочее исключение
                    </label>
                </div>

                <div class="tours-list-box" style="border: 1px solid var(--border); padding: 15px; border-radius: 8px; max-height: 180px; overflow-y: auto;">
                    <strong style="font-size: 13px; color:var(--text-main);">К каким экскурсиям применить:</strong><br>
                    <label style="display:block; margin-top:10px; font-size:14px; font-weight:700; cursor:pointer;">
                        <input type="checkbox" name="tours[]" value="all" id="tourAllCheck" checked> Ко всему каталогу
                    </label>
                    <hr style="margin: 12px 0; border: none; border-top: 1px solid var(--border);">
                    <div id="specificToursList" style="display:none;">
                        <?php foreach($tours as $t): ?>
                            <label style="display:block; margin-bottom:8px; font-size:13px; font-weight:600; color:var(--text-muted); cursor:pointer;">
                                <input type="checkbox" name="tours[]" value="<?= $t['id'] ?>" class="tour-checkbox"> <?= htmlspecialchars($t['name'] ?? '') ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <input type="text" name="rule_reason" id="modalReasonInput" class="t-input" placeholder="Причина (опционально)...">
                
                <button type="submit" class="btn-submit" style="margin-top:10px; width:100%;">Сохранить правило</button>
            </form>
            <a href="#" id="deleteRuleBtn" style="display:block; text-align:center; padding:15px; color:#EF4444; font-weight:700; text-decoration:none; margin-top:5px; display:none;">Сбросить правило (удалить)</a>
        </div>

        <div id="tab-timeoffs" class="tab-content">
            <form method="POST" action="schedule.php?ym=<?= $ym ?>" style="display:flex; flex-direction:column; gap:12px; margin-bottom: 20px;">
                <input type="hidden" name="save_timeoff" value="1">
                <input type="hidden" name="timeoff_date" id="timeoffDateInput">
                
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Назначить выходной</label>
                    <select name="guide_name" class="t-input" required>
                        <option value="" disabled selected>Выберите гида...</option>
                        <?php foreach($guides as $g): ?>
                            <option value="<?= htmlspecialchars($g['name'] ?? '') ?>"><?= htmlspecialchars($g['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="text" name="timeoff_reason" class="t-input" placeholder="Причина (Болеет, отпуск...)">
                <button type="submit" class="btn-submit" style="background:#10B981; width:100%;">Дать выходной</button>
            </form>

            <div id="existingTimeoffsList"></div>
        </div>

        <button type="button" onclick="document.getElementById('unifiedModal').style.display='none'" class="btn-cancel">Закрыть окно</button>
    </div>
</div>

<script>
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        const icon = type === 'success' ? `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>` : `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;
        toast.innerHTML = icon + `<span>${message}</span>`;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 3000);
    }

    function switchTab(tabId, btnElement = null) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        
        if (btnElement) {
            btnElement.classList.add('active');
        } else {
            document.querySelector('.tab-btn').classList.add('active');
        }
        
        document.getElementById(tabId).classList.add('active');
    }

    document.getElementById("tourAllCheck").addEventListener("change", function(e) {
        const list = document.getElementById("specificToursList");
        if (this.checked) {
            list.style.display = "none";
            document.querySelectorAll('.tour-checkbox').forEach(cb => cb.checked = false);
        } else {
            list.style.display = "block";
        }
    });

    function openUnifiedModal(cellElement) {
        const dateStr = cellElement.getAttribute('data-date');
        const ym = "<?= $ym ?>";
        
        // Форматирование даты
        const parts = dateStr.split('-');
        const displayDate = `${parseInt(parts[2], 10)} ${monthsRu[parts[1]]} ${parts[0]}`;
        
        document.getElementById('modalTitle').textContent = `Настройка: ${displayDate}`;
        document.getElementById('modalDateInput').value = dateStr;
        document.getElementById('timeoffDateInput').value = dateStr;

        const delBtn = document.getElementById('deleteRuleBtn');
        document.querySelector('input[name="action_type"][value="close"]').checked = true;
        document.getElementById("tourAllCheck").checked = true;
        document.getElementById("specificToursList").style.display = "none";
        document.getElementById("modalReasonInput").value = "";
        document.querySelectorAll('.tour-checkbox').forEach(cb => cb.checked = false);

        const rule = rulesMap[dateStr];
        if (rule) {
            document.querySelector(`input[name="action_type"][value="${rule.action_type}"]`).checked = true;
            document.getElementById("modalReasonInput").value = rule.reason || "";
            if (rule.tours !== 'all') {
                document.getElementById("tourAllCheck").checked = false;
                document.getElementById("specificToursList").style.display = "block";
                rule.tours.forEach(tId => {
                    const cb = document.querySelector(`.tour-checkbox[value="${tId}"]`);
                    if(cb) cb.checked = true;
                });
            }
            delBtn.style.display = "block";
            delBtn.href = `schedule.php?del_rule=${dateStr}&ym=${ym}`;
        } else {
            delBtn.style.display = "none";
        }

        const toList = document.getElementById('existingTimeoffsList');
        toList.innerHTML = '';
        const dayTimeoffs = timeoffsMap[dateStr] || [];
        if (dayTimeoffs.length > 0) {
            let html = '<div style="font-size:12px; font-weight:700; color:var(--text-muted); margin-bottom:10px; text-transform:uppercase;">Уже отдыхают в этот день:</div>';
            dayTimeoffs.forEach(to => {
                const reason = to.reason ? ` <span style='color:var(--text-muted); font-size:12px;'>(${to.reason})</span>` : '';
                html += `
                <div class="timeoff-list-item">
                    <div><strong>${to.guide_name}</strong>${reason}</div>
                    <a href="?del_timeoff=${to.id}&ym=${ym}" class="btn-icon-del" title="Удалить выходной">✕</a>
                </div>`;
            });
            toList.innerHTML = html;
        }

        switchTab('tab-rules', document.querySelector('.tab-btn'));
        document.getElementById('unifiedModal').style.display = 'flex';
    }

    document.getElementById('unifiedModal').addEventListener('mousedown', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

    document.addEventListener('DOMContentLoaded', () => {
        if (window.innerWidth <= 1024) {
            document.querySelectorAll('.date-number').forEach(el => {
                const dow = el.getAttribute('data-dow');
                if (dow) {
                    el.innerHTML = `<span style="color:var(--primary); margin-right:5px;">${dow},</span> ${el.innerHTML}`;
                }
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg) {
            const messages = {
                'schedule_generated': `Успешно добавлено ${urlParams.get('count') || '0'} туров в расписание!`,
                'wd_saved': 'Глобальный график обновлен',
                'rule_saved': 'Правило для даты сохранено',
                'rule_deleted': 'Исключение удалено',
                'timeoff_saved': 'Отгул гиду назначен',
                'timeoff_deleted': 'Отгул гида удален',
                'cannot_delete_has_tourists': 'Нельзя удалить: на эту экскурсию уже записаны туристы!'
            };
            if (messages[msg]) showToast(messages[msg], msg.includes('deleted') || msg.includes('cannot') ? 'error' : 'success');
            window.history.replaceState({}, document.title, window.location.pathname + '?ym=<?= $ym ?>');
        }
    });
</script>

</body>
</html>