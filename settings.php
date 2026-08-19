<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';

if ($current_user_role !== 'admin') {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>Доступ закрыт. Только для администратора.</h2>");
}

// --- АВТО-ОБНОВЛЕНИЕ БАЗЫ ДАННЫХ ---
$pdo->exec("CREATE TABLE IF NOT EXISTS global_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
try { $pdo->exec("ALTER TABLE guides ADD COLUMN sync_token VARCHAR(64) DEFAULT NULL"); } catch(PDOException $e) {}
try { $pdo->exec("ALTER TABLE guides ADD COLUMN phone VARCHAR(50) DEFAULT ''"); } catch(PDOException $e) {}

$pdo->exec("UPDATE guides SET sync_token = SUBSTRING(MD5(RAND()), 1, 20) WHERE sync_token IS NULL");

// Таблица Источников продаж
$pdo->exec("CREATE TABLE IF NOT EXISTS booking_sources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, sort_order INT DEFAULT 999)");
$count_sources = $pdo->query("SELECT COUNT(*) FROM booking_sources")->fetchColumn();
if ($count_sources == 0) {
    $pdo->exec("INSERT INTO booking_sources (name, sort_order) VALUES ('Прямые', 1), ('Трипстер', 2), ('Спутник 8', 3), ('CRM', 4), ('Сайт', 5)");
}

// Токен Админа для календаря
$admin_sync_token = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'admin_sync_token'")->fetchColumn();
if (!$admin_sync_token) {
    $admin_sync_token = substr(md5(uniqid(rand(), true)), 0, 20);
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('admin_sync_token', ?)")->execute([$admin_sync_token]);
}
// -----------------------------------

// --- СОРТИРОВКА AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_sort') {
    $table = $_POST['table'] ?? '';
    $order = $_POST['order'] ?? [];
    if (in_array($table, ['guides', 'expense_categories', 'booking_sources']) && is_array($order)) {
        foreach ($order as $index => $id) { $pdo->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?")->execute([$index, (int)$id]); }
        echo json_encode(['status' => 'success']);
    }
    exit;
}

// --- УМНОЕ ДОБАВЛЕНИЕ СОТРУДНИКА / ГИДА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $name = trim($_POST['name'] ?? '');
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'guide';
    $email = trim($_POST['email'] ?? '');
    $password_raw = trim($_POST['password'] ?? '');
    
    if ($name !== '') {
        // 1. Если это гид, добавляем его в справочник для выпадающих списков
        if ($role === 'guide') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM guides WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() == 0) {
                $pdo->prepare("INSERT INTO guides (name, sort_order) VALUES (?, 999)")->execute([$name]);
                $pdo->exec("UPDATE guides SET sync_token = SUBSTRING(MD5(RAND()), 1, 20) WHERE sync_token IS NULL");
            }
        }
        
        // 2. Если указан Email и Пароль (или если это админ), создаем аккаунт для входа
        if ($email !== '' && $password_raw !== '') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() == 0) { // Проверка на дубль email
                $hash = password_hash($password_raw, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)")->execute([$name, $email, $hash, $role]);
            }
        } elseif ($role === 'admin') {
            header("Location: settings.php?msg=error_admin_creds"); exit;
        }
    }
    header("Location: settings.php?msg=staff_added"); exit;
}

// --- УПРАВЛЕНИЕ ГИДАМИ В СПРАВОЧНИКЕ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_guide_phone'])) {
    $g_id = (int)$_POST['update_guide_phone'];
    $phone = trim($_POST['phone'] ?? '');
    $pdo->prepare("UPDATE guides SET phone = ? WHERE id = ?")->execute([$phone, $g_id]);
    header("Location: settings.php?msg=phone_saved"); exit;
}
if (isset($_GET['del_guide'])) {
    $pdo->prepare("DELETE FROM guides WHERE id = ?")->execute([(int)$_GET['del_guide']]);
    header("Location: settings.php?msg=guide_deleted"); exit;
}

// --- УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ (ДОСТУПОМ) ---
if (isset($_GET['del_user'])) {
    $uid = (int)$_GET['del_user'];
    if ($uid !== $_SESSION['user_id']) { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); }
    header("Location: settings.php?msg=user_deleted"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $uid = (int)$_POST['user_id']; $new_password = trim($_POST['new_password']);
    if ($new_password !== '') { $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new_password, PASSWORD_DEFAULT), $uid]); }
    header("Location: settings.php?msg=pass_changed"); exit;
}

// --- КАТЕГОРИИ РАСХОДОВ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense_cat'])) {
    $name = trim($_POST['expense_cat_name'] ?? '');
    if ($name !== '') { $pdo->prepare("INSERT INTO expense_categories (name, sort_order) VALUES (?, 999)")->execute([$name]); }
    header("Location: settings.php?msg=cat_added"); exit;
}
if (isset($_GET['del_expense_cat'])) {
    $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([(int)$_GET['del_expense_cat']]);
    header("Location: settings.php?msg=cat_deleted"); exit;
}

// --- ИСТОЧНИКИ ПРОДАЖ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_source'])) {
    $name = trim($_POST['source_name'] ?? '');
    if ($name !== '') { $pdo->prepare("INSERT INTO booking_sources (name, sort_order) VALUES (?, 999)")->execute([$name]); }
    header("Location: settings.php?msg=source_added"); exit;
}
if (isset($_GET['del_source'])) {
    $pdo->prepare("DELETE FROM booking_sources WHERE id = ?")->execute([(int)$_GET['del_source']]);
    header("Location: settings.php?msg=source_deleted"); exit;
}

// --- TELEGRAM НАСТРОЙКИ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_telegram'])) {
    $tg_bot = trim($_POST['tg_bot'] ?? '');
    $tg_chat = trim($_POST['tg_chat'] ?? '');
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('tg_bot', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$tg_bot, $tg_bot]);
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('tg_chat', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$tg_chat, $tg_chat]);
    header("Location: settings.php?msg=tg_saved"); exit;
}

// Загрузка данных
$guides = $pdo->query("SELECT * FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll();
$expense_cats = $pdo->query("SELECT * FROM expense_categories ORDER BY sort_order ASC, name ASC")->fetchAll();
$sources = $pdo->query("SELECT * FROM booking_sources ORDER BY sort_order ASC, name ASC")->fetchAll();
$users = $pdo->query("SELECT * FROM users ORDER BY role ASC, name ASC")->fetchAll();

$tg_bot = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'tg_bot'")->fetchColumn() ?: '';
$tg_chat = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'tg_chat'")->fetchColumn() ?: '';

$admin_ics_link = "https://" . $_SERVER['HTTP_HOST'] . "/calendar_feed.php?token=" . $admin_sync_token;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки — CRM</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05); --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em;}
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box;}
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }

        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .page-header { margin-bottom: 45px; } /* УВЕЛИЧЕННЫЙ ОТСТУП */
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;}

        .section-wrap { margin-bottom: 30px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
        
        .card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 25px; box-shadow: var(--shadow-md); border: 1px solid var(--border); box-sizing: border-box; }
        .card h3 { margin: 0 0 20px 0; font-size: 18px; font-weight: 800; border-bottom: 2px solid #F1F5F9; padding-bottom: 12px; color: var(--text-main); display: flex; align-items: center; gap: 8px;}
        
        .admin-sync-box { background: linear-gradient(135deg, #EEF2FF, #E0E7FF); border: 1px solid #C7D2FE; padding: 25px 30px; border-radius: var(--radius-lg); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; box-shadow: var(--shadow-md);}
        .admin-sync-box h3 { margin: 0 0 8px 0; color: #3730A3; border: none; padding: 0; font-size: 20px; font-weight: 800;}
        .admin-sync-box p { margin: 0; font-size: 14px; color: #4F46E5; line-height: 1.5; font-weight: 500; max-width: 700px;}
        
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 15px; }
        .form-group label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;}
        
        /* ОБНОВЛЕННЫЕ СТИЛИ ПОЛЕЙ И ВЫПАДАЮЩИХ СПИСКОВ */
        .t-input { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; background: #F8FAFC; color: var(--text-main); box-sizing: border-box; font-family: inherit; font-weight: 500; transition: var(--transition); outline: none;}
        .t-input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light);}
        select.t-input {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%2364748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>');
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }
        
        .btn-save { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; font-size: 14px; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); white-space: nowrap;}
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}
        
        .btn-create { background: var(--text-main); color: white; padding: 12px 24px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; transition: var(--transition); box-shadow: 0 4px 10px rgba(0,0,0,0.15); display: inline-flex; align-items: center; gap: 8px;}
        .btn-create:hover { background: #1F2937; transform: translateY(-1px); box-shadow: 0 6px 15px rgba(0,0,0,0.2);}
        
        .inline-form { display: flex; gap: 10px; margin-bottom: 20px; align-items: stretch;}
        .inline-form .t-input { flex-grow: 1; }
        
        ul.sortable-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px;}
        ul.sortable-list li { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: #fff; gap: 15px; flex-wrap: wrap; transition: var(--transition); box-shadow: var(--shadow-sm);}
        ul.sortable-list li:hover { border-color: #CBD5E1; box-shadow: var(--shadow-md); transform: translateY(-1px);}
        
        .list-left { display: flex; align-items: center; gap: 12px; flex-grow: 1; min-width: 200px; }
        .drag-handle { cursor: grab; color: #9CA3AF; font-size: 20px; display: flex; align-items: center;}
        .drag-handle:active { cursor: grabbing; color: var(--primary); }
        .item-name { font-size: 15px; font-weight: 700; color: var(--text-main); }
        
        .list-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end;}
        .phone-input { width: 150px; padding: 8px 12px !important; font-size: 13px !important; background: #F8FAFC !important;}
        
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: var(--radius-sm); font-size: 14px; border: none; cursor: pointer; transition: var(--transition); background: #F8FAFC; color: #64748B; text-decoration: none;}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        
        .btn-link { background: var(--primary-light); color: var(--primary); display: inline-flex; align-items: center; gap: 6px; padding: 0 12px; height: 36px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700; border: none; cursor: pointer; transition: var(--transition);}
        .btn-link:hover { background: #E0E7FF; color: #3730A3; transform: translateY(-1px); }
        
        .btn-save-mini { background: #10B981; color: white; width: 36px; height: 36px; padding: 0;}
        .btn-save-mini:hover { background: #059669; }
        .btn-del { background: #FEF2F2; color: #EF4444; } 
        .btn-del:hover { background: #FEE2E2; color: #DC2626; }

        .table-responsive { overflow-x: auto; background: var(--card-bg); border-radius: var(--radius-sm); border: 1px solid var(--border); box-shadow: var(--shadow-sm); margin-top: 10px;}
        table { width: 100%; border-collapse: collapse; white-space: nowrap; }
        th { background: #F8FAFC; padding: 14px 20px; text-align: left; font-size: 12px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); letter-spacing: 0.03em;}
        td { padding: 14px 20px; border-bottom: 1px solid #F1F5F9; font-size: 14px; color: var(--text-main); font-weight: 500; vertical-align: middle;}
        tr:hover td { background: #F8FAFC; }
        tr:last-child td { border-bottom: none; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(4px); opacity: 0; transition: opacity 0.3s; padding: 20px; box-sizing: border-box;}
        .modal-overlay.show { opacity: 1; }
        .modal-box { background: var(--card-bg); width: 100%; max-width: 450px; border-radius: var(--radius-lg); padding: 30px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); transform: translateY(20px); transition: transform 0.3s; position: relative;}
        .modal-overlay.show .modal-box { transform: translateY(0); }
        .modal-title { margin: 0 0 5px 0; font-size: 22px; font-weight: 800; color: var(--text-main); }
        .btn-close { position: absolute; top: 20px; right: 20px; background: #F1F5F9; border: none; font-size: 16px; cursor: pointer; color: var(--text-muted); width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: var(--transition);}
        .btn-close:hover { background: #E2E8F0; color: var(--text-main); }

        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%); transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55); display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        @media (max-width: 900px) {
            .grid-2 { grid-template-columns: 1fr; }
            .inline-form { flex-direction: column; }
            .list-actions { width: 100%; justify-content: flex-start; }
            .phone-input { flex-grow: 1; }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="page-header">
        <h2>Настройки системы</h2>
    </div>

    <div class="admin-sync-box">
        <div>
            <h3>📱 Сводный календарь компании</h3>
            <p>Подключите эту ссылку к своему iPhone или Android, чтобы видеть <b>абсолютно все экскурсии всех гидов</b> и их отгулы прямо в стандартном календаре.</p>
        </div>
        <button type="button" class="btn-create" style="margin:0; background:white; color:#4F46E5;" onclick="copySyncLink('<?= $admin_ics_link ?>', 'АДМИН')">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            Скопировать .ics ссылку
        </button>
    </div>

    <div class="section-wrap grid-2">
        <div class="card" style="background: #F8FAFC;">
            <h3>➕ Добавить сотрудника / гида</h3>
            <form method="POST">
                <input type="hidden" name="add_staff" value="1">
                
                <div class="form-group">
                    <label>Имя Фамилия *</label>
                    <input type="text" name="name" class="t-input" placeholder="Например: Иван Иванов" required style="background: #fff;">
                </div>
                
                <div class="form-group">
                    <label>Роль в системе *</label>
                    <select name="role" class="t-input" id="roleSelect" onchange="toggleAccessFields()" style="background: #fff;">
                        <option value="guide" selected>Гид (Ограниченный доступ)</option>
                        <option value="admin">Администратор (Полный доступ)</option>
                    </select>
                </div>
                
                <div class="form-group" id="emailGroup">
                    <label>E-mail (Логин) <span id="emailOpt" style="color:var(--text-muted); font-weight:normal; text-transform:none;">— оставьте пустым, если доступ в CRM не нужен</span></label>
                    <input type="email" name="email" id="emailInput" class="t-input" placeholder="ivan@mail.ru" style="background: #fff;">
                </div>
                
                <div class="form-group" id="passGroup">
                    <label>Временный пароль</label>
                    <input type="text" name="password" id="passInput" class="t-input" placeholder="Пароль для входа" style="background: #fff;">
                </div>
                
                <button type="submit" class="btn-create" style="margin-top: 10px; width: 100%; justify-content: center;">Сохранить сотрудника</button>
            </form>
        </div>
        
        <div class="card">
            <h3>🔐 Доступы в CRM (Пользователи)</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:15px; margin-top:-10px;">Люди, которые могут заходить в эту систему под своим логином.</p>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Имя</th>
                            <th>Email (Логин)</th>
                            <th>Роль</th>
                            <th style="text-align: right;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($u['name']) ?></strong></td>
                            <td style="color: var(--text-muted);"><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <?php if($u['role'] === 'admin'): ?>
                                    <span style="background: #EEF2FF; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; color: #4F46E5; text-transform: uppercase;">Админ</span>
                                <?php else: ?>
                                    <span style="background: #D1FAE5; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; color: #047857; text-transform: uppercase;">Гид</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div class="action-cell">
                                    <button type="button" class="btn-icon" style="background:#F1F5F9;" onclick="openPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')" title="Изменить пароль">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                    </button>
                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                        <a href="?del_user=<?= $u['id'] ?>" class="btn-icon btn-del" onclick="return confirm('Удалить пользователя (закрыть доступ)?');" title="Закрыть доступ">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="section-wrap grid-2">
        <div class="card">
            <h3>👤 Справочник гидов</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px; margin-top:-10px;">Эти люди появляются в выпадающих списках при создании экскурсий.</p>
            
            <ul class="sortable-list" data-table="guides">
                <?php foreach ($guides as $g): ?>
                <li data-id="<?= $g['id'] ?>">
                    <div class="list-left">
                        <div class="drag-handle" title="Потяните для сортировки">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        </div>
                        <span class="item-name"><?= htmlspecialchars($g['name']) ?></span>
                    </div>

                    <div class="list-actions">
                        <form method="POST" style="margin:0; display:flex; gap:6px;">
                            <input type="hidden" name="update_guide_phone" value="<?= $g['id'] ?>">
                            <input type="text" name="phone" class="t-input phone-input" value="<?= htmlspecialchars($g['phone']) ?>" placeholder="Телефон...">
                            <button type="submit" class="btn-icon btn-save-mini" title="Сохранить телефон">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                            </button>
                        </form>

                        <button type="button" class="btn-link" onclick="copySyncLink('https://<?= $_SERVER['HTTP_HOST'] ?>/calendar_feed.php?token=<?= $g['sync_token'] ?>', '<?= htmlspecialchars($g['name']) ?>')" title="Скопировать ссылку для телефона">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            .ics
                        </button>
                        
                        <a href="?del_guide=<?= $g['id'] ?>" class="btn-icon btn-del" onclick="return confirm('Удалить гида из справочника?');" title="Удалить">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <h3>📢 Источники продаж (Каналы)</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px; margin-top:-10px;">Откуда к вам приходят туристы (Авито, Трипстер и т.д.).</p>
            <form method="POST" class="inline-form">
                <input type="text" name="source_name" class="t-input" placeholder="Новый источник (например: Авито)..." required>
                <button type="submit" name="add_source" class="btn-save">+ Добавить</button>
            </form>
            <ul class="sortable-list" data-table="booking_sources">
                <?php foreach ($sources as $src): ?>
                <li data-id="<?= $src['id'] ?>">
                    <div class="list-left">
                        <div class="drag-handle" title="Потяните для сортировки">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        </div>
                        <span class="item-name"><?= htmlspecialchars($src['name']) ?></span>
                    </div>
                    <a href="?del_source=<?= $src['id'] ?>" class="btn-icon btn-del" onclick="return confirm('Удалить источник?');" title="Удалить">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="section-wrap grid-2">
        <div class="card">
            <h3>💰 Категории расходов</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px; margin-top:-10px;">Поможет анализировать, куда уходят деньги с туров.</p>
            <form method="POST" class="inline-form">
                <input type="text" name="expense_cat_name" class="t-input" placeholder="Новая категория (например: Бензин)..." required>
                <button type="submit" name="add_expense_cat" class="btn-save">+ Добавить</button>
            </form>
            <ul class="sortable-list" data-table="expense_categories">
                <?php foreach ($expense_cats as $ec): ?>
                <li data-id="<?= $ec['id'] ?>">
                    <div class="list-left">
                        <div class="drag-handle" title="Потяните для сортировки">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                        </div>
                        <span class="item-name"><?= htmlspecialchars($ec['name']) ?></span>
                    </div>
                    <a href="?del_expense_cat=<?= $ec['id'] ?>" class="btn-icon btn-del" onclick="return confirm('Удалить категорию?');" title="Удалить">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <h3>✈️ Уведомления в Telegram</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px; line-height: 1.5; margin-top:-10px;">Настройте бота, чтобы новые заявки с сайта моментально приходили вам в мессенджер.</p>
            <form method="POST">
                <input type="hidden" name="save_telegram" value="1">
                <div class="form-group">
                    <label>Токен бота (из BotFather)</label>
                    <input type="text" name="tg_bot" class="t-input" placeholder="123456:ABC-DEF..." value="<?= htmlspecialchars($tg_bot) ?>">
                </div>
                <div class="form-group">
                    <label>ID Чата / Группы</label>
                    <input type="text" name="tg_chat" class="t-input" placeholder="-10012345678" value="<?= htmlspecialchars($tg_chat) ?>">
                </div>
                <button type="submit" class="btn-save" style="margin-top: 10px; width: 100%;">Сохранить интеграцию</button>
            </form>
        </div>
    </div>
</div>

<div id="passwordModal" class="modal-overlay">
    <div class="modal-box">
        <button class="btn-close" onclick="closePasswordModal()">✕</button>
        <h3 class="modal-title">Смена пароля</h3>
        <p style="font-size:14px; color:var(--text-muted); margin-bottom: 25px;">Для пользователя: <strong id="modalUserName" style="color:var(--primary);"></strong></p>
        
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="change_password" value="1">
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="form-group">
                <input type="text" name="new_password" class="t-input" placeholder="Введите новый пароль" required>
            </div>
            <button type="submit" class="btn-save" style="width:100%; margin-top: 15px; height: 44px;">Сохранить пароль</button>
        </form>
    </div>
</div>

<script>
    // Показ/Скрытие обязательных полей в зависимости от роли
    function toggleAccessFields() {
        const role = document.getElementById('roleSelect').value;
        const emailReq = document.getElementById('emailInput');
        const passReq = document.getElementById('passInput');
        const optText = document.getElementById('emailOpt');
        
        if (role === 'admin') {
            emailReq.required = true;
            passReq.required = true;
            optText.style.display = 'none';
        } else {
            emailReq.required = false;
            passReq.required = false;
            optText.style.display = 'inline';
        }
    }
    document.addEventListener('DOMContentLoaded', toggleAccessFields);

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
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 3000);
    }

    function copySyncLink(link, who) {
        navigator.clipboard.writeText(link).then(() => {
            showToast(`Ссылка календаря для [${who}] скопирована!`);
        }).catch(err => {
            prompt('Скопируйте ссылку вручную:', link);
        });
    }

    function openPasswordModal(id, name) {
        document.getElementById('modalUserId').value = id;
        document.getElementById('modalUserName').textContent = name;
        document.getElementById('passwordModal').style.display = 'flex';
        setTimeout(() => document.getElementById('passwordModal').classList.add('show'), 10);
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').classList.remove('show');
        setTimeout(() => document.getElementById('passwordModal').style.display = 'none', 300);
    }

    // Закрытие модалки по фону
    document.getElementById('passwordModal').addEventListener('mousedown', function(e) {
        if (e.target === this) closePasswordModal();
    });

    document.addEventListener('DOMContentLoaded', function () {
        // Инициализация Drag-n-Drop сортировки
        document.querySelectorAll('.sortable-list').forEach(list => {
            new Sortable(list, {
                animation: 150, handle: '.drag-handle',
                onEnd: function () {
                    const formData = new FormData();
                    formData.append('action', 'update_sort');
                    formData.append('table', list.getAttribute('data-table'));
                    Array.from(list.querySelectorAll('li')).forEach(item => formData.append('order[]', item.getAttribute('data-id')));
                    fetch('settings.php', { method: 'POST', body: formData });
                }
            });
        });

        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg) {
            const messages = {
                'staff_added': 'Сотрудник успешно добавлен!',
                'error_admin_creds': 'Для админа Email и Пароль обязательны!',
                'phone_saved': 'Телефон гида сохранен!',
                'guide_deleted': 'Гид удален из справочника',
                'cat_added': 'Категория добавлена!',
                'cat_deleted': 'Категория удалена',
                'source_added': 'Источник продаж добавлен!',
                'source_deleted': 'Источник продаж удален',
                'user_deleted': 'Доступ в CRM закрыт',
                'pass_changed': 'Пароль успешно изменен!',
                'tg_saved': 'Настройки Telegram сохранены!'
            };
            if (messages[msg]) {
                showToast(messages[msg], msg.includes('deleted') || msg.includes('error') ? 'error' : 'success');
            }
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });
</script>

</body>
</html>