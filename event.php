<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';

$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($event_id <= 0) { header("Location: index.php"); exit; }

// Получаем информацию об экскурсии
$stmt_ev = $pdo->prepare("SELECT e.*, t.name AS tour_name, t.public_name, t.duration, t.coordinates 
                         FROM events e 
                         JOIN tours_catalog t ON e.tour_id = t.id 
                         WHERE e.id = ?");
$stmt_ev->execute([$event_id]);
$event = $stmt_ev->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>Заявка не найдена.</h2>");
}

// Ограничение доступа для гида (видит только свои туры)
if ($current_user_role === 'guide' && ($event['guide'] ?? '') !== $current_user_name) {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>Доступ запрещен.</h2>");
}

// --- СМЕНА ГИДА (АДМИН) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_event_guide']) && $current_user_role === 'admin') {
    $pdo->prepare("UPDATE events SET guide = ? WHERE id = ?")->execute([$_POST['guide'], $event_id]);
    header("Location: event.php?id=" . $event_id . "&msg=guide_updated"); exit;
}

// --- ДОБАВЛЕНИЕ УЧАСТНИКА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_participant'])) {
    $client_name = trim($_POST['client_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $seats = (int)($_POST['seats'] ?? 1);
    $price = (int)($_POST['price'] ?? 0);
    $source = trim($_POST['source'] ?? 'CRM');
    $status = trim($_POST['status'] ?? 'Бронь');
    $notes = trim($_POST['notes'] ?? '');
    $ticket_token = substr(md5(uniqid(rand(), true)), 0, 32);

    if ($client_name !== '' && $phone !== '') {
        $pdo->prepare("INSERT INTO participants (event_id, client_name, phone, email, seats, price, source, status, notes, ticket_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$event_id, $client_name, $phone, $email, $seats, $price, $source, $status, $notes, $ticket_token]);
    }
    header("Location: event.php?id=" . $event_id . "&msg=participant_added"); exit;
}

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

    $pdo->prepare("UPDATE participants SET client_name=?, phone=?, email=?, seats=?, price=?, source=?, status=?, notes=? WHERE id=? AND event_id=?")
        ->execute([$client_name, $phone, $email, $seats, $price, $source, $status, $notes, $p_id, $event_id]);
    header("Location: event.php?id=" . $event_id . "&msg=participant_updated"); exit;
}

// --- УДАЛЕНИЕ УЧАСТНИКА ---
if (isset($_GET['del_participant'])) {
    $pdo->prepare("DELETE FROM participants WHERE id = ? AND event_id = ?")->execute([(int)$_GET['del_participant'], $event_id]);
    header("Location: event.php?id=" . $event_id . "&msg=participant_deleted"); exit;
}

// --- ДОБАВЛЕНИЕ РАСХОДА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
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

    if ($amount > 0) {
        $pdo->prepare("INSERT INTO expenses (event_id, amount, category, description, receipt_path) VALUES (?, ?, ?, ?, ?)")
            ->execute([$event_id, $amount, $category, $description, $receipt_path]);
    }
    header("Location: event.php?id=" . $event_id . "&msg=expense_added"); exit;
}

// --- УДАЛЕНИЕ РАСХОДА ---
if (isset($_GET['del_expense'])) {
    $pdo->prepare("DELETE FROM expenses WHERE id = ? AND event_id = ?")->execute([(int)$_GET['del_expense'], $event_id]);
    header("Location: event.php?id=" . $event_id . "&msg=expense_deleted"); exit;
}

// Загрузка списков
$guides = $pdo->query("SELECT name FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
$expense_cats = $pdo->query("SELECT name FROM expense_categories ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);

// Получаем всех участников
$stmt_p = $pdo->prepare("SELECT * FROM participants WHERE event_id = ? ORDER BY id DESC");
$stmt_p->execute([$event_id]);
$participants = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

// Получаем все расходы
$stmt_ex = $pdo->prepare("SELECT * FROM expenses WHERE event_id = ? ORDER BY id DESC");
$stmt_ex->execute([$event_id]);
$expenses = $stmt_ex->fetchAll(PDO::FETCH_ASSOC);

// Подсчет сводных показателей
$total_seats = 0; $total_income = 0;
foreach ($participants as $p) {
    if (($p['status'] ?? '') !== 'Отмена') {
        $total_seats += (int)($p['seats'] ?? 0);
        $total_income += (int)($p['price'] ?? 0);
    }
}
$total_expenses = 0;
foreach ($expenses as $ex) {
    $total_expenses += (int)($ex['amount'] ?? 0);
}
$profit = $total_income - $total_expenses;

$months_ru = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$ts = strtotime($event['tour_date']);
$date_formatted = date('j', $ts) . ' ' . $months_ru[date('n', $ts)] . ' ' . date('Y', $ts);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($event['public_name'] ?: $event['tour_name'] ?? '') ?> — <?= $date_formatted ?></title>
    <style>
        /* ПРЕМИУМ ДИЗАЙН (Soft UI & Glassmorphism) */
        :root { 
            --primary: #4F46E5; 
            --primary-hover: #4338CA; 
            --primary-light: #EEF2FF;
            --bg: #F8FAFC; 
            --card-bg: #FFFFFF; 
            --border: #E2E8F0; 
            --text-main: #0F172A; 
            --text-muted: #64748B;
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
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

        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 700; margin-bottom: 20px; transition: var(--transition); padding: 8px 16px; background: var(--primary-light); border-radius: 99px; }
        .back-link:hover { background: #E0E7FF; transform: translateX(-3px); }

        /* Шапка тура */
        .event-header { background: linear-gradient(135deg, #EEF2FF, #E0E7FF); border: 1px solid #C7D2FE; border-radius: 20px; padding: 30px; margin-bottom: 30px; box-shadow: var(--shadow-md); position: relative; overflow: hidden; }
        .event-title-row { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .event-title-row h1 { margin: 0; font-size: 28px; font-weight: 800; color: #1E1B4B; letter-spacing: -0.02em; }
        .event-date-badge { background: var(--primary); color: white; padding: 8px 18px; border-radius: 99px; font-weight: 700; font-size: 14px; white-space: nowrap; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }

        .guide-select-box { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600; flex-wrap: wrap; color: #3730A3; }
        .guide-select-box select { padding: 8px 14px; border-radius: var(--radius-sm); border: 1px solid #C7D2FE; font-size: 14px; font-family: inherit; outline: none; background: white; font-weight: 600; color: var(--text-main); cursor: pointer; transition: var(--transition); }
        .guide-select-box select:hover { border-color: var(--primary); }

        /* Дашборды финансовой сводки */
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 35px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 22px; box-shadow: var(--shadow-md); transition: var(--transition); position: relative; overflow: hidden; }
        .dash-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-float); }
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; background: var(--border); }
        .dash-card.profit::before { background: #10B981; }
        .dash-title { font-size: 12px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em; }
        .dash-val { font-size: 26px; font-weight: 800; color: var(--text-main); }
        .val-green { color: #10B981; } .val-red { color: #EF4444; }

        /* Секции и Таблицы */
        .section-title { font-size: 20px; font-weight: 800; margin: 35px 0 15px 0; display: flex; align-items: center; justify-content: space-between; padding-bottom: 12px; border-bottom: 2px solid #F1F5F9; letter-spacing: -0.01em; }
        
        .table-wrapper { background: var(--card-bg); border-radius: var(--radius-lg); overflow: hidden; margin-bottom: 30px; box-shadow: var(--shadow-md); border: 1px solid var(--border); }
        .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        table { width: 100%; min-width: 900px; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #F1F5F9; font-size: 14px; vertical-align: middle; }
        th { background-color: #F8FAFC; font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; letter-spacing: 0.05em; }
        tr:hover td { background-color: #F8FAFC; }
        tr:last-child td { border-bottom: none; }

        /* Инпуты таблицы */
        input.t-input, select.t-input { 
            width: 100%; box-sizing: border-box; padding: 9px 12px; 
            background: #F8FAFC; border: 1px solid transparent; 
            border-radius: var(--radius-sm); font-size: 13px; font-family: inherit; 
            outline: none; transition: var(--transition); color: var(--text-main); font-weight: 500;
        }
        input.t-input:hover, select.t-input:hover { background: #F1F5F9; }
        input.t-input:focus, select.t-input:focus { background: #FFFFFF; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }

        .add-form-row td { background: var(--primary-light) !important; border-bottom: 2px solid #C7D2FE; }
        .edit_form_row td { background: #FFFBEB !important; }

        .btn-add-submit { background: var(--text-main); color: white; border: none; padding: 10px 18px; border-radius: var(--radius-sm); font-weight: 700; font-size: 13px; cursor: pointer; transition: var(--transition); white-space: nowrap; width: 100%; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
        .btn-add-submit:hover { background: #1E293B; transform: translateY(-1px); box-shadow: 0 6px 15px rgba(0,0,0,0.2); }

        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 99px; font-size: 12px; font-weight: 700; background: #F1F5F9; color: var(--text-muted); }
        .status-<?php echo md5('Бронь'); ?> { background: #FEF3C7; color: #B45309; }
        .status-<?php echo md5('Предоплата'); ?> { background: #DBEAFE; color: #1D4ED8; }
        .status-<?php echo md5('Оплачено'); ?> { background: #D1FAE5; color: #047857; }
        .status-<?php echo md5('Отмена'); ?> { background: #FEE2E2; color: #B91C1C; text-decoration: line-through; }

        .action-cell { display: flex; gap: 8px; justify-content: flex-end; align-items: center; }
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: var(--radius-sm); font-size: 14px; border: none; cursor: pointer; transition: var(--transition); background: #F8FAFC; color: #64748B; text-decoration: none;}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        
        .btn-edit { background: var(--primary-light); color: var(--primary); } .btn-edit:hover { background: #E0E7FF; color: #3730A3; }
        .btn-del { background: #FEF2F2; color: #EF4444; } .btn-del:hover { background: #FEE2E2; color: #DC2626; }
        .btn-wa { background: #DCFCE7; color: #16A34A; } .btn-wa:hover { background: #BBF7D0; color: #15803D; }

        .client-link { color: var(--text-main); font-weight: 700; text-decoration: none; transition: var(--transition); }
        .client-link:hover { color: var(--primary); text-decoration: underline; }

        .contact-col { display: flex; flex-direction: column; gap: 4px; }
        .scroll-hint { display: none; font-size: 12px; color: var(--primary); margin-bottom: 8px; font-weight: 700; }

        /* Toast Уведомления */
        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%) scale(0.9); transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55); display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0) scale(1); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .container { padding: 10px; }
            .scroll-hint { display: block; }
            .event-title-row { flex-direction: column; align-items: flex-start; gap: 12px; }
            .event-title-row h1 { font-size: 22px; line-height: 1.2; }
            .dash-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
            .dash-card { padding: 16px; }
            .dash-val { font-size: 20px; }
            .add-form-row td { display: block; width: 100%; box-sizing: border-box; padding: 10px 15px; border-bottom: none; }
            .add-form-row td:last-child { padding-bottom: 15px; border-bottom: 2px solid var(--primary-light); }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <a href="index.php" class="back-link">← Вернуться к списку</a>

    <div class="event-header">
        <div class="event-title-row">
            <div>
                <h1><?= htmlspecialchars($event['public_name'] ?: $event['tour_name'] ?? '') ?></h1>
                <?php if (!empty($event['coordinates'])): ?>
                    <div style="font-size: 14px; color: var(--text-muted); margin-top: 6px; font-weight:500;">📍 Старт: <?= htmlspecialchars($event['coordinates'] ?? '') ?></div>
                <?php endif; ?>
            </div>
            <div class="event-date-badge">🗓 <?= $date_formatted ?></div>
        </div>

        <div class="guide-select-box">
            <span>Назначенный гид:</span>
            <?php if ($current_user_role === 'admin'): ?>
                <form method="POST" style="margin:0; display:inline-flex; gap:6px;">
                    <input type="hidden" name="update_event_guide" value="1">
                    <select name="guide" onchange="this.form.submit()">
                        <option value="Не назначен" <?= ($event['guide'] ?? '') === 'Не назначен' ? 'selected' : '' ?>>Не назначен</option>
                        <?php foreach ($guides as $g): ?>
                            <option value="<?= htmlspecialchars($g ?? '') ?>" <?= ($event['guide'] ?? '') === $g ? 'selected' : '' ?>><?= htmlspecialchars($g ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php else: ?>
                <strong>🙋‍♂️ <?= htmlspecialchars($event['guide'] ?? '') ?></strong>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-grid">
        <div class="dash-card profit">
            <div class="dash-title">Прибыль тура</div>
            <div class="dash-val val-green"><?= number_format($profit, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card">
            <div class="dash-title">Собрано денег</div>
            <div class="dash-val"><?= number_format($total_income, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card">
            <div class="dash-title">Расходы тура</div>
            <div class="dash-val val-red"><?= number_format($total_expenses, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card">
            <div class="dash-title">Всего туристов</div>
            <div class="dash-val"><?= $total_seats ?> чел.</div>
        </div>
    </div>

    <form id="formAddParticipant" method="POST"><input type="hidden" name="add_participant" value="1"></form>
    
    <?php foreach ($participants as $p): ?>
        <form id="formEditP_<?= $p['id'] ?>" method="POST">
            <input type="hidden" name="update_participant" value="1">
            <input type="hidden" name="participant_id" value="<?= $p['id'] ?>">
        </form>
    <?php endforeach; ?>

    <form id="formAddExpense" method="POST" enctype="multipart/form-data"><input type="hidden" name="add_expense" value="1"></form>

    <div class="section-title">👥 Участники экскурсии</div>
    <div class="scroll-hint">↔ Свайпайте таблицу влево-вправо</div>
    
    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>ФИО Клиента</th>
                        <th>Контакты</th>
                        <th style="width: 70px;">Мест</th>
                        <th style="width: 110px;">Сумма (₽)</th>
                        <th>Источник</th>
                        <th>Статус</th>
                        <th>Примечание</th>
                        <th style="text-align: right; width: 130px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="add-form-row">
                        <td><input form="formAddParticipant" type="text" name="client_name" class="t-input" placeholder="Имя Фамилия *" required></td>
                        <td class="contact-col">
                            <input form="formAddParticipant" type="text" name="phone" class="t-input" placeholder="Телефон *" required>
                            <input form="formAddParticipant" type="email" name="email" class="t-input" placeholder="E-mail (опционально)">
                        </td>
                        <td><input form="formAddParticipant" type="number" name="seats" class="t-input" value="1" min="1" required style="min-width: 60px;"></td>
                        <td><input form="formAddParticipant" type="number" name="price" class="t-input" value="0" style="min-width: 80px;"></td>
                        <td>
                            <select form="formAddParticipant" name="source" class="t-input">
                                <option value="Прямые">Прямые</option><option value="Трипстер">Трипстер</option><option value="Спутник 8">Спутник 8</option><option value="CRM" selected>CRM</option><option value="Сайт">Сайт</option>
                            </select>
                        </td>
                        <td>
                            <select form="formAddParticipant" name="status" class="t-input">
                                <option value="Бронь" selected>Бронь</option><option value="Предоплата">Предоплата</option><option value="Оплачено">Оплачено</option><option value="Отмена">Отмена</option>
                            </select>
                        </td>
                        <td><input form="formAddParticipant" type="text" name="notes" class="t-input" placeholder="Примечание..."></td>
                        <td><button form="formAddParticipant" type="submit" class="btn-add-submit">+ Добавить</button></td>
                    </tr>

                    <?php foreach ($participants as $p): 
                        $p_id = $p['id'];
                        $clean_phone = preg_replace('/[^0-9]/', '', $p['phone'] ?? '');
                        if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }
                    ?>
                    <tr class="view_p_<?= $p_id ?>" style="<?= ($p['status'] ?? '') === 'Отмена' ? 'opacity: 0.5;' : '' ?>">
                        <td><a href="client.php?phone=<?= urlencode($p['phone'] ?? '') ?>" class="client-link"><?= htmlspecialchars($p['client_name'] ?? '') ?></a></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($p['phone'] ?? '') ?></div>
                            <?php if (!empty($p['email'])): ?><span style="color:var(--text-muted); font-size:12px; font-weight:500;"><?= htmlspecialchars($p['email'] ?? '') ?></span><?php endif; ?>
                        </td>
                        <td><strong><?= htmlspecialchars($p['seats'] ?? '0') ?></strong></td>
                        <td style="font-weight: 700; color: #10B981; font-size:15px;"><?= number_format($p['price'] ?? 0, 0, '', ' ') ?> ₽</td>
                        <td style="color: var(--text-muted); font-size: 13px; font-weight:600;"><?= htmlspecialchars($p['source'] ?? '') ?></td>
                        <td><span class="status-badge status-<?= md5($p['status'] ?? '') ?>"><?= htmlspecialchars($p['status'] ?? '') ?></span></td>
                        <td style="color: var(--text-muted); font-size: 13px;"><?= !empty($p['notes']) ? htmlspecialchars($p['notes']) : '—' ?></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div class="action-cell">
                                <a href="https://wa.me/<?= $clean_phone ?>" target="_blank" class="btn-icon btn-wa" title="WhatsApp">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.3 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                                </a>
                                <button type="button" class="btn-icon btn-edit" onclick="toggleEditP(<?= $p_id ?>)" title="Редактировать">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </button>
                                <a href="?id=<?= $event_id ?>&del_participant=<?= $p_id ?>" class="btn-icon btn-del" onclick="return confirm('Удалить туриста?');" title="Удалить">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                </a>
                            </div>
                        </td>
                    </tr>

                    <tr class="edit_form_row edit_p_<?= $p_id ?>" style="display: none;">
                        <td><input form="formEditP_<?= $p_id ?>" type="text" name="client_name" class="t-input" value="<?= htmlspecialchars($p['client_name'] ?? '') ?>" required></td>
                        <td class="contact-col">
                            <input form="formEditP_<?= $p_id ?>" type="text" name="phone" class="t-input" value="<?= htmlspecialchars($p['phone'] ?? '') ?>" required>
                            <input form="formEditP_<?= $p_id ?>" type="email" name="email" class="t-input" value="<?= htmlspecialchars($p['email'] ?? '') ?>" placeholder="E-mail">
                        </td>
                        <td><input form="formEditP_<?= $p_id ?>" type="number" name="seats" class="t-input" value="<?= htmlspecialchars($p['seats'] ?? '1') ?>" min="1" required style="min-width: 60px;"></td>
                        <td><input form="formEditP_<?= $p_id ?>" type="number" name="price" class="t-input" value="<?= htmlspecialchars($p['price'] ?? '0') ?>" style="min-width: 80px;"></td>
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
                                <button form="formEditP_<?= $p_id ?>" type="submit" class="btn-icon" style="background:#10B981; color:white;" title="Сохранить">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </button>
                                <button type="button" class="btn-icon btn-del" onclick="cancelEditP(<?= $p_id ?>)" title="Отмена">
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

    <div class="section-title">💸 Расходы по экскурсии</div>
    <div class="scroll-hint">↔ Свайпайте таблицу влево-вправо</div>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="width: 220px;">Категория</th>
                        <th style="width: 140px;">Сумма (₽)</th>
                        <th>Описание / Заметка</th>
                        <th style="width: 130px;">Чек</th>
                        <th style="text-align: right; width: 80px;">Действие</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="add-form-row">
                        <td>
                            <select form="formAddExpense" name="category" class="t-input" required>
                                <?php foreach ($expense_cats as $c): ?>
                                    <option value="<?= htmlspecialchars($c ?? '') ?>"><?= htmlspecialchars($c ?? '') ?></option>
                                <?php endforeach; ?>
                                <option value="Прочее">Прочее</option>
                            </select>
                        </td>
                        <td><input form="formAddExpense" type="number" name="amount" class="t-input" min="1" placeholder="Сумма *" required></td>
                        <td><input form="formAddExpense" type="text" name="description" class="t-input" placeholder="Комментарий..."></td>
                        <td><input form="formAddExpense" type="file" name="receipt" accept="image/*" style="font-size: 11px; max-width:180px;"></td>
                        <td><button form="formAddExpense" type="submit" class="btn-add-submit">+ Расход</button></td>
                    </tr>

                    <?php foreach ($expenses as $ex): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ex['category'] ?: 'Прочее') ?></strong></td>
                        <td style="font-weight: 700; color: #EF4444; font-size:15px;"><?= number_format($ex['amount'] ?? 0, 0, '', ' ') ?> ₽</td>
                        <td style="color: var(--text-muted); font-size: 13px; font-weight:500;"><?= !empty($ex['description']) ? htmlspecialchars($ex['description']) : '—' ?></td>
                        <td>
                            <?php if (!empty($ex['receipt_path'])): ?>
                                <a href="<?= htmlspecialchars($ex['receipt_path']) ?>" target="_blank" style="color:var(--primary); font-weight:700; text-decoration:none; font-size:13px; display:inline-flex; align-items:center; gap:4px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg> Чек
                                </a>
                            <?php else: ?>
                                <span style="color:#94A3B8; font-size:13px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div class="action-cell">
                                <a href="?id=<?= $event_id ?>&del_expense=<?= $ex['id'] ?>" class="btn-icon btn-del" onclick="return confirm('Удалить расход?');" title="Удалить">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
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

    function toggleEditP(id) {
        document.querySelectorAll('.view_p_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.edit_p_' + id).forEach(el => el.style.display = 'table-row');
    }
    function cancelEditP(id) {
        document.querySelectorAll('.edit_p_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.view_p_' + id).forEach(el => el.style.display = 'table-row');
    }

    // Показ системных уведомлений из GET параметров
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg) {
            const messages = {
                'guide_updated': 'Гид успешно изменен!',
                'participant_added': 'Участник добавлен!',
                'participant_updated': 'Данные участника сохранены!',
                'participant_deleted': 'Участник удален',
                'expense_added': 'Расход успешно внесен!',
                'expense_deleted': 'Расход удален'
            };
            if (messages[msg]) {
                showToast(messages[msg], msg.includes('deleted') ? 'error' : 'success');
            }
            // Очищаем URL от параметра msg без перезагрузки
            window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $event_id ?>');
        }
    });
</script>
</body>
</html>