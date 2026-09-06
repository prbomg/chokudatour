<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';
require_once __DIR__ . '/homepage_helpers.php';
require_once __DIR__ . '/request_helpers.php';
$filter_error = '';
try { $home_filters = homeFilters($_GET); } catch (InvalidArgumentException $e) { $home_filters = []; $filter_error = $e->getMessage(); }
$home_url = homeUrl($home_filters);
$return_url = homeReturnUrl($_POST['return_to'] ?? $home_url);
$context_suffix = '&return_to=' . rawurlencode($home_url);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['ajax_load_past']) || isset($_POST['delete_event']) || isset($_POST['update_event']) || isset($_POST['ajax_add_event']) || isset($_POST['add_expense']))) requireFormToken();
require_once __DIR__ . '/participant_seats.php';
$participant_seats_sql = participantSeatsSql($pdo);

// Функция для генерации уникального цвета гида
function getGuideColorStyle($guideName) {
    if (empty($guideName) || $guideName === 'Не назначен') {
        return "background: #F1F5F9; color: #475569; border-color: transparent;";
    }
    $hash = substr(md5($guideName), 0, 6);
    $hue = hexdec($hash) % 360; 
    return "background: hsl({$hue}, 85%, 94%); color: hsl({$hue}, 85%, 25%); border-color: transparent;";
}

$pdo->exec("SET SESSION group_concat_max_len = 10000;");

// --- АВТО-ОБНОВЛЕНИЕ БАЗЫ ДАННЫХ ---
try { $pdo->exec("ALTER TABLE expenses ADD COLUMN category VARCHAR(255) DEFAULT 'Прочее'"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE expenses ADD COLUMN description TEXT DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE expenses ADD COLUMN receipt_path VARCHAR(255) DEFAULT NULL"); } catch(PDOException $e) {}

// НОВЫЕ КОЛОНКИ ДЛЯ ВРЕМЕНИ
try { $pdo->exec("ALTER TABLE events ADD COLUMN time VARCHAR(50) DEFAULT ''"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN default_start_time VARCHAR(50) DEFAULT '10:00'"); } catch(PDOException $e) {}

// Изменение выездов: проверки и запись выполняются до уведомления.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_event']) && $current_user_role === 'admin') {
    $del_id = (int)$_POST['delete_event'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM expenses WHERE event_id = ?")->execute([$del_id]);
        $pdo->prepare("DELETE FROM participants WHERE event_id = ?")->execute([$del_id]);
        $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$del_id]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    header("Location: " . $return_url); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['update_event']) || isset($_POST['ajax_add_event']))) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($current_user_role !== 'admin') throw new InvalidArgumentException('Доступ запрещён.');
        $details = homeEventDetails($pdo, $_POST);
        if (isset($_POST['update_event'])) {
            requireEventAccess($pdo, (int)$_POST['event_id'], $current_user_role, $current_user_name);
            $pdo->prepare("UPDATE events SET tour_date=?, time=?, tour_id=?, guide=?, notes=? WHERE id=?")
                ->execute([$details['date'], $details['time'], $details['tour_id'], $details['guide'], $details['notes'], (int)$_POST['event_id']]);
            header('Location: ' . $return_url); exit;
        }
        $pdo->prepare("INSERT INTO events (tour_date, time, tour_id, guide, notes) VALUES (?, ?, ?, ?, ?)")
            ->execute([$details['date'], $details['time'], $details['tour_id'], $details['guide'], $details['notes']]);
        $notification_failed = false;
        try {
            require_once 'telegram.php';
            $msg = "🆕 <b>Новая заявка создана вручную!</b>\nТур: " . htmlspecialchars($details['tour_name'], ENT_QUOTES)
                . "\nДата: " . $details['date'] . " в " . $details['time'] . "\nГид: " . htmlspecialchars($details['guide'], ENT_QUOTES);
            $notification_failed = sendTelegramMessage($msg) === false;
        } catch (Throwable $e) { $notification_failed = true; }
        echo json_encode(['status' => 'success', 'notification_failed' => $notification_failed]);
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Не удалось сохранить экскурсию. Повторите попытку.']);
    }
    exit;
}

// --- ДИНАМИЧЕСКАЯ ПОДГРУЗКА ПРОШЕДШИХ ТУРОВ (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_load_past'])) {
    ini_set('display_errors', 0); error_reporting(0); while (ob_get_level()) { ob_end_clean(); } 
    try {
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $limit = 5;
        $past_filters = homeFilters($_POST);
        $home_url = homeUrl($past_filters);
        $context_suffix = '&return_to=' . rawurlencode($home_url);
        $params = [];
        $where = homeFilterWhere($past_filters, $params, true);

        if ($current_user_role === 'admin') {
            // Единый подсчет мест для всех экранов
            $sql = "SELECT e.*, t.name AS tour_name,
                    COALESCE((SELECT SUM({$participant_seats_sql}) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as seats_count,
                    COALESCE((SELECT SUM(price) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as total_price
                    FROM events e JOIN tours_catalog t ON e.tour_id = t.id 
                    WHERE {$where} ORDER BY e.tour_date DESC, e.time DESC, e.id DESC LIMIT {$limit} OFFSET $offset";
        } else {
            $sql = "SELECT e.*, t.name AS tour_name, t.duration, t.coordinates 
                    FROM events e JOIN tours_catalog t ON e.tour_id = t.id 
                    WHERE {$where} AND e.guide = ? ORDER BY e.tour_date DESC, e.time DESC, e.id DESC LIMIT $limit OFFSET $offset";
            $params[] = $_SESSION['user_name'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $past_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $past_events = array_reverse($past_events);
        $event_participants = homeParticipants($pdo, $past_events);
        
        $html = '';
        $forms = '';

        if ($current_user_role === 'admin') {
            $tours_list = $pdo->query("SELECT id, name FROM tours_catalog ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $guides_list = $pdo->query("SELECT name FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($past_events as $ev) {
            $months_ru = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
            $ts = strtotime($ev['tour_date']);
            $date_formatted = date('d.m.Y', $ts);
            $date_str = date('j', $ts) . ' ' . $months_ru[date('n', $ts)];

            if ($current_user_role === 'admin') {
                $tour_name = htmlspecialchars($ev['tour_name']);
                $guide = htmlspecialchars($ev['guide'] ?: 'Не назначен');
                $guide_style = getGuideColorStyle($ev['guide']);
                $income = number_format($ev['total_price'], 0, '', ' ') . ' ₽';
                $time_val = !empty($ev['time']) ? htmlspecialchars($ev['time']) : '';
                $time_html = $time_val ? "<div style='color: var(--primary); font-size: 11px; font-weight: 700; margin-top: 4px;'>⏱ {$time_val}</div>" : "";
                
                $clients_html = homeClientsHtml($event_participants[$ev['id']] ?? [], (int)$ev['id'], $home_url);

                $note_html = !empty($ev['notes']) 
                    ? "<div class='note-truncate' data-note='".htmlspecialchars($ev['notes'], ENT_QUOTES)."' onclick=\"showNoteModal(this.getAttribute('data-note'))\">" . htmlspecialchars($ev['notes']) . "</div>"
                    : "—";

                $forms .= "<form id='formEditE_{$ev['id']}' method='POST' action='" . htmlspecialchars($home_url, ENT_QUOTES) . "'>" . formTokenInput() . "<input type='hidden' name='update_event' value='1'><input type='hidden' name='event_id' value='{$ev['id']}'></form>";
                
                $html .= "<tr class='view_e_{$ev['id']} past-event-row'>
                    <td data-label='Дата' style='white-space: nowrap;'><strong style='color:#64748B;'>{$date_formatted}</strong>{$time_html}</td>
                    <td data-label='Тур'><a href='event.php?id={$ev['id']}{$context_suffix}' class='link-tour' style='color:#475569;'>{$tour_name}</a></td>
                    <td data-label='Гид'><span class='guide-tag' style='{$guide_style} opacity: 0.8;'>{$guide}</span></td>
                    <td data-label='Мест'><span class='seats-badge' style='background:#F1F5F9; color:#475569;'>{$ev['seats_count']}</span></td>
                    <td data-label='Доход' class='col-price' style='color: #059669; opacity: 0.8;'>{$income}</td>
                    <td data-label='Туристы'>{$clients_html}</td>
                    <td data-label='Примечание' class='col-note'>{$note_html}</td>
                    <td data-label='Действия' style='text-align: right; white-space: nowrap;'>
                        <div class='action-cell'>
                            <button type='button' class='btn-icon btn-edit' onclick='toggleEditE({$ev['id']})' title='Редактировать'>
                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'></path><path d='M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z'></path></svg>
                            </button>
                            " . deleteControl($home_url, 'delete_event', (int)$ev['id'], 'Удалить экскурсию вместе с её туристами и расходами?') . "
                        </div>
                    </td>
                </tr>";

                $html .= "<tr class='edit_e_{$ev['id']} edit_form_row' style='display:none; background:#F8FAFC;'>";
                $html .= "<td data-label='Дата / Время'><input form='formEditE_{$ev['id']}' type='date' name='tour_date' class='t-input' value='".htmlspecialchars($ev['tour_date'])."' required style='margin-bottom:4px;'><input form='formEditE_{$ev['id']}' type='time' name='time' class='t-input' value='".htmlspecialchars($ev['time'] ?? '')."'></td>";
                $html .= "<td data-label='Тур'><select form='formEditE_{$ev['id']}' name='tour_id' class='t-input' required>";
                foreach ($tours_list as $t) { $sel = $t['id'] == $ev['tour_id'] ? 'selected' : ''; $html .= "<option value='{$t['id']}' {$sel}>".htmlspecialchars($t['name'])."</option>"; }
                $html .= "</select></td>";
                $html .= "<td data-label='Гид'><select form='formEditE_{$ev['id']}' name='guide' class='t-input' required><option value='Не назначен' ".($ev['guide'] === 'Не назначен' ? 'selected' : '').">Не назначен</option>";
                foreach ($guides_list as $g) { $sel = $ev['guide'] === $g ? 'selected' : ''; $html .= "<option value='".htmlspecialchars($g)."' {$sel}>".htmlspecialchars($g)."</option>"; }
                $html .= "</select></td>";
                $html .= "<td data-label='Мест'><span class='seats-badge'>{$ev['seats_count']}</span></td>";
                $html .= "<td data-label='Доход' class='col-price' style='color: #059669;'>{$income}</td>";
                $html .= "<td data-label='Туристы'>{$clients_html}</td>";
                $html .= "<td data-label='Примечание'><input form='formEditE_{$ev['id']}' type='text' name='notes' class='t-input' value='".htmlspecialchars($ev['notes'], ENT_QUOTES)."'></td>";
                $html .= "<td data-label='Действия' style='text-align: right; white-space: nowrap;'><div class='action-cell'>
                            <button form='formEditE_{$ev['id']}' type='submit' class='btn-icon btn-view' title='Сохранить'>
                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'></polyline></svg>
                            </button> 
                            <button type='button' class='btn-icon btn-del' onclick='cancelEditE({$ev['id']})' title='Отмена'>
                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><line x1='18' y1='6' x2='6' y2='18'></line><line x1='6' y1='6' x2='18' y2='18'></line></svg>
                            </button>
                          </div></td>";
                $html .= "</tr>";

            } else {
                $p_stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id = ? AND status != 'Отмена'");
                $p_stmt->execute([$ev['id']]);
                $tourists = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $html .= "<div class='g-card past-event-card'>";
                
                $time_str = !empty($ev['time']) ? ' • ' . htmlspecialchars($ev['time']) : '';
                $html .= "<div class='g-card-date'>🔙 {$date_str}{$time_str}</div>";
                
                $html .= "<h2 class='g-card-title'>" . htmlspecialchars($ev['tour_name']) . "</h2>";
                
                $html .= "<div class='g-tourists'>";
                $html .= "<div class='g-tourists-title'>Туристы (" . count($tourists) . " групп)</div>";
                if (empty($tourists)) $html .= "<div class='empty-state-mini'>Нет участников</div>";
                
                foreach ($tourists as $t) {
                    $clean_phone = preg_replace('/[^0-9]/', '', $t['phone']);
                    if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }
                    $p_places = participantSeats($t);
                    $html .= "<div class='g-tourist-row'>";
                    $html .= "<div class='g-tourist-info'><span class='g-tourist-name'>" . htmlspecialchars($t['client_name'] ?? $t['name'] ?? '') . "</span><span class='g-tourist-seats'>{$p_places} чел.</span></div>";
                    $html .= "<div class='g-tourist-actions'><a href='tel:{$t['phone']}' class='g-btn-icon g-btn-call'>📞</a><a href='https://wa.me/{$clean_phone}' target='_blank' class='g-btn-icon g-btn-wa'>💬</a></div>";
                    $html .= "</div>";
                }
                $html .= "</div>";

                $html .= "<div class='g-card-actions'>";
                $html .= "<a href='event.php?id={$ev['id']}{$context_suffix}' class='g-btn g-btn-route'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'></path><polyline points='14 2 14 8 20 8'></polyline><line x1='16' y1='13' x2='8' y2='13'></line><line x1='16' y1='17' x2='8' y2='17'></line><polyline points='10 9 9 9 8 9'></polyline></svg> Детали</a>";
                $html .= "<button type='button' class='g-btn g-btn-expense' onclick=\"openExpenseModal({$ev['id']}, '" . htmlspecialchars($ev['tour_name'], ENT_QUOTES) . "')\"><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'></rect><line x1='12' y1='8' x2='12' y2='16'></line><line x1='8' y1='12' x2='16' y2='12'></line></svg> Чек</button>";
                $html .= "</div></div>";
            }
        }
        echo json_encode(['status' => 'success', 'html' => $html, 'forms' => $forms, 'count' => count($past_events)]);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

// Получаем категории расходов для всех ролей
$expense_cats = [];
try {
    $expense_cats = $pdo->query("SELECT name FROM expense_categories ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// --- ДОБАВЛЕНИЕ РАСХОДА (ДЛЯ ГИДОВ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $event_id = (int)$_POST['event_id'];
    requireEventAccess($pdo, $event_id, $current_user_role, $current_user_name);
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount < 1) { http_response_code(422); exit('Укажите положительную сумму расхода.'); }
    $category = trim($_POST['category'] ?? 'Прочее');
    $description = trim($_POST['description'] ?? '');
    $receipt_path = '';

    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            $new_name = 'uploads/rec_' . time() . '_' . rand(100,999) . '.' . $ext;
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $new_name)) { $receipt_path = $new_name; }
        }
    }

    $pdo->prepare("INSERT INTO expenses (event_id, amount, category, description, receipt_path) VALUES (?, ?, ?, ?, ?)")->execute([$event_id, $amount, $category, $description, $receipt_path]);
    header("Location: " . $return_url . (strpos($return_url, "?") === false ? "?" : "&") . "msg=expense_added"); exit;
}

$show_load_past = !$filter_error && empty($home_filters['date_from']) && empty($home_filters['date_to']);

// --- ПОДГОТОВКА ДАННЫХ ---
if ($current_user_role === 'admin') {
    $tours = $pdo->query("SELECT * FROM tours_catalog ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $guides = $pdo->query("SELECT * FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Единый подсчет мест для всех экранов
    $sql = "SELECT e.*, t.name AS tour_name,
            COALESCE((SELECT SUM({$participant_seats_sql}) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as seats_count,
            COALESCE((SELECT SUM(price) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as total_price,
            COALESCE((SELECT SUM(amount) FROM expenses WHERE event_id = e.id), 0) as total_expenses
            FROM events e JOIN tours_catalog t ON e.tour_id = t.id WHERE 1=1";
    $params = [];

    $sql .= ' AND ' . ($filter_error ? '1=0' : homeFilterWhere($home_filters, $params));
    $sort_col = $home_filters['sort'] ?? 'tour_date';
    $sort_dir = ($home_filters['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $sql .= $sort_col === 'tour_date' ? " ORDER BY tour_date $sort_dir, time ASC, e.id ASC" : " ORDER BY $sort_col $sort_dir, tour_date ASC, time ASC, e.id ASC";

    $stmt = $pdo->prepare($sql); $stmt->execute($params); $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $event_participants = homeParticipants($pdo, $events);

    $dash_tours = count($events); $dash_clients = 0; $dash_income = 0; $dash_expenses = 0;
    foreach ($events as $ev) {
        $dash_clients += $ev['seats_count'];
        $dash_income += $ev['total_price'];
        $dash_expenses += $ev['total_expenses'];
    }
    $dash_profit = $dash_income - $dash_expenses;
} else {
    $guide_name = $_SESSION['user_name'];
    $stmt_g = $pdo->prepare("SELECT e.*, t.name AS tour_name, t.duration, t.coordinates 
                             FROM events e JOIN tours_catalog t ON e.tour_id = t.id 
                             WHERE e.guide = ? AND e.tour_date >= CURDATE() ORDER BY e.tour_date ASC, e.time ASC");
    $stmt_g->execute([$guide_name]);
    $guide_events = $stmt_g->fetchAll(PDO::FETCH_ASSOC);
}

// Быстрые даты
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$day_of_week = date('N'); 
$days_to_sunday = 7 - $day_of_week;
$current_week_end = date('Y-m-d', strtotime("+$days_to_sunday days"));
$next_week_start = date('Y-m-d', strtotime("+$days_to_sunday days +1 day"));
$next_week_end = date('Y-m-d', strtotime("+$days_to_sunday days +7 days"));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Главная</title>
    <link rel="stylesheet" href="assets/homepage-base.css">
    <link rel="stylesheet" href="assets/homepage-workspace.css">
</head>
<body class="workspace-home">

<div id="ajaxFormsContainer"></div>

<div id="toast-container"></div>

<?php if ($current_user_role === 'admin'): ?>
<div class="container">
    <?php include 'navbar.php'; ?>
    <header class="header-box workspace-header">
        <div><div class="workspace-eyebrow">CHOKUDA TOUR · РАБОЧЕЕ ПРОСТРАНСТВО</div><h1>Выезды</h1><p>Поездки, туристы и всё, что важно для организации.</p></div>
        <button type="button" class="workspace-add" id="toggleAddEvent" aria-controls="addEventDialog" aria-haspopup="dialog">+ Добавить выезд</button>
    </header>
    
    <?php if ($filter_error): ?><p role="alert" style="color:#B91C1C;"><?= htmlspecialchars($filter_error) ?></p><?php endif; ?>
    <p id="summaryScope" style="color:var(--text-muted); font-size:13px;">
        Итоги выбранных выездов: с <?= htmlspecialchars($home_filters['date_from'] ?? date('Y-m-d')) ?>
        <?= isset($home_filters['date_to']) ? 'по ' . htmlspecialchars($home_filters['date_to']) : 'и далее' ?>.
        Подгруженная история в эти итоги не входит. Для расчёта за прошлый период выберите даты.
    </p>
    <div class="dash-grid">
        <div class="dash-card profit"><div class="dash-title">Чистая прибыль</div><div class="dash-val val-green"><?= number_format($dash_profit, 0, '', ' ') ?> ₽</div></div>
        <div class="dash-card"><div class="dash-title">Всего дохода</div><div class="dash-val"><?= number_format($dash_income, 0, '', ' ') ?> ₽</div></div>
        <div class="dash-card"><div class="dash-title">Всего расходов</div><div class="dash-val val-red"><?= number_format($dash_expenses, 0, '', ' ') ?> ₽</div></div>
        <div class="dash-card"><div class="dash-title">Выездов (мест)</div><div class="dash-val"><?= $dash_tours ?> <span style="font-size: 14px; color: var(--text-muted); font-weight:600;">(<?= $dash_clients ?> чел.)</span></div></div>
    </div>

    <div class="quick-filters">
        <a href="<?= htmlspecialchars(homeUrl($home_filters, ['date_from' => $today, 'date_to' => $today]), ENT_QUOTES) ?>" class="pill">Сегодня</a>
        <a href="<?= htmlspecialchars(homeUrl($home_filters, ['date_from' => $tomorrow, 'date_to' => $tomorrow]), ENT_QUOTES) ?>" class="pill">Завтра</a>
        <a href="<?= htmlspecialchars(homeUrl($home_filters, ['date_from' => $today, 'date_to' => $current_week_end]), ENT_QUOTES) ?>" class="pill">До конца недели</a>
        <a href="<?= htmlspecialchars(homeUrl($home_filters, ['date_from' => $next_week_start, 'date_to' => $next_week_end]), ENT_QUOTES) ?>" class="pill">Следующая неделя</a>
        <a href="<?= htmlspecialchars(homeUrl($home_filters, ['guide_filter' => 'Не назначен']), ENT_QUOTES) ?>" class="pill">Без гида</a>
        <a href="index.php" class="pill pill-reset">Сбросить всё</a>
    </div>

    <form class="filters" method="GET">
        <div class="filter-group"><label>Дата от</label><input type="date" name="date_from" value="<?= htmlspecialchars($home_filters['date_from'] ?? '') ?>"></div>
        <div class="filter-group"><label>Дата до</label><input type="date" name="date_to" value="<?= htmlspecialchars($home_filters['date_to'] ?? '') ?>"></div>
        <div class="filter-group"><label>Тур</label><select name="tour_filter"><option value="">Все туры</option><?php foreach ($tours as $t): ?><option value="<?= $t['id'] ?>" <?= (($home_filters['tour_filter'] ?? '') == $t['id']) ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="filter-group"><label>Гид</label><select name="guide_filter"><option value="">Все гиды</option><option value="Не назначен" <?= ($home_filters['guide_filter'] ?? '') === 'Не назначен' ? 'selected' : '' ?>>Не назначен</option><?php foreach ($guides as $g): ?><option value="<?= htmlspecialchars($g['name']) ?>" <?= (($home_filters['guide_filter'] ?? '') === $g['name']) ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?></select></div>
        <div class="filter-group"><label>Сортировка</label><select name="sort"><?php foreach (['tour_date'=>'Дата','tour_name'=>'Название тура','guide'=>'Гид'] as $key=>$label): ?><option value="<?= $key ?>" <?= $sort_col === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="filter-group"><label>Порядок</label><select name="dir"><option value="asc" <?= $sort_dir === 'ASC' ? 'selected' : '' ?>>По возрастанию</option><option value="desc" <?= $sort_dir === 'DESC' ? 'selected' : '' ?>>По убыванию</option></select></div>
        <button type="submit" class="btn-filter">Применить</button>
    </form>

    <?php if ($show_load_past): ?>
        <div id="loadPastContainer" style="margin-bottom: 20px;">
            <p id="pastHistoryLabel" hidden style="font-size:13px; color:var(--text-muted);">Подгруженная история — по дате, отдельно от итогов основной выборки.</p>
            <button type="button" id="loadPastBtn" class="btn-load-more">⬆ Прошедшие туры</button>
        </div>
    <?php endif; ?>

    <dialog id="addEventDialog" class="add-event-dialog" aria-labelledby="addEventTitle">
        <div class="add-dialog-heading"><div><h2 id="addEventTitle">Новый выезд</h2><p>Выберите маршрут, дату и назначьте гида.</p></div><button type="button" class="btn-icon" data-close-add aria-label="Закрыть">✕</button></div>
        <div class="add-dialog-fields">
            <label class="add-dialog-wide" for="add_tour_id">Маршрут *<select form="ajaxAddEventForm" name="tour_id" id="add_tour_id" class="t-input" required onchange="updateDefaultTime()"><option value="" disabled selected>Выберите тур...</option><?php foreach ($tours as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></label>
            <label for="add_date">Дата *<input form="ajaxAddEventForm" id="add_date" type="date" name="tour_date" class="t-input" required></label>
            <label for="add_time">Время старта<input form="ajaxAddEventForm" type="time" name="time" id="add_time" class="t-input" oninput="this.dataset.manual='1'"></label>
            <p class="add-dialog-hint add-dialog-wide">Время подставится из маршрута. Его можно изменить вручную.</p>
            <label class="add-dialog-wide" for="add_guide">Гид<select form="ajaxAddEventForm" name="guide" id="add_guide" class="t-input" required><option value="Не назначен">Не назначен</option><?php foreach ($guides as $g): ?><option value="<?= htmlspecialchars($g['name']) ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?></select></label>
            <label class="add-dialog-wide" for="add_notes">Примечание<textarea form="ajaxAddEventForm" id="add_notes" name="notes" class="t-input" rows="3" placeholder="Пожелания и особенности поездки"></textarea></label>
        </div>
        <p id="addEventError" role="alert" hidden></p>
        <div class="add-dialog-actions"><button type="button" class="btn-cancel" data-close-add>Отмена</button><button form="ajaxAddEventForm" type="submit" class="workspace-add" id="submitAddBtn">Сохранить выезд</button></div>
    </dialog>

    <form id="ajaxAddEventForm" method="POST" action="<?= htmlspecialchars($home_url, ENT_QUOTES) ?>"><?= formTokenInput() ?><input type="hidden" name="ajax_add_event" value="1"></form>
    <?php foreach ($events as $ev): ?>
        <form id="formEditE_<?= $ev['id'] ?>" method="POST" action="<?= htmlspecialchars($home_url, ENT_QUOTES) ?>"><?= formTokenInput() ?>
            <input type="hidden" name="update_event" value="1">
            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
        </form>
    <?php endforeach; ?>

    <div class="list-heading"><h2>Список экскурсий</h2><span>Имена туристов · места каждой брони</span></div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th class="sortable" data-sort="tour_date" data-dir="<?= $sort_col === 'tour_date' && $sort_dir === 'ASC' ? 'desc' : 'asc' ?>"><a href="<?= htmlspecialchars(homeUrl($home_filters, ['sort'=>'tour_date', 'dir'=>($sort_col === 'tour_date' && $sort_dir === 'ASC') ? 'desc' : 'asc']), ENT_QUOTES) ?>" style="color:inherit; text-decoration:none;">Дата <?= $sort_col === 'tour_date' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                    <th class="sortable" data-sort="tour_name" data-dir="<?= $sort_col === 'tour_name' && $sort_dir === 'ASC' ? 'desc' : 'asc' ?>"><a href="<?= htmlspecialchars(homeUrl($home_filters, ['sort'=>'tour_name', 'dir'=>($sort_col === 'tour_name' && $sort_dir === 'ASC') ? 'desc' : 'asc']), ENT_QUOTES) ?>" style="color:inherit; text-decoration:none;">Название тура <?= $sort_col === 'tour_name' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                    <th class="sortable" data-sort="guide" data-dir="<?= $sort_col === 'guide' && $sort_dir === 'ASC' ? 'desc' : 'asc' ?>"><a href="<?= htmlspecialchars(homeUrl($home_filters, ['sort'=>'guide', 'dir'=>($sort_col === 'guide' && $sort_dir === 'ASC') ? 'desc' : 'asc']), ENT_QUOTES) ?>" style="color:inherit; text-decoration:none;">Гид <?= $sort_col === 'guide' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?></a></th>
                    <th>Мест</th>
                    <th class="col-price">Доход</th>
                    <th>Туристы</th>
                    <th class="col-note">Примечание</th>
                    <th style="text-align: right; width: 140px;">Действия</th>
                </tr>
            </thead>
            <tbody id="eventsTableBody">
                

                
                <?php if (count($events) === 0): ?>
                <tr>
                    <td colspan="8">
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <h3>Ничего не найдено</h3>
                            <p>На выбранные даты нет запланированных экскурсий.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($events as $ev): ?>
                
                <tr class="view_e_<?= $ev['id'] ?>">
                    <td data-label="Дата" style="white-space: nowrap;">
                        <strong><?= date('d.m.Y', strtotime($ev['tour_date'])) ?></strong>
                        <?php if (!empty($ev['time'])): ?>
                            <div style="color: var(--primary); font-size: 11px; font-weight: 700; margin-top: 4px;">⏱ <?= htmlspecialchars($ev['time']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td data-label="Тур"><a href="event.php?id=<?= $ev['id'] ?><?= htmlspecialchars($context_suffix, ENT_QUOTES) ?>" class="link-tour"><?= htmlspecialchars($ev['tour_name']) ?></a></td>
                    <td data-label="Гид"><span class="guide-tag" style="<?= getGuideColorStyle($ev['guide']) ?>"><?= htmlspecialchars($ev['guide'] ?: 'Не назначен') ?></span></td>
                    <td data-label="Мест"><span class="seats-badge"><?= $ev['seats_count'] ?></span></td>
                    <td data-label="Доход" class="col-price" style="color: #10B981;"><?= number_format($ev['total_price'], 0, '', ' ') ?> ₽</td>
                    <td data-label="Туристы">
                        <?php 
                        $clients_html = homeClientsHtml($event_participants[$ev['id']] ?? [], (int)$ev['id'], $home_url);
                        echo $clients_html;
                        ?>
                    </td>
                    <td data-label="Примечание" class="col-note">
                        <?php if (!empty($ev['notes'])): ?>
                            <div class="note-truncate" data-note="<?= htmlspecialchars($ev['notes'], ENT_QUOTES) ?>" onclick="showNoteModal(this.getAttribute('data-note'))">
                                <?= htmlspecialchars($ev['notes']) ?>
                            </div>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td data-label="Действия" style="text-align: right; white-space: nowrap;">
                        <div class="action-cell">
                            <button type="button" class="btn-icon btn-edit" onclick="toggleEditE(<?= $ev['id'] ?>)" title="Редактировать">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                            <?= deleteControl($home_url, 'delete_event', (int)$ev['id'], 'Удалить экскурсию вместе с её туристами и расходами?') ?>
                        </div>
                    </td>
                </tr>

                <tr class="edit_form_row edit_e_<?= $ev['id'] ?>" style="display: none;">
                    <td data-label="Дата">
                        <input form="formEditE_<?= $ev['id'] ?>" type="date" name="tour_date" class="t-input" value="<?= htmlspecialchars($ev['tour_date']) ?>" required style="margin-bottom:4px;">
                        <input form="formEditE_<?= $ev['id'] ?>" type="time" name="time" class="t-input" value="<?= htmlspecialchars($ev['time'] ?? '') ?>">
                    </td>
                    <td data-label="Тур">
                        <select form="formEditE_<?= $ev['id'] ?>" name="tour_id" class="t-input" required>
                            <?php foreach ($tours as $t): ?>
                                <option value="<?= $t['id'] ?>" <?= $t['id'] == $ev['tour_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Гид">
                        <select form="formEditE_<?= $ev['id'] ?>" name="guide" class="t-input" required>
                            <option value="Не назначен" <?= $ev['guide'] === 'Не назначен' ? 'selected' : '' ?>>Не назначен</option>
                            <?php foreach ($guides as $g): ?>
                                <option value="<?= htmlspecialchars($g['name']) ?>" <?= $ev['guide'] === $g['name'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Мест"><span class="seats-badge"><?= $ev['seats_count'] ?></span></td>
                    <td data-label="Доход" class="col-price" style="color: #10B981;"><?= number_format($ev['total_price'], 0, '', ' ') ?> ₽</td>
                    <td data-label="Туристы"><?= $clients_html ?: '—' ?></td>
                    <td data-label="Примечание"><input form="formEditE_<?= $ev['id'] ?>" type="text" name="notes" class="t-input" value="<?= htmlspecialchars($ev['notes'] ?? '') ?>"></td>
                    <td data-label="Действие" style="text-align: right; white-space: nowrap;">
                        <div class="action-cell">
                            <button form="formEditE_<?= $ev['id'] ?>" type="submit" class="btn-icon btn-view" title="Сохранить">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </button> 
                            <button type="button" class="btn-icon btn-del" onclick="cancelEditE(<?= $ev['id'] ?>)" title="Отмена">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                            </button>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="guide-container">
    <?php include 'navbar.php'; ?>

    <div class="guide-welcome">
        <h1>Привет, <?= htmlspecialchars($_SESSION['user_name']) ?>! 👋</h1>
        <p>Вот твои предстоящие экскурсии:</p>
    </div>

    <?php if ($show_load_past): ?>
        <div id="loadPastContainer" style="margin-bottom: 20px;">
            <p id="pastHistoryLabel" hidden style="font-size:13px; color:var(--text-muted);">Подгруженная история — по дате, отдельно от итогов основной выборки.</p>
            <button type="button" id="loadPastBtn" class="btn-load-more">⬆ Прошедшие туры</button>
        </div>
    <?php endif; ?>

    <div id="guideCardsContainer">
        <?php if (count($guide_events) === 0): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h3>Пока пусто</h3>
                <p>На ближайшие дни у тебя нет экскурсий. Отдыхай!</p>
            </div>
        <?php endif; ?>

        <?php foreach ($guide_events as $ev): 
            $p_stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id = ? AND status != 'Отмена'");
            $p_stmt->execute([$ev['id']]);
            $tourists = $p_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $months_ru = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
            $ts = strtotime($ev['tour_date']);
            $date_str = date('j', $ts) . ' ' . $months_ru[date('n', $ts)];
            if ($ev['tour_date'] === date('Y-m-d')) $date_str = '🔥 СЕГОДНЯ, ' . $date_str;
            elseif ($ev['tour_date'] === date('Y-m-d', strtotime('+1 day'))) $date_str = 'ЗАВТРА, ' . $date_str;
        ?>
        <div class="g-card">
            <div class="g-card-date">
                <?= $date_str ?> 
                <?php if (!empty($ev['time'])) echo " • " . htmlspecialchars($ev['time']); ?>
            </div>
            <h2 class="g-card-title"><?= htmlspecialchars($ev['tour_name']) ?></h2>
            
            <div class="g-card-meta">
                <?php if ($ev['duration']): ?><span>⏱ Тайминг: <?= htmlspecialchars($ev['duration']) ?></span><?php endif; ?>
                <?php if (!empty($ev['time'])): ?><span>⏰ Старт: <?= htmlspecialchars($ev['time']) ?></span><?php endif; ?>
                <?php if ($ev['coordinates']): ?><span>📍 Старт (точка): <?= htmlspecialchars($ev['coordinates']) ?></span><?php endif; ?>
            </div>

            <div class="g-tourists">
                <div class="g-tourists-title">Туристы (<?= count($tourists) ?> групп)</div>
                <?php if (empty($tourists)): ?>
                    <div style="font-size:13px; color:var(--text-muted);">Пока никого не добавили</div>
                <?php endif; ?>
                
                <?php foreach ($tourists as $t): 
                    $clean_phone = preg_replace('/[^0-9]/', '', $t['phone']);
                    if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }
                    $p_places = participantSeats($t);
                ?>
                    <div class="g-tourist-row">
                        <div class="g-tourist-info">
                            <span class="g-tourist-name"><?= htmlspecialchars($t['client_name'] ?? $t['name'] ?? '') ?></span>
                            <span class="g-tourist-seats"><?= $p_places ?> чел.</span>
                        </div>
                        <div class="g-tourist-actions">
                            <a href="tel:<?= htmlspecialchars($t['phone']) ?>" class="g-btn-icon g-btn-call" title="Позвонить">📞</a>
                            <a href="https://wa.me/<?= $clean_phone ?>" target="_blank" class="g-btn-icon g-btn-wa" title="WhatsApp">💬</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="g-card-actions">
                <a href="event.php?id=<?= $ev['id'] ?><?= htmlspecialchars($context_suffix, ENT_QUOTES) ?>" class="g-btn g-btn-route">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg> Детали тура
                </a>
                <button type="button" class="g-btn g-btn-expense" onclick="openExpenseModal(<?= $ev['id'] ?>, '<?= htmlspecialchars($ev['tour_name'], ENT_QUOTES) ?>')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg> Внести чек
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php endif; ?>

<div class="modal-overlay" id="noteModal">
    <div class="modal-content">
        <h3>Примечание</h3>
        <p id="noteModalText" style="font-size:15px; line-height:1.6; white-space:pre-wrap; color:var(--text-main); margin-bottom:20px;"></p>
        <button type="button" class="btn-cancel" style="margin-top:0;" onclick="document.getElementById('noteModal').style.display='none'">Закрыть</button>
    </div>
</div>

<div class="modal-overlay" id="expenseModal">
    <div class="modal-content">
        <h3>Внести чек / расход</h3>
        <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px; font-weight:600;" id="expenseTourName"></p>
        <form method="POST" enctype="multipart/form-data" action="<?= htmlspecialchars($home_url, ENT_QUOTES) ?>"><?= formTokenInput() ?>
            <input type="hidden" name="add_expense" value="1">
            <input type="hidden" name="event_id" id="expenseEventId">
            <div class="form-group"><label>Сумма (₽) *</label><input type="number" name="amount" min="1" class="t-input" required placeholder="Например: 1500"></div>
            <div class="form-group"><label>Категория *</label><select name="category" class="t-input" required><?php if(!empty($expense_cats)){ foreach($expense_cats as $c){ echo "<option value='".htmlspecialchars($c)."'>".htmlspecialchars($c)."</option>"; } } ?><option value="Прочее">Прочее</option></select></div>
            <div class="form-group"><label>Комментарий</label><input type="text" name="description" class="t-input" placeholder="Обед, бензин, билеты..."></div>
            <div class="form-group"><label>Фото чека</label><input type="file" name="receipt" accept="image/*" class="t-input" style="padding:10px;"></div>
            <button type="submit" class="btn-submit">Отправить в бухгалтерию</button>
            <button type="button" class="btn-cancel" onclick="document.getElementById('expenseModal').style.display='none'">Отмена</button>
        </form>
    </div>
</div>

<script>
window.homePageConfig = <?= json_encode([
    'filters' => $home_filters,
    'url' => $home_url,
    'user' => ($_SESSION['user_id'] ?? '') . ':' . $current_user_role . ':' . $current_user_name,
    'tourTimes' => isset($tours) ? array_column($tours, 'default_start_time', 'id') : [],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/homepage.js"></script>
<script src="assets/homepage-actions.js"></script>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'expense_added'): ?>
    <script> document.addEventListener('DOMContentLoaded', () => showToast('Чек отправлен в бухгалтерию!', 'success')); </script>
<?php endif; ?>

</body>
</html>
