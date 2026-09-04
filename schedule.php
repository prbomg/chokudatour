<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';
require_once __DIR__ . '/participant_seats.php';
$participant_seats_sql = participantSeatsSql($pdo);

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

// --- 1. ДОБАВЛЕНИЕ ОДНОГО ТУРА НА ДАТУ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_single_event'])) {
    $tour_id = (int)($_POST['tour_id'] ?? 0);
    $guide = trim($_POST['guide'] ?? 'Не назначен');
    $tour_date = $_POST['tour_date'] ?? '';
    $time = trim($_POST['time'] ?? '');

    if ($tour_id > 0 && !empty($tour_date)) {
        if (empty($time)) {
            $stmt_t = $pdo->prepare("SELECT default_start_time FROM tours_catalog WHERE id = ?");
            $stmt_t->execute([$tour_id]);
            $time = $stmt_t->fetchColumn() ?: '10:00';
        }

        $pdo->prepare("INSERT INTO events (tour_id, tour_date, time, guide) VALUES (?, ?, ?, ?)")->execute([$tour_id, $tour_date, $time, $guide]);
        header("Location: schedule.php?ym={$ym}&msg=event_added"); exit;
    }
}

// --- 2. СОХРАНЕНИЕ ГРАФИКА РАБОЧИХ ДНЕЙ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_working_days'])) {
    $wd_arr = $_POST['wd'] ?? [];
    $wd_str = implode(',', $wd_arr);
    $pdo->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = 'working_days'")->execute([$wd_str]);
    header("Location: schedule.php?ym={$ym}&msg=wd_saved"); exit;
}

// --- 3. ПРАВИЛА И ИСКЛЮЧЕНИЯ ДЛЯ ДАТ (С УЧЕТОМ ДИАПАЗОНОВ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_date_rule'])) {
    $d_start = $_POST['rule_date_start'] ?? '';
    $d_end = $_POST['rule_date_end'] ?: $d_start;
    $action_type = $_POST['action_type'] ?? 'close';
    $reason = trim($_POST['rule_reason'] ?? '');
    
    $tours_arr = $_POST['tours'] ?? [];
    $tours_val = (in_array('all', $tours_arr) || empty($tours_arr)) ? 'all' : implode(',', $tours_arr);

    if ($d_start !== '') {
        $start = new DateTime($d_start);
        $end = new DateTime($d_end);
        if ($end < $start) $end = clone $start;

        while ($start <= $end) {
            $b_date = $start->format('Y-m-d');
            $pdo->prepare("INSERT INTO blocked_dates (block_date, reason, action_type, tours) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE reason=VALUES(reason), action_type=VALUES(action_type), tours=VALUES(tours)")->execute([$b_date, $reason, $action_type, $tours_val]);
            $start->modify('+1 day');
        }
        header("Location: schedule.php?ym={$ym}&msg=rule_saved"); exit;
    }
}

// Удаление правил для диапазона
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_date_rule'])) {
    $d_start = $_POST['rule_date_start'] ?? '';
    $d_end = $_POST['rule_date_end'] ?: $d_start;
    
    if ($d_start !== '') {
        $pdo->prepare("DELETE FROM blocked_dates WHERE block_date BETWEEN ? AND ?")->execute([$d_start, $d_end]);
        header("Location: schedule.php?ym={$ym}&msg=rule_deleted"); exit;
    }
}

if (isset($_GET['del_rule'])) {
    $pdo->prepare("DELETE FROM blocked_dates WHERE block_date = ?")->execute([$_GET['del_rule']]);
    header("Location: schedule.php?ym={$ym}&msg=rule_deleted"); exit;
}

// --- 4. ОТГУЛЫ ГИДОВ (С УЧЕТОМ ДИАПАЗОНОВ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_timeoff'])) {
    $g_name = trim($_POST['guide_name'] ?? '');
    $d_start = $_POST['timeoff_date_start'] ?? '';
    $d_end = $_POST['timeoff_date_end'] ?: $d_start;
    $reason = trim($_POST['timeoff_reason'] ?? '');

    if ($g_name && $d_start) {
        $start = new DateTime($d_start);
        $end = new DateTime($d_end);
        if ($end < $start) $end = clone $start;

        while ($start <= $end) {
            $pdo->prepare("INSERT INTO guide_timeoffs (guide_name, date_off, reason) VALUES (?, ?, ?)")->execute([$g_name, $start->format('Y-m-d'), $reason]);
            $start->modify('+1 day');
        }
    }
    header("Location: schedule.php?ym={$ym}&msg=timeoff_saved"); exit;
}

if (isset($_GET['del_timeoff'])) {
    $pdo->prepare("DELETE FROM guide_timeoffs WHERE id = ?")->execute([(int)$_GET['del_timeoff']]);
    header("Location: schedule.php?ym={$ym}&msg=timeoff_deleted"); exit;
}

// ======================= ПОДГОТОВКА ДАННЫХ ДЛЯ КАЛЕНДАРЯ =======================
$timestamp = strtotime($ym . '-01');
if ($timestamp === false) { $timestamp = time(); $ym = date('Y-m', $timestamp); }

$today_date = date('Y-m-d');
$months_ru = ['01'=>'Январь', '02'=>'Февраль', '03'=>'Март', '04'=>'Апрель', '05'=>'Май', '06'=>'Июнь', '07'=>'Июль', '08'=>'Август', '09'=>'Сентябрь', '10'=>'Октябрь', '11'=>'Ноябрь', '12'=>'Декабрь'];
$month_title = ($months_ru[date('m', $timestamp)] ?? '') . ' ' . date('Y', $timestamp);

$prev_ym = date('Y-m', strtotime('-1 month', $timestamp));
$next_ym = date('Y-m', strtotime('+1 month', $timestamp));
$days_in_month = date('t', $timestamp);
$first_day_of_week = date('N', $timestamp);

$start_date_sql = $ym . '-01';
$end_date_sql = $ym . '-' . $days_in_month;

// Рабочие дни
$wd_val = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'working_days'")->fetchColumn();
$default_working_days = ($wd_val !== false && $wd_val !== '') ? explode(',', $wd_val) : ['2','3','4','5','6','7'];

// Справочники
$tours = $pdo->query("SELECT id, name, default_start_time FROM tours_catalog ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$guides = $pdo->query("SELECT * FROM guides ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Правила на текущий месяц
$stmt_rules = $pdo->prepare("SELECT * FROM blocked_dates WHERE block_date BETWEEN ? AND ?");
$stmt_rules->execute([$start_date_sql, $end_date_sql]);
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
$stmt_to->execute([$start_date_sql, $end_date_sql]);
$timeoffs_raw = $stmt_to->fetchAll(PDO::FETCH_ASSOC);
$timeoffs_map = [];
foreach ($timeoffs_raw as $to) {
    $timeoffs_map[$to['date_off']][] = $to;
}

// Туры на текущий месяц
$stmt_ev = $pdo->prepare("SELECT e.*, t.name AS tour_name, COALESCE((SELECT SUM({$participant_seats_sql}) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as seats_count FROM events e JOIN tours_catalog t ON e.tour_id = t.id WHERE e.tour_date BETWEEN ? AND ? ORDER BY e.time ASC, t.name ASC");
$stmt_ev->execute([$start_date_sql, $end_date_sql]);
$events_raw = $stmt_ev->fetchAll(PDO::FETCH_ASSOC);
$events_map = [];

foreach ($events_raw as $ev) {
    $events_map[$ev['tour_date']][] = $ev;
}

// Будущие отгулы и правила для списков снизу
$future_rules = $pdo->query("SELECT * FROM blocked_dates WHERE block_date >= CURDATE() ORDER BY block_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$timeoffs_future = $pdo->query("SELECT * FROM guide_timeoffs WHERE date_off >= CURDATE() ORDER BY date_off ASC")->fetchAll(PDO::FETCH_ASSOC);

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
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05); --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; -webkit-font-smoothing: antialiased;}
        
        /* ИСПРАВЛЕНИЕ: Ширина контейнера теперь 1400px, как на странице index.php */
        .container { max-width: 1400px; margin: 0 auto; box-sizing: border-box;}
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }

        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;}
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;}

        .card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 25px; box-shadow: var(--shadow-md); border: 1px solid var(--border); margin-bottom: 30px;}
        .card-header { font-size: 16px; font-weight: 800; color: var(--text-main); margin: 0 0 15px 0; display: flex; align-items: center; justify-content: space-between;}

        /* ПЛАШКА БАЗОВОГО ГРАФИКА */
        .settings-bar { background: var(--card-bg); border-radius: var(--radius-lg); padding: 20px 25px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;}
        .settings-info h3 { margin: 0 0 4px 0; font-size: 16px; font-weight: 800; color: var(--text-main); }
        .settings-info p { margin: 0; font-size: 13px; color: var(--text-muted); }
        .settings-form { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        
        .wd-grid { display: flex; gap: 8px; }
        .wd-grid input[type="checkbox"] { display: none; }
        .wd-grid label { width: 42px; height: 42px; display: flex; align-items: center; justify-content: center; background: #F8FAFC; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: var(--transition); user-select: none;}
        .wd-grid input[type="checkbox"]:checked + label { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 10px rgba(79,70,229,0.3); }
        .wd-grid label:hover { border-color: var(--primary); color: var(--primary); }
        .wd-grid input[type="checkbox"]:checked + label:hover { color: white; }

        /* УПРАВЛЕНИЕ КАЛЕНДАРЕМ */
        .cal-nav { display: flex; align-items: center; gap: 15px; }
        .btn-nav { width: 36px; height: 36px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--card-bg); display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; color: var(--text-main); transition: var(--transition); box-shadow: var(--shadow-sm);}
        .btn-nav:hover { background: #F1F5F9; color: var(--primary); border-color: var(--primary);}
        .cal-title { font-size: 20px; font-weight: 800; text-transform: capitalize; min-width: 150px; text-align: center;}

        /* СЕТКА КАЛЕНДАРЯ НА ВСЮ ШИРИНУ */
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: var(--radius-md); overflow: hidden; box-shadow: var(--shadow-sm);}
        .cal-header { background: #F8FAFC; text-align: center; padding: 12px 4px; font-size: 12px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;}
        .cal-cell { background: var(--card-bg); min-height: 160px; padding: 12px; position: relative; transition: var(--transition); display: flex; flex-direction: column; cursor: pointer;}
        .cal-cell:hover { background: #F8FAFC; box-shadow: inset 0 0 0 2px var(--primary-light); z-index: 2;}
        .cal-cell.empty { background: #F8FAFC; color: #CBD5E1; cursor: default; box-shadow: none;}
        
        .date-num { font-size: 16px; font-weight: 800; color: var(--text-main); margin-bottom: 8px;}
        .date-num.today { display: inline-flex; align-items: center; justify-content: center; background: var(--primary); color: white; width: 28px; height: 28px; border-radius: 50%; box-shadow: 0 4px 10px rgba(79,70,229,0.3);}
        
        /* Карточки туров в ячейках */
        .tour-chip { background: #FFFFFF; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px; font-size: 11px; font-weight: 600; color: var(--text-main); display: flex; flex-direction: column; gap: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); margin-bottom: 6px; text-decoration: none; transition: var(--transition);}
        .tour-chip:hover { border-color: var(--primary); box-shadow: 0 2px 5px rgba(0,0,0,0.05); transform: translateY(-1px);}
        .chip-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 700; font-size: 12px;}
        .chip-meta { display: flex; justify-content: space-between; color: var(--text-muted); font-size: 10px;}
        
        /* Статусы в ячейке */
        .status-pill { font-size: 11px; font-weight: 700; padding: 4px 8px; border-radius: 6px; margin-bottom: 6px; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
        .sp-closed { background: #FEE2E2; color: #DC2626; border: 1px solid #FECACA; }
        .sp-partial { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
        .sp-open { background: #DCFCE7; color: #16A34A; border: 1px solid #BBF7D0; }
        .sp-timeoff { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }

        .is-closed { background: #FEF2F2; border-color: #FCA5A5; }
        .is-partial { background: #FFFBEB; border-color: #FDE68A; }
        .is-opened { background: #ECFDF5; border-color: #6EE7B7; }
        .not-working { background: #F8FAFC; opacity: 0.7; }

        /* ФОРМЫ И ЭЛЕМЕНТЫ УПРАВЛЕНИЯ */
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.03em;}
        .t-input { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; background: #F8FAFC; color: var(--text-main); box-sizing: border-box; font-family: inherit; font-weight: 500; transition: var(--transition); outline: none;}
        .t-input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light);}
        
        select[multiple].t-input { height: auto; min-height: 120px; }
        select[multiple].t-input option { padding: 8px 10px; border-radius: 4px; margin-bottom: 2px;}
        select[multiple].t-input option:checked { background: var(--primary-light); color: var(--primary); font-weight: bold;}

        .btn-save { background: var(--primary); color: white; border: none; padding: 10px 24px; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; font-size: 14px; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); height: 42px; display: inline-flex; justify-content: center; align-items: center;}
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}
        
        .btn-action { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; font-size: 14px; transition: var(--transition); width: 100%; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); height: 44px;}
        .btn-action:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}

        .btn-cancel { background: #FEF2F2; color: #EF4444; border: 1px solid #FECACA; padding: 12px 24px; border-radius: var(--radius-sm); font-weight: 700; font-size: 14px; width: 100%; cursor: pointer; transition: var(--transition); height: 44px; display: inline-flex; justify-content: center; align-items: center; text-decoration: none;}
        .btn-cancel:hover { background: #FEE2E2; color: #DC2626; border-color: #FCA5A5; }

        /* СЕТКА НИЖНИХ СПИСКОВ */
        .lists-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }

        /* Модальные окна */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.5); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 20px; box-sizing: border-box; opacity: 0; transition: opacity 0.3s ease;}
        .modal-overlay.show { opacity: 1; }
        .modal-content { background: var(--card-bg); padding: 30px; border-radius: 20px; max-width: 480px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transform: translateY(20px); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); position: relative;}
        .modal-overlay.show .modal-content { transform: translateY(0); }
        .close-modal { position: absolute; top: 15px; right: 15px; background: #F1F5F9; color: var(--text-muted); border: none; width: 32px; height: 32px; border-radius: 50%; font-weight: bold; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: var(--transition);}
        .close-modal:hover { background: #E2E8F0; color: var(--text-main);}

        /* Списки правил в карточках */
        .rule-list { display: flex; flex-direction: column; gap: 10px; max-height: 250px; overflow-y: auto; padding-right: 5px;}
        .rule-item { background: #F8FAFC; border: 1px solid var(--border); padding: 12px; border-radius: var(--radius-sm); display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;}
        .rule-item .r-date { font-weight: 800; font-size: 14px; color: var(--text-main); margin-bottom: 4px;}
        .rule-item .r-desc { font-size: 12px; color: var(--text-muted);}
        .btn-del { background: #FEF2F2; color: #EF4444; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; text-decoration: none; transition: var(--transition); flex-shrink: 0;}
        .btn-del:hover { background: #FEE2E2; color: #DC2626;}

        /* Умные вкладки в модалке */
        .tabs { display: flex; gap: 5px; background: #F1F5F9; padding: 4px; border-radius: var(--radius-sm); margin-bottom: 25px;}
        .tab-btn { flex: 1; padding: 10px; border: none; background: transparent; font-weight: 700; font-size: 13px; color: var(--text-muted); border-radius: 6px; cursor: pointer; transition: var(--transition);}
        .tab-btn.active { background: var(--card-bg); color: var(--text-main); box-shadow: var(--shadow-sm);}
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease;}
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .radio-group { display: flex; flex-direction: column; gap: 10px; background: #F8FAFC; padding: 15px; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-bottom: 20px;}
        
        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%); transition: all 0.3s ease; display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        @media (max-width: 992px) {
            .lists-grid { grid-template-columns: 1fr; }
            .cal-header { font-size: 10px; padding: 8px 2px; }
            .cal-cell { padding: 6px; min-height: 100px; }
            .settings-bar { flex-direction: column; align-items: stretch; }
            .settings-form { justify-content: center; }
            .wd-grid { flex-wrap: wrap; justify-content: center; }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>Центр управления расписанием</h2>
    </div>

    <div class="settings-bar">
        <div class="settings-info">
            <h3>🛠 Базовый график работы</h3>
            <p>Дни недели, в которые компания работает по умолчанию</p>
        </div>
        <form method="POST" class="settings-form">
            <input type="hidden" name="save_working_days" value="1">
            <div class="wd-grid">
                <?php 
                $week_ru = [1=>'Пн', 2=>'Вт', 3=>'Ср', 4=>'Чт', 5=>'Пт', 6=>'Сб', 7=>'Вс'];
                foreach ($week_ru as $num => $name): 
                    $checked = in_array((string)$num, $default_working_days) ? 'checked' : '';
                ?>
                    <div>
                        <input type="checkbox" name="wd[]" value="<?= $num ?>" id="wd_<?= $num ?>" <?= $checked ?>>
                        <label for="wd_<?= $num ?>"><?= $name ?></label>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-save">Сохранить</button>
        </form>
    </div>

    <div class="card" style="padding: 20px;">
        <div class="card-header" style="margin-bottom: 15px; border: none; padding: 0;">
            <div class="cal-nav">
                <a href="?ym=<?= $prev_ym ?>" class="btn-nav">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </a>
                <div class="cal-title"><?= $month_title ?></div>
                <a href="?ym=<?= $next_ym ?>" class="btn-nav">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
            </div>
        </div>

        <div class="cal-grid">
            <div class="cal-header">Пн</div><div class="cal-header">Вт</div><div class="cal-header">Ср</div>
            <div class="cal-header">Чт</div><div class="cal-header">Пт</div><div class="cal-header">Сб</div><div class="cal-header">Вс</div>

            <?php
                $days_to_pad = $first_day_of_week - 1;
                for ($i = 0; $i < $days_to_pad; $i++) {
                    echo '<div class="cal-cell empty"></div>';
                }

                for ($day = 1; $day <= $days_in_month; $day++) {
                    $date_string = $ym . '-' . sprintf('%02d', $day);
                    $day_of_week = date('N', strtotime($date_string));
                    
                    $is_today = ($date_string === $today_date);
                    $today_class = $is_today ? 'today' : '';
                    
                    $is_working = in_array($day_of_week, $default_working_days);
                    $rule = $rules_map[$date_string] ?? null;
                    $timeoffs = $timeoffs_map[$date_string] ?? [];
                    $day_events = $events_map[$date_string] ?? [];

                    $cell_classes = ['cal-cell'];
                    if ($rule) {
                        if ($rule['action_type'] === 'close') {
                            if(is_array($rule['tours']) && count($rule['tours']) > 0 && $rule['tours'][0] !== 'all') {
                                $cell_classes[] = 'is-partial';
                            } else {
                                $cell_classes[] = 'is-closed';
                            }
                        } else {
                            $cell_classes[] = 'is-opened';
                        }
                    } elseif (!$is_working) {
                        $cell_classes[] = 'not-working';
                    }

                    $classes_str = implode(' ', $cell_classes);

                    echo "<div class='{$classes_str}' data-date='{$date_string}' onclick=\"openUnifiedModal('{$date_string}')\">";
                    echo "<div class='date-num {$today_class}'>{$day}</div>";

                    if ($rule) {
                        if ($rule['action_type'] === 'close') {
                            if(is_array($rule['tours']) && count($rule['tours']) > 0 && $rule['tours'][0] !== 'all') {
                                echo "<div class='status-pill sp-partial' title='{$rule['reason']}'>⚠️ Частично закрыто</div>";
                            } else {
                                echo "<div class='status-pill sp-closed' title='{$rule['reason']}'>🛑 Закрыто</div>";
                            }
                        } else {
                            echo "<div class='status-pill sp-open' title='{$rule['reason']}'>✅ Открыто принуд.</div>";
                        }
                    } elseif (!$is_working) {
                        echo "<div class='status-pill sp-closed'>⏸ Выходной день</div>";
                    }

                    foreach ($timeoffs as $to) {
                        echo "<div class='status-pill sp-timeoff' style='border-left: 3px solid ".getGuideColor($to['guide_name'])."'>🚷 {$to['guide_name']}</div>";
                    }

                    foreach ($day_events as $ev) {
                        $guide_color = getGuideColor($ev['guide'] ?? '');
                        $time_str = !empty($ev['time']) ? $ev['time'] : '';
                        
                        echo "
                        <a href='event.php?id={$ev['id']}' class='tour-chip' style='border-left: 4px solid {$guide_color};' onclick='event.stopPropagation();' title='Перейти в карточку заказа'>
                            <div class='chip-title'>" . htmlspecialchars($ev['tour_name'] ?? '') . "</div>
                            <div class='chip-meta'>
                                <span>" . ($time_str ? "⏱ {$time_str} " : "") . htmlspecialchars($ev['guide'] ?: 'Без гида') . "</span>
                                <span style='color:var(--primary); font-weight:800;'>👤 " . (int)($ev['seats_count'] ?? 0) . "</span>
                            </div>
                        </a>";
                    }

                    echo "</div>";
                }

                $remaining_cells = (7 - (($days_to_pad + $days_in_month) % 7)) % 7;
                for ($i = 0; $i < $remaining_cells; $i++) {
                    echo '<div class="cal-cell empty"></div>';
                }
            ?>
        </div>
        <div style="font-size: 12px; color: var(--text-muted); margin-top: 15px; text-align: center;">
            Кликните на карточку тура, чтобы перейти в заказ. Кликните на свободное место даты, чтобы добавить тур или настроить исключение.
        </div>
    </div>

    <div class="lists-grid">
        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">📅 Будущие исключения и выходные</div>
            <div class="rule-list">
                <?php if (empty($future_rules)): ?>
                    <div style="text-align:center; padding: 20px; color:var(--text-muted); font-size:13px;">Нет исключений на будущее.</div>
                <?php endif; ?>

                <?php foreach ($future_rules as $r): ?>
                    <div class="rule-item">
                        <div>
                            <div class="r-date"><?= date('d.m.Y', strtotime($r['block_date'])) ?> 
                                <span style="font-size:10px; padding:2px 6px; border-radius:4px; margin-left:4px; <?= $r['action_type'] === 'close' ? 'background:#FEE2E2; color:#DC2626;' : 'background:#DCFCE7; color:#16A34A;' ?>">
                                    <?= $r['action_type'] === 'close' ? 'Закрыто' : 'Открыто' ?>
                                </span>
                            </div>
                            <div class="r-desc"><?= htmlspecialchars($r['reason'] ?: 'Без причины') ?> (Туры: <?= $r['tours'] === 'all' ? 'Все' : 'Выбраны' ?>)</div>
                        </div>
                        <a href="?ym=<?= $ym ?>&del_rule=<?= $r['block_date'] ?>" class="btn-del" title="Удалить правило" onclick="return confirm('Удалить правило?');">✕</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card" style="margin-bottom: 0;">
            <div class="card-header">🚷 Будущие отгулы гидов</div>
            <div class="rule-list">
                <?php if (empty($timeoffs_future)): ?>
                    <div style="text-align:center; padding: 20px; color:var(--text-muted); font-size:13px;">Нет запланированных отгулов.</div>
                <?php endif; ?>

                <?php foreach ($timeoffs_future as $to): ?>
                    <div class="rule-item" style="border-left: 3px solid <?= getGuideColor($to['guide_name']) ?>;">
                        <div>
                            <div class="r-date"><?= date('d.m.Y', strtotime($to['date_off'])) ?> </div>
                            <div class="r-desc"><strong style="color:var(--text-main);"><?= htmlspecialchars($to['guide_name']) ?></strong> — <?= htmlspecialchars($to['reason'] ?: 'Личные дела') ?></div>
                        </div>
                        <a href="?ym=<?= $ym ?>&del_timeoff=<?= $to['id'] ?>" class="btn-del" title="Удалить отгул" onclick="return confirm('Отменить отгул?');">✕</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>


<div id="unifiedModal" class="modal-overlay">
    <div class="modal-content">
        <button type="button" class="close-modal" onclick="closeUnifiedModal()">✕</button>
        <h3 style="margin-top:0; margin-bottom: 20px; font-size:20px; color:var(--text-main); font-weight:800;">
            Настройка дня: <span id="modalDateDisplay" style="color:var(--primary);"></span>
        </h3>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('tab-generate')">🚌 Добавить тур</button>
            <button class="tab-btn" onclick="switchTab('tab-rule')" id="btnTabRule">🛑 Исключения</button>
            <button class="tab-btn" onclick="switchTab('tab-timeoff')">🏖️ Отгулы</button>
        </div>

        <div id="tab-generate" class="tab-content active">
            <form method="POST">
                <input type="hidden" name="add_single_event" value="1">
                <input type="hidden" name="tour_date" id="addTourDateInp">
                
                <div class="form-group">
                    <label>Выберите тур *</label>
                    <select name="tour_id" id="modalTourSelect" class="t-input" required onchange="updateModalTime()">
                        <option value="" disabled selected>-- Каталог туров --</option>
                        <?php foreach($tours as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:15px;">
                    <div class="form-group" style="flex:2;">
                        <label>Гид</label>
                        <select name="guide" class="t-input">
                            <option value="Не назначен">Оставить без гида</option>
                            <?php foreach($guides as $g): ?><option value="<?= htmlspecialchars($g['name']) ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Время начала</label>
                        <input type="time" name="time" id="modalTimeInp" class="t-input">
                    </div>
                </div>
                <button type="submit" class="btn-action" style="margin-top: 10px;">Добавить тур на эту дату</button>
            </form>
        </div>

        <div id="tab-rule" class="tab-content">
            <form method="POST">
                
                <div style="display:flex; gap:15px;">
                    <div class="form-group" style="flex:1;">
                        <label>Период С *</label>
                        <input type="date" name="rule_date_start" id="ruleDateStart" class="t-input" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Период ПО</label>
                        <input type="date" name="rule_date_end" id="ruleDateEnd" class="t-input">
                    </div>
                </div>
                
                <div class="radio-group">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="action_type" value="close" checked> 🛑 Сделать выходным (Закрыть бронирование)
                    </label>
                    <hr style="border:none; border-top:1px solid #E2E8F0; margin: 5px 0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; color: #10B981;">
                        <input type="radio" name="action_type" value="open"> ✅ Сделать рабочим (Игнорировать график)
                    </label>
                </div>
                
                <div class="form-group">
                    <label>Для каких туров применить правило?</label>
                    <select name="tours[]" id="ruleToursSelect" class="t-input" multiple>
                        <option value="all" selected>Все туры (Глобально)</option>
                        <?php foreach($tours as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">Удерживайте Ctrl (Cmd) для выбора нескольких туров.</div>
                </div>

                <div class="form-group">
                    <label>Причина (опционально)</label>
                    <input type="text" name="rule_reason" id="ruleReasonInp" class="t-input" placeholder="Например: Праздник, Ремонт автобуса...">
                </div>
                
                <div style="display:flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" name="save_date_rule" class="btn-action" style="flex: 1;">Сохранить</button>
                    <button type="submit" name="delete_date_rule" id="deleteRuleBtn" class="btn-cancel" style="flex: 1; display:none;">Сбросить</button>
                </div>
            </form>
        </div>

        <div id="tab-timeoff" class="tab-content">
            <form method="POST">
                <input type="hidden" name="save_timeoff" value="1">
                
                <div class="form-group">
                    <label>Выберите гида *</label>
                    <select name="guide_name" class="t-input" required>
                        <option value="" disabled selected>-- Список гидов --</option>
                        <?php foreach($guides as $g): ?><option value="<?= htmlspecialchars($g['name']) ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex; gap:15px;">
                    <div class="form-group" style="flex:1;">
                        <label>Период С *</label>
                        <input type="date" name="timeoff_date_start" id="timeoffDateStart" class="t-input" required>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Период ПО</label>
                        <input type="date" name="timeoff_date_end" id="timeoffDateEnd" class="t-input">
                    </div>
                </div>

                <div class="form-group">
                    <label>Причина отгула</label>
                    <input type="text" name="timeoff_reason" class="t-input" placeholder="Болеет, Отпуск...">
                </div>
                <button type="submit" class="btn-action" style="background:#F59E0B; margin-top:10px;">Назначить отгул</button>
            </form>
        </div>
    </div>
</div>

<script>
    const tourTimes = {
        <?php foreach($tours as $t) {
            echo $t['id'] . ": '" . addslashes($t['default_start_time'] ?? '10:00') . "',\n";
        } ?>
    };

    function updateModalTime() {
        const tId = document.getElementById('modalTourSelect').value;
        if (tourTimes[tId]) {
            document.getElementById('modalTimeInp').value = tourTimes[tId];
        }
    }

    const rulesMap = <?= json_encode($rules_map, JSON_UNESCAPED_UNICODE) ?: '{}' ?>;

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

    function switchTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        
        const btn = Array.from(document.querySelectorAll('.tab-btn')).find(b => b.getAttribute('onclick').includes(tabId));
        if(btn) btn.classList.add('active');

        document.getElementById(tabId).classList.add('active');
    }

    function openUnifiedModal(dateStr) {
        if (!dateStr) return;
        
        const parts = dateStr.split('-');
        const displayDate = `${parts[2]}.${parts[1]}.${parts[0]}`;
        
        document.getElementById('modalDateDisplay').textContent = displayDate;
        
        document.getElementById('addTourDateInp').value = dateStr;
        document.getElementById('ruleDateStart').value = dateStr;
        document.getElementById('ruleDateEnd').value = dateStr;
        document.getElementById('timeoffDateStart').value = dateStr;
        document.getElementById('timeoffDateEnd').value = dateStr;
        
        document.getElementById("ruleReasonInp").value = "";
        document.querySelector('input[name="action_type"][value="close"]').checked = true;

        const delBtn = document.getElementById('deleteRuleBtn');
        const rule = rulesMap[dateStr];
        const toursSelect = document.getElementById('ruleToursSelect');
        
        if (rule) {
            document.querySelector(`input[name="action_type"][value="${rule.action_type}"]`).checked = true;
            document.getElementById("ruleReasonInp").value = rule.reason || "";
            
            Array.from(toursSelect.options).forEach(opt => {
                if (rule.tours === 'all') {
                    opt.selected = (opt.value === 'all');
                } else {
                    opt.selected = rule.tours.includes(opt.value);
                }
            });

            delBtn.style.display = "inline-flex";
            switchTab('tab-rule');
        } else {
            Array.from(toursSelect.options).forEach(opt => opt.selected = (opt.value === 'all'));
            delBtn.style.display = "none";
            switchTab('tab-generate');
        }

        let modal = document.getElementById('unifiedModal');
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('show'), 10);
    }

    function closeUnifiedModal() {
        let modal = document.getElementById('unifiedModal');
        modal.classList.remove('show');
        setTimeout(() => modal.style.display = 'none', 300);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg) {
            const messages = {
                'event_added': 'Тур успешно добавлен в расписание!',
                'wd_saved': 'График рабочих дней обновлен',
                'rule_saved': 'Правило для дат сохранено',
                'rule_deleted': 'Правило сброшено (удалено)',
                'timeoff_saved': 'Отгул гида назначен',
                'timeoff_deleted': 'Отгул гида отменен'
            };
            if (messages[msg]) showToast(messages[msg], msg.includes('deleted') || msg.includes('cannot') ? 'error' : 'success');
            window.history.replaceState({}, document.title, window.location.pathname + '?ym=<?= $ym ?>');
        }

        document.getElementById('unifiedModal').addEventListener('mousedown', function(e) {
            if (e.target === this) closeUnifiedModal();
        });
    });
</script>

</body>
</html>