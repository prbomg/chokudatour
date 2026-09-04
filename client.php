<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';
require_once __DIR__ . '/participant_seats.php';

if ($current_user_role !== 'admin') {
    die("<h2 style='text-align:center; margin-top:50px;'>Доступ закрыт.</h2>");
}

$phone = $_GET['phone'] ?? '';
if (!$phone) { header("Location: clients.php"); exit; }

$part_cols = $pdo->query("SHOW COLUMNS FROM participants")->fetchAll(PDO::FETCH_COLUMN);
$name_col = in_array('client_name', $part_cols) ? 'client_name' : 'name';

// --- ИНИЦИАЛИЗАЦИЯ БАЗЫ ДАННЫХ ДЛЯ ТЕГОВ ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_profiles (phone VARCHAR(50) PRIMARY KEY, tags VARCHAR(255) DEFAULT '', global_note TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS global_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("INSERT IGNORE INTO global_settings (setting_key, setting_value) VALUES ('client_tags', 'VIP,Лояльный,Семья с детьми,Сложный клиент,Черный список')");
} catch(PDOException $e) {}

function getTagStyle($tagName) {
    $hash = crc32($tagName);
    $hue = abs($hash) % 360;
    return "background: hsl({$hue}, 80%, 94%); color: hsl({$hue}, 85%, 28%); border: 1px solid transparent;";
}

// --- ПЕРЕИМЕНОВАНИЕ ТЕГА ГЛОБАЛЬНО ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_tag'])) {
    $old_tag = trim($_POST['old_tag'] ?? '');
    $new_tag = trim($_POST['new_tag'] ?? '');
    if ($old_tag && $new_tag && $old_tag !== $new_tag) {
        
        // 1. Меняем в глобальном справочнике
        $tags_str = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'client_tags'")->fetchColumn();
        $tags_arr = $tags_str ? explode(',', $tags_str) : [];
        $idx = array_search($old_tag, $tags_arr);
        if ($idx !== false) {
            $tags_arr[$idx] = $new_tag;
            $pdo->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = 'client_tags'")->execute([implode(',', array_unique($tags_arr))]);
        }
        
        // 2. Меняем в профилях всех клиентов
        $stmt = $pdo->prepare("SELECT phone, tags FROM client_profiles WHERE tags LIKE ?");
        $stmt->execute(["%" . $old_tag . "%"]);
        foreach ($stmt->fetchAll() as $p) {
            $tags_arr_c = array_map('trim', explode(',', $p['tags']));
            $idx_c = array_search($old_tag, $tags_arr_c);
            if ($idx_c !== false) {
                $tags_arr_c[$idx_c] = $new_tag;
                $pdo->prepare("UPDATE client_profiles SET tags = ? WHERE phone = ?")->execute([implode(',', array_unique(array_filter($tags_arr_c))), $p['phone']]);
            }
        }
        header("Location: client.php?phone=" . urlencode($phone) . "&msg=tag_renamed");
        exit;
    }
}

// --- УДАЛЕНИЕ ТЕГА ГЛОБАЛЬНО ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_tag'])) {
    $tag_to_delete = trim($_POST['delete_tag_name'] ?? '');
    if ($tag_to_delete) {
        
        // 1. Удаляем из справочника
        $tags_str = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'client_tags'")->fetchColumn();
        $tags_arr = $tags_str ? explode(',', $tags_str) : [];
        $tags_arr = array_filter($tags_arr, function($t) use ($tag_to_delete) { return $t !== $tag_to_delete; });
        $pdo->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = 'client_tags'")->execute([implode(',', $tags_arr)]);
        
        // 2. Удаляем у всех клиентов
        $stmt = $pdo->prepare("SELECT phone, tags FROM client_profiles WHERE tags LIKE ?");
        $stmt->execute(["%" . $tag_to_delete . "%"]);
        foreach ($stmt->fetchAll() as $p) {
            $tags_arr_c = array_map('trim', explode(',', $p['tags']));
            $tags_arr_c = array_filter($tags_arr_c, function($t) use ($tag_to_delete) { return $t !== $tag_to_delete; });
            $pdo->prepare("UPDATE client_profiles SET tags = ? WHERE phone = ?")->execute([implode(',', $tags_arr_c), $p['phone']]);
        }
        header("Location: client.php?phone=" . urlencode($phone) . "&msg=tag_deleted");
        exit;
    }
}

// --- СОХРАНЕНИЕ ПРОФИЛЯ ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $posted_tags = $_POST['tags'] ?? [];
    $custom_tag = trim($_POST['custom_tag'] ?? '');
    
    // Если вписали новый тег - добавляем его клиенту и в глобальный справочник
    if ($custom_tag !== '') {
        $posted_tags[] = $custom_tag;
        
        $tags_str = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'client_tags'")->fetchColumn();
        $tags_arr = $tags_str ? explode(',', $tags_str) : [];
        if (!in_array($custom_tag, $tags_arr)) {
            $tags_arr[] = $custom_tag;
            $pdo->prepare("UPDATE global_settings SET setting_value = ? WHERE setting_key = 'client_tags'")->execute([implode(',', array_unique($tags_arr))]);
        }
    }

    $clean_tags = array_unique(array_filter(array_map('trim', $posted_tags)));
    $tags_str = implode(',', $clean_tags);
    $global_note = trim($_POST['global_note'] ?? '');

    $pdo->prepare("INSERT INTO client_profiles (phone, tags, global_note) VALUES (?, ?, ?) 
                   ON DUPLICATE KEY UPDATE tags = VALUES(tags), global_note = VALUES(global_note)")
        ->execute([$phone, $tags_str, $global_note]);
    
    header("Location: client.php?phone=" . urlencode($phone) . "&msg=saved");
    exit;
}

// Получаем профиль клиента
$stmt_prof = $pdo->prepare("SELECT * FROM client_profiles WHERE phone = ?");
$stmt_prof->execute([$phone]);
$profile = $stmt_prof->fetch(PDO::FETCH_ASSOC) ?: ['tags' => '', 'global_note' => ''];
$current_tags = array_filter(array_map('trim', explode(',', $profile['tags'])));

// Получаем глобальный список тегов
$global_tags_str = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'client_tags'")->fetchColumn();
$all_existing_tags = $global_tags_str ? explode(',', $global_tags_str) : [];

// История поездок и сбор почты
$sql = "SELECT p.*, e.tour_date, t.name as tour_name 
        FROM participants p 
        JOIN events e ON p.event_id = e.id 
        JOIN tours_catalog t ON e.tour_id = t.id 
        WHERE p.phone = ? 
        ORDER BY e.tour_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$phone]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$client_name = $history[0][$name_col] ?? 'Без имени';
$client_email = '';
$trips_count = count($history);
$ltv = 0;

foreach ($history as $h) {
    if ($h['status'] !== 'Отмена') $ltv += $h['price'];
    if (empty($client_email) && !empty($h['email'])) $client_email = $h['email'];
}

$clean_phone = preg_replace('/[^0-9]/', '', $phone);
if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }

function getStatusColor($status) {
    switch($status) {
        case 'Оплачено': return 'background:#D1FAE5; color:#047857;';
        case 'Предоплата': return 'background:#DBEAFE; color:#1D4ED8;';
        case 'Бронь': return 'background:#FEF3C7; color:#B45309;';
        case 'Отмена': return 'background:#FEE2E2; color:#B91C1C; text-decoration:line-through;';
        default: return 'background:#F1F5F9; color:#475569;';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($client_name) ?> — Профиль клиента</title>
    <style>
        :root { --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF; --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; --text-main: #0F172A; --text-muted: #64748B; --radius-lg: 12px; --radius-md: 8px; --radius-sm: 6px; --shadow-xs: 0 1px 2px rgba(0,0,0,0.05); --shadow-sm: 0 4px 6px -1px rgba(0,0,0,0.05); --transition: all 0.2s ease;}
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; box-sizing: border-box; }
        
        .navbar { display: flex; gap: 12px; margin-bottom: 24px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 12px 20px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-xs); }
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; font-size: 14px; padding: 8px 14px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; }

        .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 700; margin-bottom: 20px; padding: 6px 14px; background: var(--primary-light); border-radius: 99px; transition: var(--transition);}
        .back-link:hover { background: #E0E7FF; transform: translateX(-2px); }

        .profile-header { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 30px; box-shadow: var(--shadow-sm); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 20px;}
        .ph-info h1 { margin: 0 0 10px 0; font-size: 32px; font-weight: 900; letter-spacing: -0.02em; }
        .ph-contacts { font-size: 15px; color: var(--text-muted); font-weight: 600; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;}
        .contact-item { display: flex; align-items: center; gap: 6px; }
        
        .action-buttons { margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap; }
        .btn-act { padding: 8px 16px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: var(--transition); box-shadow: var(--shadow-xs);}
        .btn-act:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }

        .ph-stats { display: flex; gap: 20px; }
        .stat-box { background: #F8FAFC; padding: 15px 20px; border-radius: var(--radius-md); border: 1px solid var(--border); text-align: center; min-width: 120px;}
        .stat-val { font-size: 24px; font-weight: 900; color: var(--text-main); margin-bottom: 4px;}
        .stat-val.green { color: #10B981; }
        .stat-label { font-size: 11px; text-transform: uppercase; font-weight: 800; color: var(--text-muted); letter-spacing: 0.05em;}

        .grid-layout { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; align-items: start; }
        
        .card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 25px; box-shadow: var(--shadow-sm); }
        .card h3 { margin: 0 0 20px 0; font-size: 18px; font-weight: 800; border-bottom: 2px solid #F1F5F9; padding-bottom: 10px;}

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 10px;}
        
        /* Управление тегами (с кнопками) */
        .tags-selector { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;}
        .tag-wrap { display: inline-flex; align-items: stretch; background: #fff; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; box-shadow: var(--shadow-xs); transition: var(--transition);}
        .tag-wrap:hover { border-color: var(--primary); box-shadow: var(--shadow-sm); }
        .tag-wrap:has(input:checked) { border-color: var(--primary); }
        
        .tag-wrap input[type="checkbox"] { display: none; }
        .tag-lbl { padding: 8px 12px; font-size: 13px; font-weight: 800; cursor: pointer; transition: var(--transition); opacity: 0.4; filter: grayscale(100%); user-select: none; margin: 0; display: flex; align-items: center;}
        .tag-wrap input[type="checkbox"]:checked + .tag-lbl { opacity: 1; filter: grayscale(0%); }

        .tag-actions { display: flex; align-items: center; background: #F8FAFC; border-left: 1px solid var(--border); padding: 0 4px;}
        .btn-tag-edit { background: transparent; border: none; cursor: pointer; font-size: 13px; padding: 6px 4px; border-radius: 4px; display: flex; align-items: center; justify-content: center; opacity: 0.5; transition: var(--transition);}
        .btn-tag-edit:hover { opacity: 1; background: rgba(0,0,0,0.05); }

        .t-input { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; background: #F8FAFC; outline: none; box-sizing: border-box; }
        .t-input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

        .t-textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; background: #F8FAFC; font-family: inherit; resize: vertical; outline: none; box-sizing: border-box;}
        .t-textarea:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px var(--primary-light);}

        .btn-save { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: var(--radius-sm); font-weight: 700; font-size: 14px; cursor: pointer; width: 100%; transition: var(--transition);}
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-1px);}

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; font-size: 14px; border-bottom: 1px solid #F1F5F9; text-align: left; }
        th { font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); background: #F8FAFC; }
        tr:hover td { background-color: #F8FAFC; }
        .tour-link { font-weight: 800; color: var(--text-main); text-decoration: none;}
        .tour-link:hover { color: var(--primary); text-decoration: underline;}
        .status-badge { padding: 4px 10px; border-radius: 99px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em;}

        @media (max-width: 992px) {
            .grid-layout { grid-template-columns: 1fr; }
            .profile-header { flex-direction: column; }
            .ph-stats { width: 100%; }
            .stat-box { flex: 1; }
        }
    </style>
</head>
<body>

<form id="tagManageForm" method="POST" style="display:none;">
    <input type="hidden" name="rename_tag" id="rt_flag">
    <input type="hidden" name="old_tag" id="rt_old">
    <input type="hidden" name="new_tag" id="rt_new">
    
    <input type="hidden" name="delete_tag" id="dt_flag">
    <input type="hidden" name="delete_tag_name" id="dt_name">
</form>

<div class="container">
    <?php include 'navbar.php'; ?>

    <a href="clients.php" class="back-link">← Вернуться к базе клиентов</a>

    <div class="profile-header">
        <div class="ph-info">
            <h1><?= htmlspecialchars($client_name) ?></h1>
            <div class="ph-contacts">
                <span class="contact-item">📞 <?= htmlspecialchars($phone) ?></span>
                <?php if ($client_email): ?>
                    <span style="color:var(--border);">|</span>
                    <span class="contact-item">✉️ <?= htmlspecialchars($client_email) ?></span>
                <?php endif; ?>
            </div>
            
            <div class="action-buttons">
                <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn-act" style="background:#F1F5F9; color:var(--text-main);">Позвонить</a>
                <a href="https://wa.me/<?= $clean_phone ?>" target="_blank" class="btn-act" style="background:#ECFDF5; color:#059669;">WhatsApp</a>
                <a href="https://t.me/+<?= $clean_phone ?>" target="_blank" class="btn-act" style="background:#EFF6FF; color:#2563EB;">Telegram</a>
                <?php if ($client_email): ?>
                    <a href="mailto:<?= htmlspecialchars($client_email) ?>" class="btn-act" style="background:#FEF2F2; color:#DC2626;">Email</a>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="ph-stats">
            <div class="stat-box">
                <div class="stat-val"><?= $trips_count ?></div>
                <div class="stat-label">Всего поездок</div>
            </div>
            <div class="stat-box">
                <div class="stat-val green"><?= number_format($ltv, 0, '', ' ') ?> ₽</div>
                <div class="stat-label">Принес(ла) денег</div>
            </div>
        </div>
    </div>

    <div class="grid-layout">
        <div class="card">
            <h3>Управление профилем</h3>
            <form method="POST">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-group">
                    <label>Теги клиента</label>
                    <div class="tags-selector">
                        <?php foreach($all_existing_tags as $tag): 
                            $checked = in_array($tag, $current_tags) ? 'checked' : '';
                            $style = getTagStyle($tag);
                        ?>
                            <div class="tag-wrap">
                                <label style="margin:0; display:flex;">
                                    <input type="checkbox" name="tags[]" value="<?= htmlspecialchars($tag) ?>" <?= $checked ?>>
                                    <div class="tag-lbl" style="<?= $style ?>"><?= htmlspecialchars($tag) ?></div>
                                </label>
                                <div class="tag-actions">
                                    <button type="button" class="btn-tag-edit" onclick="renameTag('<?= htmlspecialchars(addslashes($tag)) ?>')" title="Переименовать">✏️</button>
                                    <button type="button" class="btn-tag-edit" onclick="delTag('<?= htmlspecialchars(addslashes($tag)) ?>')" title="Удалить из всей базы">❌</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>➕ Создать новый тег</label>
                    <input type="text" name="custom_tag" class="t-input" placeholder="Например: Из Москвы, Школьники...">
                </div>

                <div class="form-group">
                    <label>Глобальная заметка (Видна всегда)</label>
                    <textarea name="global_note" class="t-textarea" rows="4" placeholder="Например: Любит сидеть спереди, аллергия на орехи..."><?= htmlspecialchars($profile['global_note']) ?></textarea>
                </div>

                <button type="submit" class="btn-save">💾 Сохранить изменения</button>
                
                <?php 
                $msg = $_GET['msg'] ?? '';
                if ($msg === 'saved') echo '<div style="text-align:center; color:#10B981; font-weight:700; font-size:13px; margin-top:10px;">Профиль обновлен!</div>';
                if ($msg === 'tag_renamed') echo '<div style="text-align:center; color:#3B82F6; font-weight:700; font-size:13px; margin-top:10px;">Тег переименован у всех!</div>';
                if ($msg === 'tag_deleted') echo '<div style="text-align:center; color:#EF4444; font-weight:700; font-size:13px; margin-top:10px;">Тег удален из базы!</div>';
                ?>
            </form>
        </div>

        <div class="card" style="padding: 0;">
            <h3 style="padding: 25px 25px 0 25px;">История поездок</h3>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Экскурсия</th>
                            <th>Мест</th>
                            <th>Сумма</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($history as $h): ?>
                            <tr>
                                <td style="white-space:nowrap; font-weight:600; color:var(--text-muted);"><?= date('d.m.Y', strtotime($h['tour_date'])) ?></td>
                                <td><a href="event.php?id=<?= $h['event_id'] ?>" class="tour-link"><?= htmlspecialchars($h['tour_name']) ?></a></td>
                                <td><span style="font-weight:800;"><?= participantSeats($h) ?></span></td>
                                <td style="font-weight:700; color:var(--text-main);"><?= number_format($h['price'], 0, '', ' ') ?> ₽</td>
                                <td><span class="status-badge" style="<?= getStatusColor($h['status']) ?>"><?= htmlspecialchars($h['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    function renameTag(oldName) {
        let newName = prompt("Введите новое название для тега '" + oldName + "':\nЭто изменит тег у ВСЕХ клиентов.", oldName);
        if (newName && newName.trim() !== "" && newName !== oldName) {
            document.getElementById('rt_old').value = oldName;
            document.getElementById('rt_new').value = newName;
            document.getElementById('rt_flag').value = "1";
            document.getElementById('tagManageForm').submit();
        }
    }

    function delTag(tagName) {
        if (confirm("Вы уверены, что хотите навсегда УДАЛИТЬ тег '" + tagName + "'?\nОн исчезнет из профилей ВСЕХ клиентов.")) {
            document.getElementById('dt_name').value = tagName;
            document.getElementById('dt_flag').value = "1";
            document.getElementById('tagManageForm').submit();
        }
    }
</script>

</body>
</html>