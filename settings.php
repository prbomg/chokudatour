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
    if (in_array($table, ['guides', 'expense_categories']) && is_array($order)) {
        foreach ($order as $index => $id) { $pdo->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?")->execute([$index, (int)$id]); }
        echo json_encode(['status' => 'success']);
    }
    exit;
}

// --- ГИДЫ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_guide'])) {
    $name = trim($_POST['guide_name'] ?? '');
    if ($name !== '') { $pdo->prepare("INSERT INTO guides (name, sort_order) VALUES (?, 999)")->execute([$name]); }
    header("Location: settings.php"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_guide_phone'])) {
    $g_id = (int)$_POST['update_guide_phone'];
    $phone = trim($_POST['phone'] ?? '');
    $pdo->prepare("UPDATE guides SET phone = ? WHERE id = ?")->execute([$phone, $g_id]);
    header("Location: settings.php"); exit;
}
if (isset($_GET['del_guide'])) {
    $pdo->prepare("DELETE FROM guides WHERE id = ?")->execute([(int)$_GET['del_guide']]);
    header("Location: settings.php"); exit;
}

// --- КАТЕГОРИИ РАСХОДОВ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense_cat'])) {
    $name = trim($_POST['expense_cat_name'] ?? '');
    if ($name !== '') { $pdo->prepare("INSERT INTO expense_categories (name, sort_order) VALUES (?, 999)")->execute([$name]); }
    header("Location: settings.php"); exit;
}
if (isset($_GET['del_expense_cat'])) {
    $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([(int)$_GET['del_expense_cat']]);
    header("Location: settings.php"); exit;
}

// --- ПОЛЬЗОВАТЕЛИ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $name = trim($_POST['user_name']); $email = trim($_POST['user_email']); $role = $_POST['user_role'] === 'admin' ? 'admin' : 'guide';
    $password = password_hash(trim($_POST['user_password']), PASSWORD_DEFAULT);
    if ($name !== '' && $email !== '' && $_POST['user_password'] !== '') { $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)")->execute([$name, $email, $password, $role]); }
    header("Location: settings.php"); exit;
}
if (isset($_GET['del_user'])) {
    $uid = (int)$_GET['del_user'];
    if ($uid !== $_SESSION['user_id']) { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]); }
    header("Location: settings.php"); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $uid = (int)$_POST['user_id']; $new_password = trim($_POST['new_password']);
    if ($new_password !== '') { $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new_password, PASSWORD_DEFAULT), $uid]); }
    header("Location: settings.php"); exit;
}

// --- TELEGRAM НАСТРОЙКИ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_telegram'])) {
    $tg_bot = trim($_POST['tg_bot'] ?? '');
    $tg_chat = trim($_POST['tg_chat'] ?? '');
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('tg_bot', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$tg_bot, $tg_bot]);
    $pdo->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES ('tg_chat', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$tg_chat, $tg_chat]);
    header("Location: settings.php"); exit;
}

// Загрузка данных
$guides = $pdo->query("SELECT * FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll();
$expense_cats = $pdo->query("SELECT * FROM expense_categories ORDER BY sort_order ASC, name ASC")->fetchAll();
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
    <title>CRM - Настройки</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        :root { --primary: #4F46E5; --primary-hover: #4338CA; --bg: #F9FAFB; --card-bg: #FFFFFF; --border: #E5E7EB; --text-main: #111827; --text-muted: #6B7280; }
        body { font-family: 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 30px 20px; }
        .container { max-width: 1200px; background: var(--card-bg); padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin: 0 auto; box-sizing: border-box;}
        
        .navbar { display: flex; gap: 15px; margin-bottom: 30px; border-bottom: 1px solid var(--border); padding-bottom: 15px; align-items: center; flex-wrap: wrap;}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 8px 16px; border-radius: 6px; transition: 0.2s; }
        .nav-link.active { background: var(--primary); color: white; }
        .nav-link:hover:not(.active) { background: #F3F4F6; color: var(--text-main); }
        
        .page-header { margin-bottom: 25px; }
        .page-header h2 { margin: 0; font-size: 26px; font-weight: 700; color: var(--text-main); }

        /* Сетки для секций */
        .section-wrap { margin-bottom: 35px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        
        /* Карточки */
        .card { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); height: 100%; box-sizing: border-box;}
        .card h3 { margin: 0 0 20px 0; font-size: 18px; border-bottom: 2px solid #F3F4F6; padding-bottom: 12px; color: var(--text-main); display: flex; align-items: center; gap: 8px;}
        
        /* Особая карточка для админ-календаря */
        .admin-sync-box { background: linear-gradient(135deg, #EEF2FF, #E0E7FF); border: 1px solid #C7D2FE; padding: 25px; border-radius: 12px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;}
        .admin-sync-box h3 { margin: 0 0 8px 0; color: #3730A3; border: none; padding: 0; font-size: 18px;}
        .admin-sync-box p { margin: 0; font-size: 14px; color: #4F46E5; line-height: 1.5; max-width: 700px;}
        
        /* Формы и поля ввода */
        .form-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 15px; }
        .form-group label { font-size: 13px; font-weight: 600; color: var(--text-main); }
        input[type="text"], input[type="email"], select { padding: 10px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; font-family: inherit; transition: 0.2s;}
        input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
        
        /* Кнопки */
        .btn { padding: 10px 16px; border: none; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-family: inherit;}
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #F3F4F6; color: #374151; }
        .btn-secondary:hover { background: #E5E7EB; }
        
        /* Форма в одну строку (для добавления элементов) */
        .inline-form { display: flex; gap: 10px; margin-bottom: 20px; }
        .inline-form input { flex-grow: 1; }
        
        /* Списки (Сортируемые) */
        ul.sortable-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px;}
        ul.sortable-list li { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid var(--border); border-radius: 8px; background: #fff; gap: 15px; flex-wrap: wrap; transition: 0.2s;}
        ul.sortable-list li:hover { border-color: #C7D2FE; box-shadow: 0 4px 6px rgba(79,70,229,0.05); }
        
        .list-left { display: flex; align-items: center; gap: 12px; flex-grow: 1; min-width: 200px; }
        .drag-handle { cursor: grab; color: #9CA3AF; font-size: 18px; }
        .item-name { font-size: 15px; font-weight: 600; color: var(--text-main); }
        
        .list-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .phone-input { width: 140px; padding: 6px 10px !important; font-size: 13px !important; background: #F9FAFB;}
        
        .btn-small { background: #EEF2FF; color: var(--primary); padding: 6px 12px; font-size: 13px; font-weight: 600; border-radius: 6px; border:none; cursor:pointer; transition:0.2s;}
        .btn-small:hover { background: #C7D2FE; }
        .btn-del { color: #EF4444; text-decoration: none; font-weight: bold; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; background: #FEF2F2; transition: 0.2s;}
        .btn-del:hover { background: #FEE2E2; color: #DC2626; }

        /* Таблица пользователей */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; } 
        th, td { padding: 14px 12px; border-bottom: 1px solid var(--border); font-size: 14px; text-align: left; }
        th { font-weight: 600; color: var(--text-muted); text-transform: uppercase; font-size: 12px; background: #F9FAFB;}
        th:first-child { border-top-left-radius: 8px;} th:last-child { border-top-right-radius: 8px;}
        tr:hover td { background: #F9FAFB; }

        @media (max-width: 900px) {
            .grid-2 { grid-template-columns: 1fr; }
            .inline-form { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="page-header">
        <h2>Настройки системы</h2>
    </div>

    <div class="admin-sync-box">
        <div>
            <h3>📱 Сводный календарь компании (Для руководителя)</h3>
            <p>Подключите эту ссылку к своему iPhone или Android, чтобы видеть <b>абсолютно все экскурсии всех гидов</b> и их отгулы прямо в стандартном календаре телефона.</p>
        </div>
        <button type="button" class="btn btn-primary" onclick="copySyncLink('<?= $admin_ics_link ?>', 'АДМИН')">
            🔗 Скопировать .ics ссылку
        </button>
    </div>

    <div class="section-wrap">
        <div class="card">
            <h3>👤 Справочник гидов (Телефоны и персональные календари)</h3>
            
            <form method="POST" class="inline-form">
                <input type="text" name="guide_name" placeholder="Введите имя нового гида..." required>
                <button type="submit" name="add_guide" class="btn btn-primary">+ Добавить гида</button>
            </form>
            
            <ul class="sortable-list" data-table="guides">
                <?php foreach ($guides as $g): ?>
                <li data-id="<?= $g['id'] ?>">
                    <div class="list-left">
                        <span class="drag-handle">☰</span>
                        <span class="item-name"><?= htmlspecialchars($g['name']) ?></span>
                    </div>

                    <div class="list-actions">
                        <form method="POST" style="margin:0; display:flex; gap:6px;">
                            <input type="hidden" name="update_guide_phone" value="<?= $g['id'] ?>">
                            <input type="text" name="phone" class="phone-input" value="<?= htmlspecialchars($g['phone']) ?>" placeholder="Телефон гида...">
                            <button type="submit" class="btn-small" style="padding: 6px;" title="Сохранить телефон">💾</button>
                        </form>

                        <button type="button" class="btn-small" onclick="copySyncLink('https://<?= $_SERVER['HTTP_HOST'] ?>/calendar_feed.php?token=<?= $g['sync_token'] ?>', '<?= htmlspecialchars($g['name']) ?>')">🔗 .ics календарь</button>
                        <a href="?del_guide=<?= $g['id'] ?>" class="btn-del" onclick="return confirm('Удалить гида?');" title="Удалить">✕</a>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="section-wrap grid-2">
        <div class="card">
            <h3>💰 Категории расходов</h3>
            <form method="POST" class="inline-form">
                <input type="text" name="expense_cat_name" placeholder="Новая категория (например: Бензин)..." required>
                <button type="submit" name="add_expense_cat" class="btn btn-primary">+ Добавить</button>
            </form>
            <ul class="sortable-list" data-table="expense_categories">
                <?php foreach ($expense_cats as $ec): ?>
                <li data-id="<?= $ec['id'] ?>">
                    <div class="list-left">
                        <span class="drag-handle">☰</span>
                        <span class="item-name"><?= htmlspecialchars($ec['name']) ?></span>
                    </div>
                    <a href="?del_expense_cat=<?= $ec['id'] ?>" class="btn-del" onclick="return confirm('Удалить категорию?');">✕</a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="card">
            <h3>✈️ Уведомления в Telegram</h3>
            <p style="font-size:13px; color:var(--text-muted); margin-bottom:20px;">Настройте бота, чтобы новые заявки с сайта моментально приходили вам в мессенджер.</p>
            <form method="POST">
                <input type="hidden" name="save_telegram" value="1">
                <div class="form-group">
                    <label>Токен бота (из BotFather)</label>
                    <input type="text" name="tg_bot" placeholder="123456:ABC-DEF..." value="<?= htmlspecialchars($tg_bot) ?>">
                </div>
                <div class="form-group">
                    <label>ID Чата / Группы</label>
                    <input type="text" name="tg_chat" placeholder="-10012345678" value="<?= htmlspecialchars($tg_chat) ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: 5px;">Сохранить интеграцию</button>
            </form>
        </div>
    </div>

    <div class="section-wrap grid-2">
        <div class="card" style="background: #FAFAFA;">
            <h3>Создать доступ в CRM</h3>
            <form method="POST">
                <input type="hidden" name="add_user" value="1">
                <div class="form-group">
                    <label>Имя сотрудника</label>
                    <input type="text" name="user_name" placeholder="Например: Иван" required>
                </div>
                <div class="form-group">
                    <label>E-mail (Логин для входа)</label>
                    <input type="email" name="user_email" placeholder="ivan@mail.ru" required>
                </div>
                <div class="form-group">
                    <label>Временный пароль</label>
                    <input type="text" name="user_password" placeholder="Пароль" required>
                </div>
                <div class="form-group">
                    <label>Права доступа</label>
                    <select name="user_role" required>
                        <option value="admin">Администратор (Полный доступ)</option>
                        <option value="guide" selected>Гид (Ограниченный доступ)</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-top: 10px;">Создать аккаунт</button>
            </form>
        </div>
        
        <div class="card">
            <h3>Активные пользователи</h3>
            <div style="overflow-x: auto;">
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
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><span style="background: #EEF2FF; padding: 4px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; color: #4F46E5;"><?= $u['role'] === 'admin' ? 'Админ' : 'Гид' ?></span></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="openPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')">Пароль</button>
                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                    <a href="?del_user=<?= $u['id'] ?>" class="btn-del" style="margin-left: 5px; width: 26px; height: 26px; font-size: 12px;" onclick="return confirm('Удалить пользователя?');">✕</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="passwordModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(2px);">
    <div style="background:white; padding:30px; border-radius:12px; max-width:400px; width:90%; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <h3 style="margin-top:0; color: var(--text-main); margin-bottom: 5px; font-size: 20px;">Смена пароля</h3>
        <p style="font-size:14px; color:var(--text-muted); margin-bottom: 25px;">Для пользователя: <strong id="modalUserName" style="color:var(--primary);"></strong></p>
        <form method="POST" style="margin: 0;">
            <input type="hidden" name="change_password" value="1">
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="form-group">
                <input type="text" name="new_password" placeholder="Введите новый пароль" required style="width: 100%; box-sizing: border-box;">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; margin-top: 10px;">Сохранить пароль</button>
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('passwordModal').style.display='none'" style="width:100%; margin-top:10px;">Отмена</button>
        </form>
    </div>
</div>

<script>
    function openPasswordModal(id, name) {
        document.getElementById('modalUserId').value = id;
        document.getElementById('modalUserName').textContent = name;
        document.getElementById('passwordModal').style.display = 'flex';
    }

    function copySyncLink(link, who) {
        navigator.clipboard.writeText(link).then(() => {
            alert(`Ссылка для [${who}] скопирована!\n\n📱 iPhone: Настройки -> Календарь -> Учетные записи -> Добавить -> Другое -> Подписной календарь -> Вставить ссылку.\n🤖 Android: calendar.google.com -> "+" Другие календари -> По URL -> Вставить.`);
        }).catch(err => {
            alert('Скопируйте ссылку вручную:\n\n' + link);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
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
    });
</script>

</body>
</html>