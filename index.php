<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';
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

// --- УДАЛЕНИЕ ЭКСКУРСИИ ---
if (isset($_GET['delete_event']) && $current_user_role === 'admin') {
    $del_id = (int)$_GET['delete_event'];
    $pdo->prepare("DELETE FROM expenses WHERE event_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM participants WHERE event_id = ?")->execute([$del_id]);
    $pdo->prepare("DELETE FROM events WHERE id = ?")->execute([$del_id]);
    header("Location: index.php"); exit;
}

// --- РЕДАКТИРОВАНИЕ ЭКСКУРСИИ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event']) && $current_user_role === 'admin') {
    $e_id = (int)$_POST['event_id'];
    $tour_date = $_POST['tour_date'];
    $time = trim($_POST['time'] ?? '');
    $tour_id = (int)$_POST['tour_id'];
    $guide = $_POST['guide'];
    $notes = trim($_POST['notes'] ?? '');

    $pdo->prepare("UPDATE events SET tour_date=?, time=?, tour_id=?, guide=?, notes=? WHERE id=?")
        ->execute([$tour_date, $time, $tour_id, $guide, $notes, $e_id]);
    header("Location: index.php"); exit;
}

// --- БЫСТРОЕ ДОБАВЛЕНИЕ ЗАЯВКИ (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_event']) && $current_user_role === 'admin') {
    ini_set('display_errors', 0); error_reporting(0); while (ob_get_level()) { ob_end_clean(); } 
    try {
        $tour_date = $_POST['tour_date']; 
        $tour_id = $_POST['tour_id']; 
        $guide = $_POST['guide']; 
        $notes = trim($_POST['notes'] ?? '');

        // Отправка уведомления в Telegram
        require_once 'telegram.php';
        $tour_name = $pdo->query("SELECT name FROM tours_catalog WHERE id = " . (int)$tour_id)->fetchColumn();
        $msg = "🆕 <b>Новая заявка создана вручную!</b>\n";
        $msg .= "Тур: {$tour_name}\n";
        $msg .= "Дата: {$tour_date} в {$time}\n";
        $msg .= "Гид: {$guide}";
        sendTelegramMessage($msg);
        
        if (empty($tour_date) || empty($tour_id) || empty($guide)) { echo json_encode(['status' => 'error', 'message' => 'Заполните обязательные поля']); exit; }

        // УМНАЯ ПОДСТАНОВКА ВРЕМЕНИ
        $stmt_t = $pdo->prepare("SELECT default_start_time FROM tours_catalog WHERE id = ?");
        $stmt_t->execute([$tour_id]);
        $time = $stmt_t->fetchColumn() ?: '10:00'; // Если пусто, ставим 10:00

        $stmt = $pdo->prepare("INSERT INTO events (tour_date, time, tour_id, guide, notes) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tour_date, $time, $tour_id, $guide, $notes]);
        echo json_encode(['status' => 'success']); 
        exit; 
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); exit; }
}

// --- ДИНАМИЧЕСКАЯ ПОДГРУЗКА ПРОШЕДШИХ ТУРОВ (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_load_past'])) {
    ini_set('display_errors', 0); error_reporting(0); while (ob_get_level()) { ob_end_clean(); } 
    try {
        $offset = (int)$_POST['offset'];
        $limit = 5;
        
        $params = [];
        if ($current_user_role === 'admin') {
            // Единый подсчет мест для всех экранов
            $sql = "SELECT e.*, t.name AS tour_name,
                    COALESCE((SELECT SUM({$participant_seats_sql}) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as seats_count,
                    COALESCE((SELECT SUM(price) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as total_price,
                    (SELECT GROUP_CONCAT(CONCAT(COALESCE(client_name,''), '::', COALESCE(phone,''), '::', {$participant_seats_sql}) SEPARATOR '||') FROM participants WHERE event_id = e.id AND status != 'Отмена') as clients_data
                    FROM events e JOIN tours_catalog t ON e.tour_id = t.id 
                    WHERE e.tour_date < CURDATE() ORDER BY e.tour_date DESC LIMIT $limit OFFSET $offset";
        } else {
            $sql = "SELECT e.*, t.name AS tour_name, t.duration, t.coordinates 
                    FROM events e JOIN tours_catalog t ON e.tour_id = t.id 
                    WHERE e.guide = ? AND e.tour_date < CURDATE() ORDER BY e.tour_date DESC LIMIT $limit OFFSET $offset";
            $params[] = $_SESSION['user_name'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $past_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $past_events = array_reverse($past_events);
        
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
                
                $clients_html = '';
                if (!empty($ev['clients_data'])) {
                    $clients = explode('||', $ev['clients_data']);
                    foreach ($clients as $c) {
                        $parts = explode('::', $c);
                        if (count($parts) >= 2) {
                            $c_name = htmlspecialchars(trim($parts[0]));
                            $c_phone = urlencode(trim($parts[1]));
                            $c_seats = isset($parts[2]) ? (int)trim($parts[2]) : 1;
                            $clients_html .= "<div class='tourist-chip'><a href='client.php?phone={$c_phone}' class='client-link'>👤 {$c_name}</a> <span class='seats-count'>{$c_seats} чел.</span></div>";
                        }
                    }
                } else {
                    $clients_html = "<a href='event.php?id={$ev['id']}' class='btn-add-tourist'>+ Добавить</a>";
                }

                $note_html = !empty($ev['notes']) 
                    ? "<div class='note-truncate' data-note='".htmlspecialchars($ev['notes'], ENT_QUOTES)."' onclick=\"showNoteModal(this.getAttribute('data-note'))\">" . htmlspecialchars($ev['notes']) . "</div>"
                    : "—";

                $forms .= "<form id='formEditE_{$ev['id']}' method='POST'><input type='hidden' name='update_event' value='1'><input type='hidden' name='event_id' value='{$ev['id']}'></form>";
                
                $html .= "<tr class='view_e_{$ev['id']} past-event-row'>
                    <td data-label='Дата' style='white-space: nowrap;'><strong style='color:#64748B;'>{$date_formatted}</strong>{$time_html}</td>
                    <td data-label='Тур'><a href='event.php?id={$ev['id']}' class='link-tour' style='color:#475569;'>{$tour_name}</a></td>
                    <td data-label='Гид'><span class='guide-tag' style='{$guide_style} opacity: 0.8;'>{$guide}</span></td>
                    <td data-label='Мест'><span class='seats-badge' style='background:#F1F5F9; color:#475569;'>{$ev['seats_count']}</span></td>
                    <td data-label='Доход' class='col-price' style='color: #059669; opacity: 0.8;'>{$income}</td>
                    <td data-label='Туристы'>{$clients_html}</td>
                    <td data-label='Примечание' class='col-note'>{$note_html}</td>
                    <td data-label='Действия' style='text-align: right; white-space: nowrap;'>
                        <div class='action-cell'>
                            <a href='event.php?id={$ev['id']}' class='btn-icon btn-view' title='Открыть'>
                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z'></path><circle cx='12' cy='12' r='3'></circle></svg>
                            </a>
                            <button type='button' class='btn-icon btn-edit' onclick='toggleEditE({$ev['id']})' title='Редактировать'>
                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'></path><path d='M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z'></path></svg>
                            </button>
                            <a href='?delete_event={$ev['id']}' class='btn-icon btn-del' onclick=\"return confirm('Точно удалить экскурсию?');\" title='Удалить'>
                                <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='3 6 5 6 21 6'></polyline><path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'></path><line x1='10' y1='11' x2='10' y2='17'></line><line x1='14' y1='11' x2='14' y2='17'></line></svg>
                            </a>
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
                $html .= "<a href='event.php?id={$ev['id']}' class='g-btn g-btn-route'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'></path><polyline points='14 2 14 8 20 8'></polyline><line x1='16' y1='13' x2='8' y2='13'></line><line x1='16' y1='17' x2='8' y2='17'></line><polyline points='10 9 9 9 8 9'></polyline></svg> Детали</a>";
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
    $amount = (int)$_POST['amount'];
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
    header("Location: index.php?msg=expense_added"); exit;
}

$show_load_past = empty($_GET['date_from']) && empty($_GET['date_to']);

// --- ПОДГОТОВКА ДАННЫХ ---
if ($current_user_role === 'admin') {
    $tours = $pdo->query("SELECT * FROM tours_catalog ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $guides = $pdo->query("SELECT * FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Единый подсчет мест для всех экранов
    $sql = "SELECT e.*, t.name AS tour_name,
            COALESCE((SELECT SUM({$participant_seats_sql}) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as seats_count,
            COALESCE((SELECT SUM(price) FROM participants WHERE event_id = e.id AND status != 'Отмена'), 0) as total_price,
            COALESCE((SELECT SUM(amount) FROM expenses WHERE event_id = e.id), 0) as total_expenses,
            (SELECT GROUP_CONCAT(CONCAT(COALESCE(client_name,''), '::', COALESCE(phone,''), '::', {$participant_seats_sql}) SEPARATOR '||') FROM participants WHERE event_id = e.id AND status != 'Отмена') as clients_data
            FROM events e JOIN tours_catalog t ON e.tour_id = t.id WHERE 1=1";
    $params = [];

    if (!empty($_GET['date_from'])) { $sql .= " AND e.tour_date >= ?"; $params[] = $_GET['date_from']; } 
    else { $sql .= " AND e.tour_date >= CURDATE()"; }

    if (!empty($_GET['date_to'])) { $sql .= " AND e.tour_date <= ?"; $params[] = $_GET['date_to']; }
    if (!empty($_GET['tour_filter'])) { $sql .= " AND e.tour_id = ?"; $params[] = $_GET['tour_filter']; }
    if (!empty($_GET['guide_filter'])) { $sql .= " AND e.guide = ?"; $params[] = $_GET['guide_filter']; }

    $sort_col = $_GET['sort'] ?? 'tour_date';
    $sort_dir = isset($_GET['dir']) && $_GET['dir'] === 'desc' ? 'DESC' : 'ASC'; 
    $allowed_sorts = ['tour_date', 'tour_name', 'guide'];
    if (!in_array($sort_col, $allowed_sorts)) { $sort_col = 'tour_date'; }
    
    // Сортировка времени вместе с датой
    if ($sort_col === 'tour_date') {
        $sql .= " ORDER BY tour_date $sort_dir, time ASC";
    } else {
        $sql .= " ORDER BY $sort_col $sort_dir";
    }

    $stmt = $pdo->prepare($sql); $stmt->execute($params); $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CRM - Главная</title>
    <style>
        /* ПРЕМИУМ ДИЗАЙН (Soft UI & Glassmorphism) */
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --shadow-float: 0 10px 30px -5px rgba(0,0,0,0.08);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em;}
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box;}
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94A3B8; }

        .navbar { display: flex; gap: 15px; margin-bottom: 30px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;}
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;}
        
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 25px; box-shadow: var(--shadow-md); transition: var(--transition); border: 1px solid rgba(255,255,255,0.5); position: relative; overflow: hidden;}
        .dash-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-float); }
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px;}
        .dash-card.profit::before { background: #10B981; }
        .dash-title { font-size: 13px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;}
        .dash-val { font-size: 28px; font-weight: 800; color: var(--text-main); }
        .val-green { color: #10B981; } .val-red { color: #EF4444; }
        
        /* Быстрые фильтры (Таблетки) */
        .quick-filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .pill { background: var(--card-bg); color: var(--text-muted); padding: 8px 18px; border-radius: 99px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid var(--border); transition: var(--transition); white-space: nowrap; box-shadow: var(--shadow-sm);}
        .pill:hover { background: var(--primary-light); color: var(--primary); border-color: transparent; transform: translateY(-1px); box-shadow: var(--shadow-md);}
        
        .pill-reset { background: #F3F4F6; color: #4B5563; border-color: #D1D5DB; }
        .pill-reset:hover { background: #E5E7EB; color: #111827; border-color: #9CA3AF;}

        .filters { display: flex; gap: 12px; background: var(--card-bg); padding: 20px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); margin-bottom: 30px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 130px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;}
        
        .filter-group input, .filter-group select { padding: 11px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; outline: none; background: #F8FAFC; color: var(--text-main); font-weight: 500; transition: var(--transition); width: 100%; box-sizing: border-box; height: 40px;}
        .filter-group select { 
            cursor: pointer; font-weight: 600; 
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%2364748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
            background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px;
        }
        .filter-group input:focus, .filter-group select:focus, .filter-group select:hover { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px var(--primary-light); }
        
        .btn-filter { background: var(--primary); color: white; padding: 0 24px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); font-size: 13px; height: 40px; display: inline-flex; align-items: center; justify-content: center; margin-top: 19px;}
        .btn-filter:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}
        
        /* Идеальная SaaS Таблица с закрепленной шапкой */
        .table-responsive { width: 100%; background: var(--card-bg); border-radius: var(--radius-lg); box-shadow: var(--shadow-md); overflow-x: auto;}
        table { width: 100%; min-width: 950px; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 16px 20px; text-align: left; font-size: 14px; vertical-align: middle; border-bottom: 1px solid #F1F5F9;}
        th { position: sticky; top: 0; z-index: 10; background-color: rgba(255,255,255,0.95); backdrop-filter: blur(8px); font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; cursor:pointer; box-shadow: 0 2px 10px rgba(0,0,0,0.05), 0 1px 0 #F1F5F9; letter-spacing: 0.05em;}
        tr:hover td { background-color: #F8FAFC; }
        tr:last-child td { border-bottom: none; }
        
        .col-price { white-space: nowrap; width: 110px; font-weight: 600; font-size: 15px;}
        .col-note { width: 200px; }
        
        .note-truncate { max-width: 180px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; color: var(--text-muted); font-size: 13px; padding: 6px 8px; border-radius: 6px; transition: var(--transition); background: transparent; border: 1px dashed transparent;}
        .note-truncate:hover { background: var(--card-bg); color: var(--text-main); border-color: #CBD5E1; box-shadow: var(--shadow-sm);}

        .link-tour { color: var(--text-main); text-decoration: none; font-weight: 700; transition: var(--transition);} 
        .link-tour:hover { color: var(--primary); }
        .client-link { color: var(--text-main); text-decoration: none; font-weight: 600; transition: var(--transition); }
        .client-link:hover { color: var(--primary); text-decoration: underline; }

        .guide-tag { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; white-space: nowrap; display: inline-block;}
        .seats-badge { background: #F1F5F9; color: #475569; font-weight: 700; padding: 4px 12px; border-radius: 12px; font-size: 13px;}
        
        .tourist-chip { margin-bottom: 6px; white-space: nowrap; display: flex; align-items: center; gap: 6px;}
        .seats-count { background: #F1F5F9; color: #475569; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700;}
        .btn-add-tourist { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; background: transparent; color: #94A3B8; border: 1px dashed #CBD5E1; border-radius: var(--radius-sm); font-size: 12px; font-weight: 600; text-decoration: none; transition: var(--transition); white-space: nowrap; }
        .btn-add-tourist:hover { background: #F8FAFC; color: var(--primary); border-color: var(--primary); }

        /* Векторные Иконки (Кнопки действий) */
        .action-cell { display: flex; gap: 8px; justify-content: flex-end; align-items: center; }
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: var(--radius-sm); font-size: 14px; border: none; cursor: pointer; transition: var(--transition); background: #F8FAFC; color: #64748B;}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        
        .btn-edit { background: var(--primary-light); color: var(--primary); } 
        .btn-edit:hover { background: #E0E7FF; color: #3730A3; }
        .btn-del { background: #FEF2F2; color: #EF4444; } 
        .btn-del:hover { background: #FEE2E2; color: #DC2626; }
        .btn-view { background: #F0FDF4; color: #10B981; } 
        .btn-view:hover { background: #DCFCE7; color: #047857; }
        
        /* Состояния строк (Добавление/Редактирование/Архив) */
        .add-form-row td { background: var(--primary-light) !important; border-bottom: none; position: relative; z-index: 1;}
        .edit_form_row td { background: #FFFBEB !important; }
        .past-event-row td { background-color: #F8FAFC !important; opacity: 0.8; }
        .past-event-row:hover td { opacity: 1; }

        input.t-input, select.t-input { width: 100%; box-sizing: border-box; padding: 10px 14px; background: #FFFFFF; border: 1px solid #D1D5DB; border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; outline: none; transition: var(--transition); color: var(--text-main); font-weight: 500; min-width: 0;}
        input.t-input:focus, select.t-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }
        
        .btn-add-submit { background: var(--text-main); color: white; border: none; padding: 10px 16px; border-radius: var(--radius-sm); font-weight: 600; font-size: 13px; cursor: pointer; width: 100%; transition: var(--transition); white-space: nowrap; box-shadow: 0 4px 10px rgba(0,0,0,0.15);}
        .btn-add-submit:hover { background: #1F2937; transform: translateY(-1px); box-shadow: 0 6px 15px rgba(0,0,0,0.2);}

        .btn-load-more { background: var(--card-bg); color: var(--text-main); border: 1px dashed #CBD5E1; padding: 14px 20px; border-radius: var(--radius-lg); font-weight: 600; cursor: pointer; width: 100%; transition: var(--transition); font-family: inherit; font-size: 14px; box-shadow: var(--shadow-sm);}
        .btn-load-more:hover { background: #F8FAFC; color: var(--primary); border-color: var(--primary); }

        /* Пустое состояние (Empty State) */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state svg { width: 64px; height: 64px; color: #E2E8F0; margin-bottom: 20px; }
        .empty-state h3 { font-size: 20px; color: var(--text-main); margin: 0 0 8px 0; font-weight: 800;}
        .empty-state p { font-size: 15px; margin: 0; }

        /* Toast Уведомления */
        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%) scale(0.9); transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0) scale(1); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        /* --- СТИЛИ ДЛЯ ПУЛЬТА ГИДА (МОБИЛЬНЫЙ ВИД) --- */
        .guide-container { max-width: 600px; margin: 0 auto; background: transparent; box-shadow: none; padding: 0; }
        .guide-welcome { margin-bottom: 25px; text-align: center;}
        .guide-welcome h1 { font-size: 28px; margin: 0 0 8px 0; color: var(--text-main); }
        .guide-welcome p { margin: 0; color: var(--text-muted); font-size: 15px; }
        
        .g-card { background: var(--card-bg); border-radius: 20px; padding: 25px; margin-bottom: 20px; box-shadow: var(--shadow-md); position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.8);}
        .past-event-card { background: #F8FAFC; border: 1px dashed #CBD5E1; box-shadow: none;}
        .g-card-date { background: var(--primary-light); color: var(--primary); font-weight: 800; font-size: 13px; padding: 6px 14px; border-radius: 20px; display: inline-block; margin-bottom: 15px; letter-spacing: 0.03em;}
        .past-event-card .g-card-date { background: #E2E8F0; color: #475569;}
        .g-card-title { font-size: 22px; font-weight: 800; margin: 0 0 12px 0; color: var(--text-main); line-height: 1.3; letter-spacing: -0.02em;}
        .g-card-meta { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; display: flex; flex-direction: column; gap: 6px;}
        
        .g-tourists { background: #F8FAFC; border-radius: var(--radius-lg); padding: 18px; margin-bottom: 20px;}
        .g-tourists-title { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 12px; letter-spacing: 0.05em;}
        .g-tourist-row { display: flex; justify-content: space-between; align-items: center; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid #E2E8F0; }
        .g-tourist-row:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
        .g-tourist-info { display: flex; flex-direction: column; gap: 4px; }
        .g-tourist-name { font-weight: 700; font-size: 16px; color: var(--text-main); }
        .g-tourist-seats { font-size: 12px; color: #059669; font-weight: 700; background: #D1FAE5; padding: 2px 8px; border-radius: 6px; display: inline-block; width: max-content;}
        
        .g-tourist-actions { display: flex; gap: 10px; }
        .g-btn-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; font-size: 18px; transition: var(--transition); box-shadow: var(--shadow-sm);}
        .g-btn-call { background: #E0F2FE; color: #2563EB; } .g-btn-call:hover { background: #BFDBFE; transform: scale(1.05);}
        .g-btn-wa { background: #DCFCE7; color: #16A34A; } .g-btn-wa:hover { background: #BBF7D0; transform: scale(1.05);}
        
        .g-card-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 20px;}
        .g-btn { padding: 16px 12px; border-radius: var(--radius-md); text-align: center; text-decoration: none; font-weight: 700; font-size: 15px; border: none; cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: 8px;}
        .g-btn:active { transform: scale(0.96); } 
        .g-btn-route { background: #F1F5F9; color: var(--text-main); } 
        .g-btn-route:hover { background: #E2E8F0; }
        .g-btn-expense { background: var(--text-main); color: #FFFFFF; box-shadow: 0 4px 15px rgba(15, 23, 42, 0.2); } 
        .g-btn-expense:hover { background: #1E293B; box-shadow: 0 6px 20px rgba(15, 23, 42, 0.3); }

        /* Модальные окна */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 20px; box-sizing: border-box;}
        .modal-content { background: var(--card-bg); padding: 30px; border-radius: 24px; max-width: 420px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.2); transform: scale(0.95); animation: modalIn 0.2s forwards cubic-bezier(0.4, 0, 0.2, 1);}
        @keyframes modalIn { to { transform: scale(1); } }
        .modal-content h3 { font-size: 20px; font-weight: 800; margin-bottom: 20px;}
        
        .btn-submit { background: var(--primary); color: white; padding: 14px; border: none; border-radius: var(--radius-md); font-weight: 700; font-size: 15px; width: 100%; cursor: pointer; margin-top: 10px; transition: var(--transition);}
        .btn-submit:hover { background: var(--primary-hover); }
        .btn-cancel { background: transparent; color: var(--text-muted); padding: 14px; border: 1px solid var(--border); border-radius: var(--radius-md); font-weight: 600; font-size: 15px; width: 100%; cursor: pointer; margin-top: 10px; transition: var(--transition);}
        .btn-cancel:hover { background: #F8FAFC; color: var(--text-main); }

        /* МАГИЯ ДЛЯ АДМИНСКОЙ ТАБЛИЦЫ НА МОБИЛЬНЫХ ТЕЛЕФОНАХ (ПРЕВРАЩЕНИЕ В КАРТОЧКИ) */
        @media (max-width: 768px) { 
            body { padding: 10px; } .container { padding: 10px; } 
            .filters { flex-direction: column; align-items: stretch; padding: 15px; }
            .filter-group { width: 100%; }
            .btn-filter { width: 100%; margin-top: 5px; }

            /* Превращаем таблицу в карточки */
            .table-responsive { border-radius: 12px; background: transparent; box-shadow: none; overflow: visible;}
            .table-responsive table, .table-responsive thead, .table-responsive tbody, .table-responsive tr, .table-responsive th, .table-responsive td { display: block; width: 100%; min-width: 0; box-sizing: border-box; }
            .table-responsive thead { display: none; } /* Прячем заголовки таблицы */
            
            .table-responsive tr { 
                margin-bottom: 16px; 
                background: var(--card-bg); 
                border-radius: var(--radius-lg); 
                border: 1px solid var(--border); 
                padding: 16px; 
                box-shadow: var(--shadow-sm); 
            }
            
            .table-responsive td { 
                display: flex; 
                justify-content: space-between; 
                align-items: center; 
                padding: 10px 0; 
                border-bottom: 1px dashed #E2E8F0; 
                text-align: right; 
                gap: 8px;
                flex-wrap: wrap;
                overflow-wrap: anywhere;
            }
            .table-responsive td > * { min-width: 0; max-width: 100%; }
            .table-responsive .tourist-chip { flex-wrap: wrap; white-space: normal; }
            .table-responsive .guide-tag { white-space: normal; }
            .table-responsive td:last-child { border-bottom: none; padding-bottom: 0; }
            
            /* Выводим названия колонок слева (берется из атрибута data-label) */
            .table-responsive td::before { 
                content: attr(data-label); 
                font-size: 11px; 
                font-weight: 700; 
                text-transform: uppercase; 
                color: var(--text-muted); 
                text-align: left; 
                margin-right: 15px; 
            }
            
            .action-cell { justify-content: flex-end; flex-wrap: wrap; width: auto; gap: 10px;}
            .btn-icon { width: 44px; height: 44px; flex-shrink: 0; }
            
            /* Фиксы для инлайн-форм на мобилке */
            .add-form-row { padding: 15px !important; }
            .add-form-row td::before, .edit_form_row td::before { display: none; }
            .add-form-row td { display: block; border-bottom: none; padding: 8px 0; }
            .edit_form_row td { display: block; border-bottom: none; padding: 8px 0; text-align: left; }
            .add-form-row td:last-child { padding-bottom: 15px; border-bottom: none; }
        }
    </style>
</head>
<body>

<div id="ajaxFormsContainer"></div>

<div id="toast-container"></div>

<?php if ($current_user_role === 'admin'): ?>
<div class="container">
    <?php include 'navbar.php'; ?>
    <div class="header-box"><h2>Список экскурсий</h2></div>
    
    <div class="dash-grid">
        <div class="dash-card profit"><div class="dash-title">Чистая прибыль</div><div class="dash-val val-green"><?= number_format($dash_profit, 0, '', ' ') ?> ₽</div></div>
        <div class="dash-card"><div class="dash-title">Всего дохода</div><div class="dash-val"><?= number_format($dash_income, 0, '', ' ') ?> ₽</div></div>
        <div class="dash-card"><div class="dash-title">Всего расходов</div><div class="dash-val val-red"><?= number_format($dash_expenses, 0, '', ' ') ?> ₽</div></div>
        <div class="dash-card"><div class="dash-title">Заявок (Мест)</div><div class="dash-val"><?= $dash_tours ?> <span style="font-size: 14px; color: var(--text-muted); font-weight:600;">(<?= $dash_clients ?> чел.)</span></div></div>
    </div>

    <div class="quick-filters">
        <a href="?date_from=<?= $today ?>&date_to=<?= $today ?>" class="pill">Сегодня</a>
        <a href="?date_from=<?= $tomorrow ?>&date_to=<?= $tomorrow ?>" class="pill">Завтра</a>
        <a href="?date_from=<?= $today ?>&date_to=<?= $current_week_end ?>" class="pill">Текущая неделя</a>
        <a href="?date_from=<?= $next_week_start ?>&date_to=<?= $next_week_end ?>" class="pill">Следующая неделя</a>
        <a href="?guide_filter=Не назначен" class="pill">Без гида</a>
        <a href="index.php" class="pill pill-reset">Сбросить всё</a>
    </div>

    <form class="filters" method="GET">
        <div class="filter-group"><label>Дата от</label><input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>"></div>
        <div class="filter-group"><label>Дата до</label><input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>"></div>
        <div class="filter-group"><label>Тур</label><select name="tour_filter"><option value="">Все туры</option><?php foreach ($tours as $t): ?><option value="<?= $t['id'] ?>" <?= (($_GET['tour_filter'] ?? '') == $t['id']) ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="filter-group"><label>Гид</label><select name="guide_filter"><option value="">Все гиды</option><?php foreach ($guides as $g): ?><option value="<?= htmlspecialchars($g['name']) ?>" <?= (($_GET['guide_filter'] ?? '') === $g['name']) ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn-filter">Применить</button>
    </form>

    <?php if ($show_load_past): ?>
        <div id="loadPastContainer" style="margin-bottom: 20px;">
            <button type="button" id="loadPastBtn" class="btn-load-more">⬆ Прошедшие туры</button>
        </div>
    <?php endif; ?>

    <form id="ajaxAddEventForm" method="POST"><input type="hidden" name="ajax_add_event" value="1"></form>
    <?php foreach ($events as $ev): ?>
        <form id="formEditE_<?= $ev['id'] ?>" method="POST">
            <input type="hidden" name="update_event" value="1">
            <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
        </form>
    <?php endforeach; ?>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th class="sortable" data-sort="tour_date" data-dir="<?= $sort_col === 'tour_date' && $sort_dir === 'ASC' ? 'desc' : 'asc' ?>">Дата <?= $sort_col === 'tour_date' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?></th>
                    <th class="sortable" data-sort="tour_name" data-dir="<?= $sort_col === 'tour_name' && $sort_dir === 'ASC' ? 'desc' : 'asc' ?>">Название тура <?= $sort_col === 'tour_name' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?></th>
                    <th class="sortable" data-sort="guide" data-dir="<?= $sort_col === 'guide' && $sort_dir === 'ASC' ? 'desc' : 'asc' ?>">Гид <?= $sort_col === 'guide' ? ($sort_dir === 'ASC' ? '↑' : '↓') : '' ?></th>
                    <th>Мест</th>
                    <th class="col-price">Доход</th>
                    <th>Туристы</th>
                    <th class="col-note">Примечание</th>
                    <th style="text-align: right; width: 140px;">Действия</th>
                </tr>
            </thead>
            <tbody id="eventsTableBody">
                
                <tr class="add-form-row" id="add_event_row">
                    <td data-label="Дата" style="display:flex; gap:5px; border-bottom: none;">
                        <input form="ajaxAddEventForm" type="date" name="tour_date" class="t-input" required title="Дата экскурсии" style="flex:1;">
                        <input form="ajaxAddEventForm" type="time" name="time" id="add_time" class="t-input" title="Время старта (оставьте пустым для автоподстановки)" style="width:auto;" oninput="this.dataset.manual='1'">
                    </td>
                    <td data-label="Тур">
                        <select form="ajaxAddEventForm" name="tour_id" id="add_tour_id" class="t-input" required title="Название тура" onchange="updateDefaultTime()">
                            <option value="" disabled selected>Выберите тур...</option>
                            <?php foreach ($tours as $t): ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td data-label="Гид"><select form="ajaxAddEventForm" name="guide" class="t-input" required title="Назначить гида"><option value="" disabled selected>Гид...</option><?php foreach ($guides as $g): ?><option value="<?= htmlspecialchars($g['name']) ?>"><?= htmlspecialchars($g['name']) ?></option><?php endforeach; ?></select></td>
                    <td data-label="Примечание" colspan="3"><input form="ajaxAddEventForm" type="text" name="notes" class="t-input" placeholder="Примечание (опционально)..."></td>
                    <td data-label="Действие" colspan="2"><button form="ajaxAddEventForm" type="submit" class="btn-add-submit" id="submitAddBtn">Сохранить тур</button></td>
                </tr>
                
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
                    <td data-label="Тур"><a href="event.php?id=<?= $ev['id'] ?>" class="link-tour"><?= htmlspecialchars($ev['tour_name']) ?></a></td>
                    <td data-label="Гид"><span class="guide-tag" style="<?= getGuideColorStyle($ev['guide']) ?>"><?= htmlspecialchars($ev['guide'] ?: 'Не назначен') ?></span></td>
                    <td data-label="Мест"><span class="seats-badge"><?= $ev['seats_count'] ?></span></td>
                    <td data-label="Доход" class="col-price" style="color: #10B981;"><?= number_format($ev['total_price'], 0, '', ' ') ?> ₽</td>
                    <td data-label="Туристы">
                        <?php 
                        $clients_html = '';
                        if (!empty($ev['clients_data'])) {
                            $clients = explode('||', $ev['clients_data']);
                            foreach ($clients as $c) {
                                $parts = explode('::', $c);
                                if (count($parts) >= 2) {
                                    $c_name = htmlspecialchars(trim($parts[0]));
                                    $c_phone = urlencode(trim($parts[1]));
                                    $c_seats = isset($parts[2]) ? (int)trim($parts[2]) : 1;
                                    $clients_html .= "<div class='tourist-chip'><a href='client.php?phone={$c_phone}' class='client-link'>👤 {$c_name}</a> <span class='seats-count'>{$c_seats} чел.</span></div>";
                                }
                            }
                            echo $clients_html;
                        } else {
                            echo "<a href='event.php?id={$ev['id']}' class='btn-add-tourist'>+ Добавить</a>";
                        }
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
                            <a href="event.php?id=<?= $ev['id'] ?>" class="btn-icon btn-view" title="Открыть">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </a>
                            <button type="button" class="btn-icon btn-edit" onclick="toggleEditE(<?= $ev['id'] ?>)" title="Редактировать">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                            <a href="?delete_event=<?= $ev['id'] ?>" class="btn-icon btn-del" onclick="return confirm('Точно удалить экскурсию?');" title="Удалить">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                            </a>
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
                <a href="event.php?id=<?= $ev['id'] ?>" class="g-btn g-btn-route">
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
        <form method="POST" enctype="multipart/form-data">
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
    // Подстановка дефолтного времени
    const tourTimes = {
        <?php if(isset($tours)) { foreach($tours as $t) echo $t['id'] . ": '" . addslashes($t['default_start_time'] ?? '') . "',\n"; } ?>
    };

    function updateDefaultTime() {
        const tId = document.getElementById('add_tour_id').value;
        const timeInp = document.getElementById('add_time');
        if (tourTimes[tId] && !timeInp.dataset.manual) {
            timeInp.value = tourTimes[tId];
        }
    }

    // Показ Toast уведомления
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icon = type === 'success' 
            ? `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`
            : `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;
            
        toast.innerHTML = icon + `<span>${message}</span>`;
        container.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 3000);
    }

    // Закрытие модалок по фону
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('mousedown', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });

    function showNoteModal(text) {
        document.getElementById('noteModalText').textContent = text;
        document.getElementById('noteModal').style.display = 'flex';
    }

    function toggleEditE(id) {
        document.querySelectorAll('.view_e_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.edit_e_' + id).forEach(el => el.style.display = '');
    }
    function cancelEditE(id) {
        document.querySelectorAll('.edit_e_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.view_e_' + id).forEach(el => el.style.display = '');
    }

    function openExpenseModal(eventId, tourName) {
        document.getElementById('expenseEventId').value = eventId;
        document.getElementById('expenseTourName').textContent = tourName;
        document.getElementById('expenseModal').style.display = 'flex';
    }

    document.addEventListener('DOMContentLoaded', () => {
        
        const savedToast = sessionStorage.getItem('toast_msg');
        if (savedToast) {
            showToast(savedToast, sessionStorage.getItem('toast_type') || 'success');
            sessionStorage.removeItem('toast_msg');
            sessionStorage.removeItem('toast_type');
        }

        const addEventForm = document.getElementById('ajaxAddEventForm');
        if (addEventForm) {
            addEventForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = document.getElementById('submitAddBtn');
                submitBtn.innerHTML = '⏳ Сохранение...'; submitBtn.disabled = true;

                fetch('index.php', { method: 'POST', body: new FormData(this), headers: { 'X-Requested-With': 'XMLHttpRequest' }})
                .then(res => res.json())
                .then(data => {
                    if(data.status === 'success') {
                        sessionStorage.setItem('toast_msg', 'Экскурсия успешно добавлена!');
                        sessionStorage.setItem('toast_type', 'success');
                        window.location.reload(); 
                    } else {
                        showToast(data.message || "Ошибка сохранения", "error");
                        submitBtn.innerHTML = 'Сохранить тур'; submitBtn.disabled = false;
                    }
                })
                .catch(err => { 
                    showToast("Ошибка соединения с сервером", "error"); 
                    submitBtn.innerHTML = 'Сохранить тур'; submitBtn.disabled = false; 
                });
            });
        }

        let pastOffset = 0;
        const loadPastBtn = document.getElementById('loadPastBtn');
        if (loadPastBtn) {
            loadPastBtn.addEventListener('click', function() {
                const btn = this; const originalText = btn.innerHTML;
                btn.innerHTML = '⏳ Загрузка...'; btn.disabled = true;

                const formData = new FormData();
                formData.append('ajax_load_past', '1');
                formData.append('offset', pastOffset);

                fetch('index.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' }})
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.count > 0) {
                            const addRow = document.getElementById('add_event_row');
                            if (addRow) addRow.insertAdjacentHTML('afterend', data.html);
                            
                            const gContainer = document.getElementById('guideCardsContainer');
                            if (gContainer) gContainer.insertAdjacentHTML('afterbegin', data.html);
                            
                            const fContainer = document.getElementById('ajaxFormsContainer');
                            if (fContainer && data.forms) fContainer.insertAdjacentHTML('beforeend', data.forms);

                            pastOffset += 5;
                            showToast('Туры успешно подгружены', 'success');
                        }
                        if (data.count < 5) btn.parentElement.style.display = 'none';
                    }
                })
                .finally(() => { btn.innerHTML = originalText; btn.disabled = false; });
            });
        }
    });
</script>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'expense_added'): ?>
    <script> document.addEventListener('DOMContentLoaded', () => showToast('Чек отправлен в бухгалтерию!', 'success')); </script>
<?php endif; ?>

</body>
</html>
