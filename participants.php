<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';

// --- РЕДАКТИРОВАНИЕ УЧАСТНИКА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_participant'])) {
    $p_id = (int)$_POST['participant_id'];
    $client_name = trim($_POST['client_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $seats = (int)($_POST['seats'] ?? 1);
    $price = (int)($_POST['price'] ?? 0);
    $source = trim($_POST['source'] ?? 'CRM');
    $status = trim($_POST['status'] ?? 'Бронь');
    $notes = trim($_POST['notes'] ?? '');

    $pdo->prepare("UPDATE participants SET client_name=?, phone=?, email=?, seats=?, price=?, source=?, status=?, notes=? WHERE id=?")
        ->execute([$client_name, $phone, $email, $seats, $price, $source, $status, $notes, $p_id]);
    
    // Редирект с сохранением GET-параметров поиска
    $qs = preg_replace('/&?msg=[^&]*/', '', $_SERVER['QUERY_STRING']);
    header("Location: participants.php?" . $qs . ($qs ? '&' : '') . "msg=updated"); exit;
}

// --- УДАЛЕНИЕ УЧАСТНИКА ---
if (isset($_GET['del_participant'])) {
    $pdo->prepare("DELETE FROM participants WHERE id = ?")->execute([(int)$_GET['del_participant']]);
    $qs = preg_replace('/&?del_participant=[^&]*/', '', $_SERVER['QUERY_STRING']);
    $qs = preg_replace('/&?msg=[^&]*/', '', $qs);
    header("Location: participants.php?" . $qs . ($qs ? '&' : '') . "msg=deleted"); exit;
}

// --- ДИНАМИЧЕСКАЯ ПОДГРУЗКА ПРОШЕДШИХ ТУРИСТОВ (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_load_past_participants'])) {
    ini_set('display_errors', 0); error_reporting(0); while (ob_get_level()) { ob_end_clean(); } 
    try {
        $offset = (int)$_POST['offset'];
        $limit = 20; 
        
        $search = trim($_POST['search'] ?? '');
        $status_filter = trim($_POST['status'] ?? '');
        $source_filter = trim($_POST['source'] ?? '');

        $params = [];
        $sql = "SELECT p.*, e.tour_date, t.name AS tour_name, e.id AS event_id 
                FROM participants p 
                JOIN events e ON p.event_id = e.id 
                JOIN tours_catalog t ON e.tour_id = t.id 
                WHERE e.tour_date < CURDATE()";

        if ($current_user_role !== 'admin') {
            $sql .= " AND e.guide = ?";
            $params[] = $_SESSION['user_name'];
        }

        if ($search !== '') {
            $sql .= " AND (p.client_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR t.name LIKE ?)";
            array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
        }
        if ($status_filter !== '') { $sql .= " AND p.status = ?"; $params[] = $status_filter; }
        if ($source_filter !== '') { $sql .= " AND p.source = ?"; $params[] = $source_filter; }

        $sql .= " ORDER BY e.tour_date DESC, p.id DESC LIMIT $limit OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $past_participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $past_participants = array_reverse($past_participants);
        
        $html = '';
        $forms = '';

        foreach ($past_participants as $p) {
            $p_id = $p['id'];
            $clean_phone = preg_replace('/[^0-9]/', '', $p['phone'] ?? '');
            if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }
            $date_str = date('d.m.Y', strtotime($p['tour_date']));
            $price_str = number_format($p['price'] ?? 0, 0, '', ' ') . ' ₽';
            $opacity = ($p['status'] ?? '') === 'Отмена' ? '0.5' : '1';
            
            $note_html = !empty($p['notes']) ? "<div class='note-truncate' data-note='".htmlspecialchars($p['notes'], ENT_QUOTES)."' onclick=\"showNoteModal(this.getAttribute('data-note'))\">" . htmlspecialchars($p['notes']) . "</div>" : "—";
            $email_html = !empty($p['email']) ? "<span style='color:var(--text-muted); font-size:12px; font-weight:500;'>".htmlspecialchars($p['email'])."</span>" : "";

            $forms .= "<form id='formEditP_{$p_id}' method='POST'><input type='hidden' name='update_participant' value='1'><input type='hidden' name='participant_id' value='{$p_id}'></form>";
            
            $html .= "
            <tr class='view_p_{$p_id} past-event-row' style='opacity: {$opacity};'>
                <td>
                    <strong style='color:#64748B;'>{$date_str}</strong><br>
                    <a href='event.php?id={$p['event_id']}' class='link-tour' style='color:#475569;' title='Перейти в карточку тура'>".htmlspecialchars($p['tour_name'] ?? '')."</a>
                </td>
                <td><a href='client.php?phone=".urlencode($p['phone'] ?? '')."' class='client-link'>".htmlspecialchars($p['client_name'] ?? '')."</a></td>
                <td>
                    <div class='contact-col'>
                        <span style='font-weight:600; color:var(--text-main);'>".htmlspecialchars($p['phone'] ?? '')."</span>
                        {$email_html}
                    </div>
                </td>
                <td><span class='seats-badge' style='background:#E2E8F0; color:#475569;'>".htmlspecialchars($p['seats'] ?? '0')."</span></td>
                <td class='col-price' style='color: #059669; opacity:0.8;'>{$price_str}</td>
                <td style='color: var(--text-muted); font-size: 13px; font-weight:600;'>".htmlspecialchars($p['source'] ?? '')."</td>
                <td><span class='status-badge status-".md5($p['status'] ?? '')."'>".htmlspecialchars($p['status'] ?? '')."</span></td>
                <td class='col-note'>{$note_html}</td>
                <td style='text-align: right; white-space: nowrap;'>
                    <div class='action-cell'>
                        <a href='https://wa.me/{$clean_phone}' target='_blank' class='btn-icon btn-wa' title='WhatsApp'>
                            <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.3 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z'></path></svg>
                        </a>
                        <button type='button' class='btn-icon btn-edit' onclick='toggleEditP({$p_id})' title='Редактировать'>
                            <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'></path><path d='M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z'></path></svg>
                        </button>
                        <a href='?del_participant={$p_id}&" . $_SERVER['QUERY_STRING'] . "' class='btn-icon btn-del' onclick=\"return confirm('Точно удалить туриста из базы?');\" title='Удалить'>
                            <svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='3 6 5 6 21 6'></polyline><path d='M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2'></path><line x1='10' y1='11' x2='10' y2='17'></line><line x1='14' y1='11' x2='14' y2='17'></line></svg>
                        </a>
                    </div>
                </td>
            </tr>";

            // Форма редактирования для подгруженных
            $sel_dir = ($p['source'] ?? '') === 'Прямые' ? 'selected' : '';
            $sel_trp = ($p['source'] ?? '') === 'Трипстер' ? 'selected' : '';
            $sel_sp = ($p['source'] ?? '') === 'Спутник 8' ? 'selected' : '';
            $sel_crm = ($p['source'] ?? '') === 'CRM' ? 'selected' : '';
            $sel_site = ($p['source'] ?? '') === 'Сайт' ? 'selected' : '';

            $st_br = ($p['status'] ?? '') === 'Бронь' ? 'selected' : '';
            $st_pr = ($p['status'] ?? '') === 'Предоплата' ? 'selected' : '';
            $st_op = ($p['status'] ?? '') === 'Оплачено' ? 'selected' : '';
            $st_ot = ($p['status'] ?? '') === 'Отмена' ? 'selected' : '';

            $html .= "
            <tr class='edit_form_row edit_p_{$p_id}' style='display: none;'>
                <td><strong style='color:var(--text-muted);'>{$date_str}</strong><br><span style='font-size:12px; color:var(--text-muted);'>".htmlspecialchars($p['tour_name'] ?? '')."</span></td>
                <td><input form='formEditP_{$p_id}' type='text' name='client_name' class='t-input' value='".htmlspecialchars($p['client_name'] ?? '')."' required></td>
                <td class='contact-col'>
                    <input form='formEditP_{$p_id}' type='text' name='phone' class='t-input' value='".htmlspecialchars($p['phone'] ?? '')."' required>
                    <input form='formEditP_{$p_id}' type='email' name='email' class='t-input' value='".htmlspecialchars($p['email'] ?? '')."' placeholder='E-mail'>
                </td>
                <td><input form='formEditP_{$p_id}' type='number' name='seats' class='t-input' value='".htmlspecialchars($p['seats'] ?? '1')."' min='1' required style='min-width: 50px;'></td>
                <td><input form='formEditP_{$p_id}' type='number' name='price' class='t-input' value='".htmlspecialchars($p['price'] ?? '0')."' style='min-width: 70px;'></td>
                <td>
                    <select form='formEditP_{$p_id}' name='source' class='t-input'>
                        <option value='Прямые' {$sel_dir}>Прямые</option><option value='Трипстер' {$sel_trp}>Трипстер</option><option value='Спутник 8' {$sel_sp}>Спутник 8</option><option value='CRM' {$sel_crm}>CRM</option><option value='Сайт' {$sel_site}>Сайт</option>
                    </select>
                </td>
                <td>
                    <select form='formEditP_{$p_id}' name='status' class='t-input'>
                        <option value='Бронь' {$st_br}>Бронь</option><option value='Предоплата' {$st_pr}>Предоплата</option><option value='Оплачено' {$st_op}>Оплачено</option><option value='Отмена' {$st_ot}>Отмена</option>
                    </select>
                </td>
                <td><input form='formEditP_{$p_id}' type='text' name='notes' class='t-input' value='".htmlspecialchars($p['notes'] ?? '')."'></td>
                <td style='text-align: right; white-space: nowrap;'>
                    <div class='action-cell'>
                        <button form='formEditP_{$p_id}' type='submit' class='btn-icon btn-view' title='Сохранить'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='20 6 9 17 4 12'></polyline></svg></button>
                        <button type='button' class='btn-icon btn-del' onclick='cancelEditP({$p_id})' title='Отмена'><svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><line x1='18' y1='6' x2='6' y2='18'></line><line x1='6' y1='6' x2='18' y2='18'></line></svg></button>
                    </div>
                </td>
            </tr>";
        }
        
        echo json_encode(['status' => 'success', 'html' => $html, 'forms' => $forms, 'count' => count($past_participants)]);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

// --- ПОИСК И ФИЛЬТРЫ ---
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$source_filter = trim($_GET['source'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$params = [];
$sql = "SELECT p.*, e.tour_date, t.name AS tour_name, e.id AS event_id 
        FROM participants p 
        JOIN events e ON p.event_id = e.id 
        JOIN tours_catalog t ON e.tour_id = t.id 
        WHERE 1=1";

if ($current_user_role !== 'admin') {
    $sql .= " AND e.guide = ?";
    $params[] = $_SESSION['user_name'];
}

// Фильтр по датам. Если не задан - показываем только актуальные!
if (!empty($date_from)) { $sql .= " AND e.tour_date >= ?"; $params[] = $date_from; } 
else { $sql .= " AND e.tour_date >= CURDATE()"; }

if (!empty($date_to)) { $sql .= " AND e.tour_date <= ?"; $params[] = $date_to; }

if ($search !== '') {
    $sql .= " AND (p.client_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR t.name LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($status_filter !== '') {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter;
}
if ($source_filter !== '') {
    $sql .= " AND p.source = ?";
    $params[] = $source_filter;
}

// Сортировка от ближайших к дальним
$sql .= " ORDER BY e.tour_date ASC, p.id DESC LIMIT 500"; 

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Показывать ли кнопку "Подгрузить прошлые"? (Только если нет жесткого фильтра дат)
$show_load_past = empty($date_from) && empty($date_to);

// Подсчет статистики для Дашбордов по результатам
$total_found = count($participants);
$total_money = 0;
$total_seats = 0;
foreach ($participants as $p) {
    if (($p['status'] ?? '') !== 'Отмена') {
        $total_money += (int)($p['price'] ?? 0);
        $total_seats += (int)($p['seats'] ?? 0);
    }
}

// Даты для кнопок "Таблеток"
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
    <title>База туристов — CRM</title>
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
        
        /* Скроллбар */
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
        
        /* Дашборды */
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 22px; box-shadow: var(--shadow-md); transition: var(--transition); position: relative; overflow: hidden;}
        .dash-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-float); }
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; background: var(--border);}
        .dash-card.profit::before { background: #10B981; }
        .dash-card.blue::before { background: var(--primary); }
        .dash-title { font-size: 12px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;}
        .dash-val { font-size: 26px; font-weight: 800; color: var(--text-main); }
        .val-green { color: #10B981; } 

        /* Быстрые фильтры (Таблетки) */
        .quick-filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .pill { background: var(--card-bg); color: var(--text-muted); padding: 8px 18px; border-radius: 99px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid var(--border); transition: var(--transition); white-space: nowrap; box-shadow: var(--shadow-sm);}
        .pill:hover { background: var(--primary-light); color: var(--primary); border-color: transparent; transform: translateY(-1px); box-shadow: var(--shadow-md);}
        
        /* Единый стиль сброса для главной и базы туристов */
        .pill-reset { background: #F3F4F6; color: #4B5563; border-color: #D1D5DB; }
        .pill-reset:hover { background: #E5E7EB; color: #111827; border-color: #9CA3AF;}

        /* Поисковая панель */
        .search-box { display: flex; gap: 12px; background: var(--card-bg); padding: 20px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); margin-bottom: 30px; flex-wrap: wrap; align-items: flex-end; }
        
        .filter-group { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 130px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;}
        
        .search-group { flex: 2; min-width: 280px; }
        .search-input-wrapper { position: relative; width: 100%; display: flex; align-items: center; }
        .search-input-wrapper svg { position: absolute; left: 14px; color: var(--text-muted); width: 16px; height: 16px; pointer-events: none;}
        
        /* Единый стиль для всех инпутов фильтра */
        .filter-group input, .filter-group select, .search-input-wrapper input { 
            padding: 11px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); 
            font-size: 13px; outline: none; background: #F8FAFC; color: var(--text-main); 
            font-weight: 500; transition: var(--transition); width: 100%; box-sizing: border-box; height: 40px;
        }
        .filter-group select { cursor: pointer; font-weight: 600; }
        .search-input-wrapper input { padding-left: 40px; cursor: text; }
        .filter-group input:focus, .filter-group select:focus, .filter-group select:hover, .search-input-wrapper input:focus { 
            border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px var(--primary-light);
        }
        
        .btn-filter { background: var(--primary); color: white; padding: 0 24px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); font-size: 13px; height: 40px; display: inline-flex; align-items: center; justify-content: center;}
        .btn-filter:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}

        .btn-load-more { background: var(--card-bg); color: var(--text-main); border: 1px dashed #CBD5E1; padding: 14px 20px; border-radius: var(--radius-lg); font-weight: 600; cursor: pointer; width: 100%; transition: var(--transition); font-family: inherit; font-size: 14px; box-shadow: var(--shadow-sm);}
        .btn-load-more:hover { background: #F8FAFC; color: var(--primary); border-color: var(--primary); }

        /* Идеальная SaaS Таблица */
        .table-responsive { width: 100%; overflow-x: auto; max-height: 65vh; overflow-y: auto; background: var(--card-bg); border-radius: var(--radius-lg); box-shadow: var(--shadow-md);}
        table { width: 100%; min-width: 1050px; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 16px 20px; text-align: left; font-size: 14px; vertical-align: middle; border-bottom: 1px solid #F1F5F9;}
        th { position: sticky; top: 0; z-index: 10; background-color: rgba(255,255,255,0.95); backdrop-filter: blur(8px); font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; box-shadow: 0 1px 0 #F1F5F9; letter-spacing: 0.05em;}
        tr:hover td { background-color: #F8FAFC; }
        tr:last-child td { border-bottom: none; }
        
        .col-price { white-space: nowrap; width: 100px; font-weight: 700; font-size: 15px; color: #10B981;}
        .col-note { width: 180px; }
        
        .note-truncate { max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; color: var(--text-muted); font-size: 13px; padding: 6px 8px; border-radius: 6px; transition: var(--transition); border: 1px dashed transparent;}
        .note-truncate:hover { background: var(--card-bg); color: var(--text-main); border-color: #CBD5E1; box-shadow: var(--shadow-sm);}

        .link-tour { color: var(--text-muted); text-decoration: none; font-weight: 600; font-size: 13px; transition: var(--transition); display: block; margin-top: 4px;} 
        .link-tour:hover { color: var(--primary); text-decoration: underline; }
        .client-link { color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 15px; transition: var(--transition); display: block;}
        .client-link:hover { color: var(--primary); }

        .seats-badge { background: #F1F5F9; color: #475569; font-weight: 700; padding: 4px 12px; border-radius: 12px; font-size: 13px;}
        .contact-col { display: flex; flex-direction: column; gap: 4px; }
        
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 99px; font-size: 12px; font-weight: 700; background: #F1F5F9; color: var(--text-muted); }
        .status-<?php echo md5('Бронь'); ?> { background: #FEF3C7; color: #B45309; }
        .status-<?php echo md5('Предоплата'); ?> { background: #DBEAFE; color: #1D4ED8; }
        .status-<?php echo md5('Оплачено'); ?> { background: #D1FAE5; color: #047857; }
        .status-<?php echo md5('Отмена'); ?> { background: #FEE2E2; color: #B91C1C; text-decoration: line-through; }

        /* Кнопки действий */
        .action-cell { display: flex; gap: 8px; justify-content: flex-end; align-items: center; }
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: var(--radius-sm); font-size: 14px; border: none; cursor: pointer; transition: var(--transition); background: #F8FAFC; color: #64748B;}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        
        .btn-edit { background: var(--primary-light); color: var(--primary); } .btn-edit:hover { background: #E0E7FF; color: #3730A3; }
        .btn-del { background: #FEF2F2; color: #EF4444; } .btn-del:hover { background: #FEE2E2; color: #DC2626; }
        .btn-wa { background: #DCFCE7; color: #16A34A; } .btn-wa:hover { background: #BBF7D0; color: #15803D; }
        .btn-view { background: #F0FDF4; color: #10B981; } .btn-view:hover { background: #DCFCE7; color: #047857; }

        /* Строки редактирования (Исправление горизонтального скролла) */
        .edit_form_row td { background: #FFFBEB !important; }
        .past-event-row td { background-color: #F8FAFC !important; opacity: 0.85; }
        .past-event-row:hover td { opacity: 1; }

        /* min-width: 0; позволяет инпутам не распирать таблицу! */
        input.t-input, select.t-input { 
            width: 100%; box-sizing: border-box; padding: 8px 10px; 
            background: #FFFFFF; border: 1px solid #D1D5DB; border-radius: var(--radius-sm); 
            font-size: 13px; font-family: inherit; outline: none; transition: var(--transition); 
            color: var(--text-main); font-weight: 500; min-width: 0;
        }
        input.t-input:focus, select.t-input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }

        /* Toast Уведомления */
        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%) scale(0.9); transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0) scale(1); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        /* Модалка */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 20px; box-sizing: border-box;}
        .modal-content { background: var(--card-bg); padding: 30px; border-radius: 24px; max-width: 420px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.2); transform: scale(0.95); animation: modalIn 0.2s forwards cubic-bezier(0.4, 0, 0.2, 1);}
        @keyframes modalIn { to { transform: scale(1); } }
        .modal-content h3 { font-size: 20px; font-weight: 800; margin-bottom: 20px;}
        .btn-cancel { background: transparent; color: var(--text-muted); padding: 14px; border: 1px solid var(--border); border-radius: var(--radius-md); font-weight: 600; font-size: 15px; width: 100%; cursor: pointer; margin-top: 10px; transition: var(--transition);}
        .btn-cancel:hover { background: #F8FAFC; color: var(--text-main); }

        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state svg { width: 64px; height: 64px; color: #E2E8F0; margin-bottom: 20px; }
        .empty-state h3 { font-size: 20px; color: var(--text-main); margin: 0 0 8px 0; font-weight: 800;}
        .empty-state p { font-size: 15px; margin: 0; }

        @media (max-width: 768px) {
            body { padding: 10px; } .container { padding: 10px; }
            .search-box { flex-direction: column; align-items: stretch; padding: 15px; }
            .filter-group, .search-group { width: 100%; }
            .btn-filter { width: 100%; justify-content: center; margin-top: 5px; }
            .table-responsive { border-radius: 12px; }
        }
    </style>
</head>
<body>

<div id="ajaxFormsContainer"></div>
<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>База туристов (CRM)</h2>
    </div>

    <div class="dash-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
        <div class="dash-card blue">
            <div class="dash-title">Найдено туристов</div>
            <div class="dash-val"><?= $total_found ?> чел. <span style="font-size: 14px; color: var(--text-muted); font-weight: 600;">(<?= $total_seats ?> мест)</span></div>
        </div>
        <div class="dash-card profit">
            <div class="dash-title">Сумма чеков (без отмен)</div>
            <div class="dash-val val-green"><?= number_format($total_money, 0, '', ' ') ?> ₽</div>
        </div>
    </div>

    <div class="quick-filters">
        <a href="?date_from=<?= $today ?>&date_to=<?= $today ?>" class="pill">Сегодня</a>
        <a href="?date_from=<?= $tomorrow ?>&date_to=<?= $tomorrow ?>" class="pill">Завтра</a>
        <a href="?date_from=<?= $today ?>&date_to=<?= $current_week_end ?>" class="pill">Текущая неделя</a>
        <a href="?date_from=<?= $next_week_start ?>&date_to=<?= $next_week_end ?>" class="pill">Следующая неделя</a>
        <a href="participants.php" class="pill pill-reset">Сбросить всё</a>
    </div>

    <form class="search-box" method="GET">
        <div class="filter-group search-group">
            <label>Поиск по туристам</label>
            <div class="search-input-wrapper">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="search" placeholder="Имя, телефон, email или тур..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        
        <div class="filter-group"><label>От даты</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
        <div class="filter-group"><label>До даты</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>

        <div class="filter-group">
            <label>Статус</label>
            <select name="status">
                <option value="">Все статусы</option>
                <option value="Бронь" <?= $status_filter === 'Бронь' ? 'selected' : '' ?>>Бронь</option>
                <option value="Предоплата" <?= $status_filter === 'Предоплата' ? 'selected' : '' ?>>Предоплата</option>
                <option value="Оплачено" <?= $status_filter === 'Оплачено' ? 'selected' : '' ?>>Оплачено</option>
                <option value="Отмена" <?= $status_filter === 'Отмена' ? 'selected' : '' ?>>Отмена</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Источник</label>
            <select name="source">
                <option value="">Все источники</option>
                <option value="Прямые" <?= $source_filter === 'Прямые' ? 'selected' : '' ?>>Прямые</option>
                <option value="Трипстер" <?= $source_filter === 'Трипстер' ? 'selected' : '' ?>>Трипстер</option>
                <option value="Спутник 8" <?= $source_filter === 'Спутник 8' ? 'selected' : '' ?>>Спутник 8</option>
                <option value="CRM" <?= $source_filter === 'CRM' ? 'selected' : '' ?>>CRM</option>
                <option value="Сайт" <?= $source_filter === 'Сайт' ? 'selected' : '' ?>>Сайт</option>
            </select>
        </div>

        <button type="submit" class="btn-filter">Найти</button>
    </form>

    <?php if ($show_load_past): ?>
        <div id="loadPastContainer" style="margin-bottom: 20px;">
            <button type="button" id="loadPastBtn" class="btn-load-more">⬆ Подгрузить прошедших туристов (Архив)</button>
        </div>
    <?php endif; ?>

    <?php foreach ($participants as $p): ?>
        <form id="formEditP_<?= $p['id'] ?>" method="POST">
            <input type="hidden" name="update_participant" value="1">
            <input type="hidden" name="participant_id" value="<?= $p['id'] ?>">
        </form>
    <?php endforeach; ?>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="min-width: 140px;">Данные тура</th>
                        <th>ФИО Туриста</th>
                        <th style="min-width: 160px;">Контакты</th>
                        <th style="width: 70px;">Мест</th>
                        <th>Сумма</th>
                        <th>Источник</th>
                        <th>Статус</th>
                        <th>Примечание</th>
                        <th style="text-align: right; width: 140px;">Действия</th>
                    </tr>
                </thead>
                <tbody id="participantsTableBody">
                    <?php if (count($participants) === 0): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <h3>Ничего не найдено</h3>
                                <p>По вашему запросу туристов не обнаружено.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($participants as $p): 
                        $p_id = $p['id'];
                        $clean_phone = preg_replace('/[^0-9]/', '', $p['phone'] ?? '');
                        if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }
                        $date_str = date('d.m.Y', strtotime($p['tour_date']));
                    ?>
                    
                    <tr class="view_p_<?= $p_id ?>" style="<?= ($p['status'] ?? '') === 'Отмена' ? 'opacity: 0.5;' : '' ?>">
                        <td>
                            <strong style="color:var(--text-main);"><?= $date_str ?></strong><br>
                            <a href="event.php?id=<?= $p['event_id'] ?>" class="link-tour" title="Перейти в карточку тура"><?= htmlspecialchars($p['tour_name'] ?? '') ?></a>
                        </td>
                        <td><a href="client.php?phone=<?= urlencode($p['phone'] ?? '') ?>" class="client-link" title="История клиента"><?= htmlspecialchars($p['client_name'] ?? '') ?></a></td>
                        <td>
                            <div class="contact-col">
                                <span style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($p['phone'] ?? '') ?></span>
                                <?php if (!empty($p['email'])): ?><span style="color:var(--text-muted); font-size:12px; font-weight:500;"><?= htmlspecialchars($p['email'] ?? '') ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td><span class="seats-badge"><?= htmlspecialchars($p['seats'] ?? '0') ?></span></td>
                        <td class="col-price"><?= number_format($p['price'] ?? 0, 0, '', ' ') ?> ₽</td>
                        <td style="color: var(--text-muted); font-size: 13px; font-weight:600;"><?= htmlspecialchars($p['source'] ?? '') ?></td>
                        <td><span class="status-badge status-<?= md5($p['status'] ?? '') ?>"><?= htmlspecialchars($p['status'] ?? '') ?></span></td>
                        <td class="col-note">
                            <?php if (!empty($p['notes'])): ?>
                                <div class="note-truncate" data-note="<?= htmlspecialchars($p['notes'], ENT_QUOTES) ?>" onclick="showNoteModal(this.getAttribute('data-note'))">
                                    <?= htmlspecialchars($p['notes']) ?>
                                </div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div class="action-cell">
                                <a href="https://wa.me/<?= $clean_phone ?>" target="_blank" class="btn-icon btn-wa" title="WhatsApp">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.3 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                                </a>
                                <button type="button" class="btn-icon btn-edit" onclick="toggleEditP(<?= $p_id ?>)" title="Редактировать">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </button>
                                <a href="?del_participant=<?= $p_id ?>&<?= $_SERVER['QUERY_STRING'] ?>" class="btn-icon btn-del" onclick="return confirm('Точно удалить туриста из базы?');" title="Удалить">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                </a>
                            </div>
                        </td>
                    </tr>

                    <tr class="edit_form_row edit_p_<?= $p_id ?>" style="display: none;">
                        <td>
                            <strong style="color:var(--text-muted);"><?= $date_str ?></strong><br>
                            <span style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($p['tour_name'] ?? '') ?></span>
                        </td>
                        <td><input form="formEditP_<?= $p_id ?>" type="text" name="client_name" class="t-input" value="<?= htmlspecialchars($p['client_name'] ?? '') ?>" required></td>
                        <td class="contact-col">
                            <input form="formEditP_<?= $p_id ?>" type="text" name="phone" class="t-input" value="<?= htmlspecialchars($p['phone'] ?? '') ?>" required>
                            <input form="formEditP_<?= $p_id ?>" type="email" name="email" class="t-input" value="<?= htmlspecialchars($p['email'] ?? '') ?>" placeholder="E-mail">
                        </td>
                        <td><input form="formEditP_<?= $p_id ?>" type="number" name="seats" class="t-input" value="<?= htmlspecialchars($p['seats'] ?? '1') ?>" min="1" required style="min-width: 50px;"></td>
                        <td><input form="formEditP_<?= $p_id ?>" type="number" name="price" class="t-input" value="<?= htmlspecialchars($p['price'] ?? '0') ?>" style="min-width: 70px;"></td>
                        <td>
                            <select form="formEditP_<?= $p_id ?>" name="source" class="t-input">
                                <option value="Прямые" <?= ($p['source'] ?? '') === 'Прямые' ? 'selected' : '' ?>>Прямые</option>
                                <option value="Трипстер" <?= ($p['source'] ?? '') === 'Трипстер' ? 'selected' : '' ?>>Трипстер</option>
                                <option value="Спутник 8" <?= ($p['source'] ?? '') === 'Спутник 8' ? 'selected' : '' ?>>Спутник 8</option>
                                <option value="CRM" <?= ($p['source'] ?? '') === 'CRM' ? 'selected' : '' ?>>CRM</option>
                                <option value="Сайт" <?= ($p['source'] ?? '') === 'Сайт' ? 'selected' : '' ?>>Сайт</option>
                            </select>
                        </td>
                        <td>
                            <select form="formEditP_<?= $p_id ?>" name="status" class="t-input">
                                <option value="Бронь" <?= ($p['status'] ?? '') === 'Бронь' ? 'selected' : '' ?>>Бронь</option>
                                <option value="Предоплата" <?= ($p['status'] ?? '') === 'Предоплата' ? 'selected' : '' ?>>Предоплата</option>
                                <option value="Оплачено" <?= ($p['status'] ?? '') === 'Оплачено' ? 'selected' : '' ?>>Оплачено</option>
                                <option value="Отмена" <?= ($p['status'] ?? '') === 'Отмена' ? 'selected' : '' ?>>Отмена</option>
                            </select>
                        </td>
                        <td><input form="formEditP_<?= $p_id ?>" type="text" name="notes" class="t-input" value="<?= htmlspecialchars($p['notes'] ?? '') ?>"></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div class="action-cell">
                                <button form="formEditP_<?= $p_id ?>" type="submit" class="btn-icon btn-view" title="Сохранить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg></button>
                                <button type="button" class="btn-icon btn-del" onclick="cancelEditP(<?= $p_id ?>)" title="Отмена"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal-overlay" id="noteModal">
    <div class="modal-content">
        <h3>Примечание клиента</h3>
        <p id="noteModalText" style="font-size:15px; line-height:1.6; white-space:pre-wrap; color:var(--text-main); margin-bottom:25px;"></p>
        <button type="button" class="btn-cancel" style="margin-top:0;" onclick="document.getElementById('noteModal').style.display='none'">Закрыть</button>
    </div>
</div>

<script>
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

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('mousedown', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });

    function showNoteModal(text) {
        document.getElementById('noteModalText').textContent = text;
        document.getElementById('noteModal').style.display = 'flex';
    }

    function toggleEditP(id) {
        document.querySelectorAll('.view_p_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.edit_p_' + id).forEach(el => el.style.display = 'table-row');
    }
    function cancelEditP(id) {
        document.querySelectorAll('.edit_p_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.view_p_' + id).forEach(el => el.style.display = 'table-row');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg) {
            const messages = {
                'updated': 'Данные участника сохранены!',
                'deleted': 'Участник удален из базы'
            };
            if (messages[msg]) {
                showToast(messages[msg], msg === 'deleted' ? 'error' : 'success');
            }
            const qs = window.location.search.replace(/&?msg=[^&]*/, '');
            window.history.replaceState({}, document.title, window.location.pathname + qs);
        }

        // AJAX Подгрузка прошлых туристов
        let pastOffset = 0;
        const loadPastBtn = document.getElementById('loadPastBtn');
        if (loadPastBtn) {
            loadPastBtn.addEventListener('click', function() {
                const btn = this; 
                const originalText = btn.innerHTML;
                btn.innerHTML = '⏳ Загрузка...'; 
                btn.disabled = true;

                const formData = new FormData();
                formData.append('ajax_load_past_participants', '1');
                formData.append('offset', pastOffset);
                
                // Передаем текущие значения фильтров из URL (если есть)
                formData.append('search', urlParams.get('search') || '');
                formData.append('status', urlParams.get('status') || '');
                formData.append('source', urlParams.get('source') || '');

                fetch('participants.php', { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' }})
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (data.count > 0) {
                            const tbody = document.getElementById('participantsTableBody');
                            if (tbody) tbody.insertAdjacentHTML('afterbegin', data.html); // Вставляем сверху таблицы!
                            
                            const fContainer = document.getElementById('ajaxFormsContainer');
                            if (fContainer && data.forms) fContainer.insertAdjacentHTML('beforeend', data.forms);

                            pastOffset += 20; // Увеличиваем оффсет
                            showToast('Архив успешно подгружен', 'success');
                        }
                        if (data.count < 20) btn.parentElement.style.display = 'none';
                    }
                })
                .finally(() => { btn.innerHTML = originalText; btn.disabled = false; });
            });
        }
    });
</script>

</body>
</html>