<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') {
    die("Доступ закрыт.");
}

// Функция для уникального цвета источника
function getSourceColor($name) {
    $colors = ['#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#F43F5E', '#06B6D4', '#84CC16'];
    $hash = crc32($name);
    return $colors[abs($hash) % count($colors)];
}

// --- АВТО-ОБНОВЛЕНИЕ БАЗЫ ДАННЫХ ---
$pdo->exec("CREATE TABLE IF NOT EXISTS tours_catalog (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, public_name VARCHAR(255) DEFAULT NULL, duration VARCHAR(100) DEFAULT NULL, coordinates VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 0)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tour_modules (id INT AUTO_INCREMENT PRIMARY KEY, tour_id INT NOT NULL, title VARCHAR(255) NOT NULL, timing VARCHAR(255) DEFAULT NULL, content TEXT DEFAULT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 999)");

$columns = [
    'included_text' => 'TEXT DEFAULT NULL', 'not_included_text' => 'TEXT DEFAULT NULL', 
    'faq_text' => 'TEXT DEFAULT NULL', 'food_options' => 'TEXT DEFAULT NULL', 
    'program' => 'TEXT DEFAULT NULL', 'main_image' => 'VARCHAR(255) DEFAULT NULL',
    'prices' => 'TEXT DEFAULT NULL', 'description' => 'TEXT DEFAULT NULL',
    'default_start_time' => "VARCHAR(50) DEFAULT '10:00'",
    'is_archived' => "TINYINT(1) DEFAULT 0" // НОВАЯ КОЛОНКА ДЛЯ АРХИВА
];
foreach ($columns as $col => $type) {
    try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN $col $type"); } catch(PDOException $e) {}
}

// --- БЫСТРОЕ СОЗДАНИЕ ПУСТОГО ТУРА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_new_tour'])) {
    $pdo->prepare("INSERT INTO tours_catalog (name, sort_order) VALUES ('Новый маршрут', 999)")->execute();
    $new_id = $pdo->lastInsertId();
    header("Location: tour_builder.php?id=" . $new_id);
    exit;
}

// --- ДУБЛИРОВАНИЕ ТУРА ---
if (isset($_GET['duplicate_tour'])) {
    $id_to_copy = (int)$_GET['duplicate_tour'];
    $stmt = $pdo->prepare("SELECT * FROM tours_catalog WHERE id = ?");
    $stmt->execute([$id_to_copy]);
    $tour = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tour) {
        $newName = $tour['name'] . ' (Копия)';
        
        $pdo->prepare("INSERT INTO tours_catalog 
            (name, public_name, duration, default_start_time, coordinates, sort_order, description, food_options, program, prices, main_image, included_text, not_included_text, faq_text) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([
            $newName, 
            $tour['public_name'] ?? '', $tour['duration'] ?? '', $tour['default_start_time'] ?? '10:00', $tour['coordinates'] ?? '', $tour['sort_order'] ?? 0, 
            $tour['description'] ?? '', $tour['food_options'] ?? '', $tour['program'] ?? '', 
            $tour['prices'] ?? '', $tour['main_image'] ?? '', $tour['included_text'] ?? '', $tour['not_included_text'] ?? '', $tour['faq_text'] ?? ''
        ]);
        
        $new_tour_id = $pdo->lastInsertId();

        // Копируем модули
        $stmt_mod = $pdo->prepare("SELECT * FROM tour_modules WHERE tour_id = ?");
        $stmt_mod->execute([$id_to_copy]);
        foreach($stmt_mod->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $pdo->prepare("INSERT INTO tour_modules (tour_id, title, timing, content, image_path, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$new_tour_id, $m['title'], $m['timing'], $m['content'], $m['image_path'], $m['sort_order']]);
        }

        header("Location: tours.php?msg=tour_duplicated"); exit;
    }
}

// --- АРХИВАЦИЯ ТУРА (Soft Delete) ---
if (isset($_GET['archive_tour'])) {
    $id = (int)$_GET['archive_tour'];
    $pdo->prepare("UPDATE tours_catalog SET is_archived = 1 WHERE id = ?")->execute([$id]);
    header("Location: tours.php?msg=tour_archived"); exit;
}

// --- ВОССТАНОВЛЕНИЕ ТУРА ---
if (isset($_GET['restore_tour'])) {
    $id = (int)$_GET['restore_tour'];
    $pdo->prepare("UPDATE tours_catalog SET is_archived = 0 WHERE id = ?")->execute([$id]);
    header("Location: tours.php?show_archive=1&msg=tour_restored"); exit;
}

// --- УДАЛЕНИЕ ТУРА НАВСЕГДА ---
if (isset($_GET['del_tour'])) {
    $id = (int)$_GET['del_tour'];
    $check = $pdo->prepare("SELECT COUNT(*) FROM events WHERE tour_id = ?");
    $check->execute([$id]);
    
    if ($check->fetchColumn() > 0) {
        header("Location: tours.php?show_archive=1&msg=cannot_delete"); exit;
    } else {
        $pdo->prepare("DELETE FROM tours_catalog WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM tour_modules WHERE tour_id = ?")->execute([$id]);
        header("Location: tours.php?show_archive=1&msg=tour_deleted"); exit;
    }
}

// --- СОРТИРОВКА AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_sort') {
    $order = $_POST['order'] ?? [];
    foreach ($order as $index => $id) {
        $pdo->prepare("UPDATE tours_catalog SET sort_order = ? WHERE id = ?")->execute([$index, (int)$id]);
    }
    echo json_encode(['status' => 'success']); exit;
}

// Получаем каталог туров (с учетом архива)
$show_archive = isset($_GET['show_archive']) ? 1 : 0;
$stmt_tours = $pdo->prepare("SELECT * FROM tours_catalog WHERE is_archived = ? ORDER BY sort_order ASC, id DESC");
$stmt_tours->execute([$show_archive]);
$tours = $stmt_tours->fetchAll(PDO::FETCH_ASSOC);

// Источники продаж для красивого вывода цен
$sources_raw = $pdo->query("SELECT id, name FROM booking_sources")->fetchAll(PDO::FETCH_ASSOC);
$sources_map = [];
foreach($sources_raw as $s) $sources_map[$s['id']] = $s['name'];

$stats_stmt = $pdo->query("SELECT tour_id, COUNT(*) as cnt FROM events GROUP BY tour_id");
$tour_stats = [];
while ($row = $stats_stmt->fetch(PDO::FETCH_ASSOC)) {
    $tour_stats[$row['tour_id']] = (int)$row['cnt'];
}

$guides = $pdo->query("SELECT id, name, allowed_tours FROM guides ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

function getGuideColor($guideName) {
    if (empty($guideName) || $guideName === 'Не назначен') return "hsl(215, 16%, 80%)";
    $hash = substr(md5($guideName), 0, 6);
    $hue = hexdec($hash) % 360; 
    return "hsl({$hue}, 70%, 45%)"; 
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Каталог туров — CRM</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        :root { --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF; --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; --text-main: #0F172A; --text-muted: #64748B; --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px; --shadow-sm: 0 1px 3px rgba(0,0,0,0.05); --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05); --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; }
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box;}
        
        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;}
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); }
        
        .top-controls { display: flex; gap: 15px; align-items: center; justify-content: space-between; flex-wrap: wrap; width: 100%; margin-bottom: 25px;}
        .search-input { width: 300px; padding: 12px 18px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; background: var(--card-bg); outline: none; font-weight: 600; box-shadow: var(--shadow-sm);}
        .search-input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

        .tabs-wrap { display: flex; gap: 10px; background: #F1F5F9; padding: 4px; border-radius: var(--radius-sm); width: max-content;}
        .tab { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 700; text-decoration: none; color: var(--text-muted); transition: var(--transition);}
        .tab.active { background: white; color: var(--text-main); box-shadow: var(--shadow-sm);}

        .btn-create { background: var(--text-main); color: white; padding: 12px 24px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; transition: var(--transition); box-shadow: 0 4px 10px rgba(0,0,0,0.15); display: inline-flex; align-items: center; gap: 8px;}
        .btn-create:hover { background: #1F2937; transform: translateY(-1px); box-shadow: 0 6px 15px rgba(0,0,0,0.2);}

        .tours-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 25px; }
        .tour-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm); transition: var(--transition); display: flex; flex-direction: column; position: relative;}
        .tour-card:hover { transform: translateY(-4px); border-color: #CBD5E1; box-shadow: var(--shadow-md);}
        
        .tour-card.sortable-ghost { opacity: 0.4; border: 2px dashed var(--primary); background: var(--primary-light); }
        .tour-card.sortable-drag { transition: none !important; cursor: grabbing !important; box-shadow: 0 15px 30px rgba(0,0,0,0.15); z-index: 999; }

        .tour-cover-wrap { width: 100%; aspect-ratio: 16/9; background: #E2E8F0; position: relative; overflow: hidden;}
        .tour-cover { width: 100%; height: 100%; object-fit: cover; object-position: center; transition: var(--transition); pointer-events: none;}
        .tour-card:hover .tour-cover { transform: scale(1.03); }
        .tour-cover-empty { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 13px; font-weight: 600; background: #F1F5F9;}
        
        .tour-badge-order { position: absolute; top: 12px; left: 12px; background: var(--primary); color: white; font-size: 13px; font-weight: 900; padding: 4px 12px; border-radius: 6px; z-index: 2; box-shadow: 0 2px 6px rgba(79, 70, 229, 0.4); letter-spacing: 0.05em;}
        .tour-badge-type { position: absolute; bottom: 12px; left: 12px; background: rgba(15,23,42,0.7); color: white; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 99px; z-index: 2; backdrop-filter: blur(4px);}
        
        .drag-handle { position: absolute; top: 12px; right: 12px; background: rgba(255,255,255,0.9); backdrop-filter: blur(4px); border-radius: 6px; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; color: var(--text-main); cursor: grab; z-index: 10; box-shadow: 0 2px 5px rgba(0,0,0,0.1);}
        .drag-handle:active { cursor: grabbing; background: var(--primary); color: white;}

        .tour-body { padding: 25px 20px 20px 20px; flex: 1; display: flex; flex-direction: column; position: relative;}
        .tour-name { font-size: 18px; font-weight: 800; color: var(--text-main); margin: 0 0 4px 0; line-height: 1.3;}
        .tour-public { font-size: 13px; color: var(--text-muted); margin-bottom: 12px; font-weight: 500;}
        
        .tour-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 15px; font-size: 12px; color: var(--text-muted); font-weight: 600;}
        .meta-item { display: flex; align-items: center; gap: 4px; background: #F8FAFC; padding: 4px 8px; border-radius: 6px; border: 1px solid #E2E8F0;}
        .meta-item.id-tag { background: transparent; border-color: transparent; padding: 0; color: #94A3B8; font-weight: 500; }

        /* ДИНАМИЧЕСКИЕ ЦЕНЫ ИЗ БАЗЫ */
        .tour-prices { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; margin-bottom: 15px; background: #F8FAFC; padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--border);}
        .t-price-item { font-size: 12px; font-weight: 700; display: flex; justify-content: space-between;}

        .tour-guides-wrap { margin-top: auto; margin-bottom: 15px; border-top: 1px dashed var(--border); padding-top: 12px;}
        .guides-title { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px;}
        .guides-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .guide-chip { padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; color: white;}
        .guide-chip.none { background: #F1F5F9; color: var(--text-muted); }

        .tour-actions { display: flex; justify-content: flex-end; gap: 8px; border-top: 1px solid #F1F5F9; padding-top: 15px;}
        .btn-icon-action { width: 36px; height: 36px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; text-decoration: none; transition: var(--transition); border: none; cursor: pointer;}
        
        .btn-link { background: #ECFDF5; color: #047857; } .btn-link:hover { background: #D1FAE5; transform: translateY(-2px); }
        .btn-wp { background: #F1F5F9; color: #475569; } .btn-wp:hover { background: #E2E8F0; transform: translateY(-2px); color: #0F172A;}
        .btn-prev { background: #F3F4F6; color: #4B5563; } .btn-prev:hover { background: #E5E7EB; transform: translateY(-2px); color: #111827; }
        .btn-edit { background: var(--primary-light); color: var(--primary); } .btn-edit:hover { background: #E0E7FF; transform: translateY(-2px); }
        .btn-dup { background: #F3F4F6; color: #4B5563; } .btn-dup:hover { background: #E5E7EB; transform: translateY(-2px); color: #111827; }
        .btn-del { background: #FEF2F2; color: #EF4444; } .btn-del:hover { background: #FEE2E2; transform: translateY(-2px); color: #DC2626; }
        
        .btn-restore { background: #ECFDF5; color: #047857; width: 100%; border-radius: var(--radius-sm); font-weight: 700; text-decoration: none; padding: 10px; text-align: center; border: 1px solid #A7F3D0; transition: var(--transition); display: block;}
        .btn-restore:hover { background: #D1FAE5; transform: translateY(-2px);}

        .preview-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.8); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(8px); padding: 20px; box-sizing: border-box; opacity: 0; transition: opacity 0.3s ease;}
        .preview-overlay.show { opacity: 1; }
        .preview-modal { background: #F8FAFC; width: 100%; max-width: 480px; height: 90vh; border-radius: 30px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); overflow: hidden; display: flex; flex-direction: column; position: relative; transform: translateY(20px); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);}
        .preview-overlay.show .preview-modal { transform: translateY(0); }
        .preview-content { flex: 1; overflow-y: auto; padding: 0; background: #fff;}
        .prev-cover { width: 100%; height: 250px; object-fit: cover; background: #E2E8F0; }
        .prev-body { padding: 25px; }
        .prev-tag { display: inline-block; background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 800; margin-bottom: 10px; }
        .prev-title { font-size: 24px; font-weight: 900; color: var(--text-main); margin: 0 0 15px 0; line-height: 1.2;}
        .prev-desc { font-size: 15px; color: var(--text-muted); line-height: 1.6; margin-bottom: 25px;}
        .close-preview { position: absolute; top: 15px; right: 15px; width: 36px; height: 36px; background: rgba(0,0,0,0.5); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; backdrop-filter: blur(4px); font-weight: bold; border: none; z-index: 10; transition: var(--transition);}
        .close-preview:hover { background: #000; transform: scale(1.1); }

        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%); transition: all 0.3s ease; display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }
        .toast.warning { background: #F59E0B; }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>Каталог туров</h2>
        <form method="POST">
            <input type="hidden" name="create_new_tour" value="1">
            <button type="submit" class="btn-create">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Создать маршрут
            </button>
        </form>
    </div>

    <div class="top-controls">
        <div class="tabs-wrap">
            <a href="tours.php" class="tab <?= !$show_archive ? 'active' : '' ?>">🚀 Активные туры</a>
            <a href="tours.php?show_archive=1" class="tab <?= $show_archive ? 'active' : '' ?>">📦 Архив (<?= $pdo->query("SELECT COUNT(*) FROM tours_catalog WHERE is_archived=1")->fetchColumn() ?>)</a>
        </div>
        <input type="text" id="searchInput" class="search-input" placeholder="🔍 Быстрый поиск..." onkeyup="filterTours()">
    </div>

    <div class="tours-grid" id="toursList">
        <?php if(empty($tours)): ?>
            <div style="grid-column: 1/-1; text-align:center; padding: 50px; color:var(--text-muted); font-weight:600;">В этом разделе пока пусто.</div>
        <?php endif; ?>

        <?php foreach ($tours as $index => $t): 
            $t_id = $t['id'];
            $count_planned = $tour_stats[$t_id] ?? 0;

            $assigned_guides = [];
            foreach ($guides as $g) {
                if ($g['allowed_tours'] === 'all' || in_array((string)$t_id, explode(',', $g['allowed_tours']))) {
                    $assigned_guides[] = $g['name'];
                }
            }

            $mods_stmt = $pdo->prepare("SELECT * FROM tour_modules WHERE tour_id = ? ORDER BY sort_order ASC");
            $mods_stmt->execute([$t_id]);
            $t_modules = $mods_stmt->fetchAll(PDO::FETCH_ASSOC);

            // ДЕКОДИРУЕМ ДИНАМИЧЕСКИЕ ЦЕНЫ ИЗ БАЗЫ
            $saved_prices = json_decode($t['prices'] ?? '', true) ?: [];
            if (empty($saved_prices) && !empty($t['price_direct'])) {
                $saved_prices[-1] = $t['price_direct']; // fallback
            }

            $preview_prices = [];
            foreach($saved_prices as $s_id => $val) {
                $s_name = $sources_map[$s_id] ?? ($s_id == -1 ? 'Прямые' : 'Источник');
                $preview_prices[] = [
                    "name" => $s_name, 
                    "val" => $val, 
                    "color" => getSourceColor($s_name)
                ];
            }
        ?>
        <div class="tour-card" data-id="<?= $t_id ?>" data-name="<?= htmlspecialchars(strtolower($t['name'] . ' ' . ($t['public_name'] ?? ''))) ?>">
            
            <div class="tour-badge-order">#<?= $index + 1 ?></div>
            
            <div class="tour-cover-wrap">
                <?php if (!$show_archive): ?>
                <div class="drag-handle" title="Потяните для сортировки">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                </div>
                <?php endif; ?>

                <?php if (!empty($t['tour_type'])): ?>
                    <div class="tour-badge-type"><?= htmlspecialchars($t['tour_type']) ?></div>
                <?php endif; ?>

                <?php if (!empty($t['main_image']) && file_exists($t['main_image'])): ?>
                    <img src="<?= htmlspecialchars($t['main_image']) ?>" class="tour-cover" draggable="false">
                <?php else: ?>
                    <div class="tour-cover-empty">Нет обложки</div>
                <?php endif; ?>
            </div>

            <div class="tour-body">
                <h3 class="tour-name"><?= htmlspecialchars($t['name']) ?></h3>
                <div class="tour-public"><?= htmlspecialchars($t['public_name'] ?: 'Без публичного имени') ?></div>
                
                <div class="tour-meta">
                    <?php if (!empty($t['duration'])): ?>
                        <div class="meta-item">⏱ <?= htmlspecialchars($t['duration']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($t['default_start_time'])): ?>
                        <div class="meta-item">⏰ <?= htmlspecialchars($t['default_start_time']) ?></div>
                    <?php endif; ?>
                    <div class="meta-item" style="color: <?= $count_planned > 0 ? 'var(--primary)' : 'var(--text-muted)' ?>;">
                        📅 <?= $count_planned ?> экскурсий
                    </div>
                    <div class="meta-item id-tag">ID: <?= $t_id ?></div>
                </div>

                <div class="tour-prices">
                    <?php if(!empty($saved_prices)): ?>
                        <?php foreach($saved_prices as $s_id => $val): 
                            $s_name = $sources_map[$s_id] ?? ($s_id == -1 ? 'Прямые' : 'Удаленный источник');
                            $color = getSourceColor($s_name);
                        ?>
                        <div class="t-price-item" style="color: <?= $color ?>;">
                            <span><?= htmlspecialchars($s_name) ?>:</span> 
                            <span style="color:var(--text-main);"><?= number_format($val, 0, '', ' ') ?> ₽</span>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1/-1; color: var(--text-muted); font-size:12px; text-align:center; padding: 5px 0;">Цены не заданы</div>
                    <?php endif; ?>
                </div>

                <div class="tour-guides-wrap">
                    <div class="guides-title">Гиды направления:</div>
                    <div class="guides-chips">
                        <?php if (!empty($assigned_guides)): ?>
                            <?php foreach ($assigned_guides as $g_name): 
                                $g_color = getGuideColor($g_name);
                            ?>
                                <span class="guide-chip" style="background: <?= $g_color ?>;"><?= htmlspecialchars($g_name) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="guide-chip none">Не назначены</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tour-actions">
                    <?php if ($show_archive): ?>
                        <a href="?restore_tour=<?= $t_id ?>" class="btn-restore">Восстановить из архива</a>
                        <a href="?del_tour=<?= $t_id ?>" class="btn-icon-action btn-del" onclick="return confirm('Точно удалить тур НАВСЕГДА? Это действие нельзя отменить.');" title="Удалить навсегда">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn-icon-action btn-wp" onclick="showEmbedModal(<?= $t_id ?>)" title="Код календаря для вставки на сайт">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                        </button>

                        <a href="route.php?id=<?= $t_id ?>" target="_blank" class="btn-icon-action btn-link" title="Открыть маршрут (для клиента)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                        </a>

                        <button type="button" class="btn-icon-action btn-prev" onclick='openCardPreview(<?= json_encode([
                            "name" => $t['public_name'] ?: $t['name'],
                            "duration" => $t['duration'],
                            "time" => $t['default_start_time'] ?? '',
                            "prices" => $preview_prices,
                            "desc" => $t['description'],
                            "cover" => $t['main_image'],
                            "modules" => $t_modules
                        ], JSON_UNESCAPED_UNICODE) ?>)' title="Быстрый предпросмотр">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>

                        <a href="tour_builder.php?id=<?= $t_id ?>" class="btn-icon-action btn-edit" title="Редактировать в конструкторе">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </a>
                        
                        <a href="?duplicate_tour=<?= $t_id ?>" class="btn-icon-action btn-dup" title="Дублировать тур" onclick="return confirm('Создать точную копию этого тура?');">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        </a>

                        <a href="?archive_tour=<?= $t_id ?>" class="btn-icon-action btn-del" onclick="return confirm('Убрать тур в архив?');" title="В архив">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div id="previewModal" class="preview-overlay">
    <div class="preview-modal">
        <button class="close-preview" onclick="closeCardPreview()">✕</button>
        <div class="preview-content" id="previewModalBody"></div>
    </div>
</div>

<div id="embedModal" class="preview-overlay">
    <div class="preview-modal" style="height: auto; padding: 30px; max-width: 460px; border-radius: 20px;">
        <button class="close-preview" style="top: 15px; right: 15px; background: #F1F5F9; color: var(--text-muted);" onclick="closeEmbedModal()">✕</button>
        <h3 style="margin: 0 0 10px 0; font-size: 20px; font-weight: 800; color: var(--text-main);">Код календаря для сайта</h3>
        <p style="font-size: 13px; color: var(--text-muted); line-height: 1.5; margin-bottom: 20px;">
            Скопируйте этот HTML-код и вставьте его на страницу тура вашего основного сайта, чтобы выводить онлайн-календарь бронирования:
        </p>
        <textarea id="embedCodeText" style="width: 100%; height: 110px; padding: 12px; font-family: monospace; font-size: 12px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 15px; resize: none; background: #F8FAFC; color: var(--text-main); outline: none;" readonly></textarea>
        <button class="btn-create" style="width: 100%; justify-content: center; height: 44px;" onclick="copyEmbedCode()">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            Скопировать код
        </button>
    </div>
</div>

<script>
    function filterTours() {
        let val = document.getElementById('searchInput').value.toLowerCase();
        let cards = document.querySelectorAll('.tour-card');
        cards.forEach(card => {
            let name = card.getAttribute('data-name');
            if (name.includes(val)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // КРАСИВЫЙ ВЫВОД ЦЕН В ПРЕДПРОСМОТРЕ
    function openCardPreview(data) {
        let html = '';
        if(data.cover) html += `<img src="${data.cover}" class="prev-cover">`;
        html += `<div class="prev-body">`;
        if(data.duration) html += `<div class="prev-tag">⏱ ${data.duration}</div>`;
        if(data.time) html += `<div class="prev-tag" style="margin-left: 8px;">⏰ Старт: ${data.time}</div>`;
        html += `<h2 class="prev-title">${data.name}</h2>`;
        
        if(data.prices && data.prices.length > 0) {
            html += `<div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:20px;">`;
            data.prices.forEach(p => {
                html += `<div style="background:#F8FAFC; border:1px solid #E2E8F0; padding:8px 12px; border-radius:8px; display:flex; flex-direction:column; gap:4px; min-width:100px;">
                    <span style="font-size:11px; font-weight:800; text-transform:uppercase; color:${p.color}">${p.name}</span>
                    <span style="font-size:16px; font-weight:800; color:var(--text-main);">${new Intl.NumberFormat('ru-RU').format(p.val)} ₽</span>
                </div>`;
            });
            html += `</div>`;
        }

        if(data.desc) html += `<div class="prev-desc">${data.desc.replace(/\n/g, '<br>')}</div>`;

        if(data.modules && data.modules.length > 0) {
            html += `<h3 style="font-size:18px; font-weight:800; margin-bottom:20px;">Программа:</h3><div style="position:relative; padding-left:20px; border-left:2px solid #E2E8F0;">`;
            data.modules.forEach(m => {
                html += `<div style="margin-bottom:25px; position:relative;">`;
                html += `<div style="position:absolute; left:-27px; top:4px; width:12px; height:12px; border-radius:50%; background:var(--primary); border:2px solid #fff;"></div>`;
                if(m.timing) html += `<div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:4px;">⏱ ${m.timing}</div>`;
                html += `<h4 style="font-size:16px; font-weight:800; margin:0 0 6px 0;">${m.title}</h4>`;
                if(m.image_path) html += `<img src="${m.image_path}" style="width:100%; height:120px; object-fit:cover; border-radius:8px; margin-bottom:8px;">`;
                html += `<div style="font-size:14px; color:var(--text-muted);">${m.content}</div></div>`;
            });
            html += `</div>`;
        }
        html += `</div>`;

        document.getElementById('previewModalBody').innerHTML = html;
        let modal = document.getElementById('previewModal');
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('show'), 10);
        document.body.style.overflow = 'hidden';
    }

    function closeCardPreview() {
        let modal = document.getElementById('previewModal');
        modal.classList.remove('show');
        setTimeout(() => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }, 300);
    }

    function showEmbedModal(id) {
        const domain = window.location.origin; 
        const code = `<iframe src="${domain}/widget.php?tour_id=${id}" width="100%" height="1000" style="border: none; overflow: hidden; max-width: 600px; display: block; margin: 0 auto;" scrolling="no"></iframe>`;
        document.getElementById('embedCodeText').value = code;
        let modal = document.getElementById('embedModal');
        modal.style.display = 'flex';
        setTimeout(() => modal.classList.add('show'), 10);
    }

    function closeEmbedModal() {
        let modal = document.getElementById('embedModal');
        modal.classList.remove('show');
        setTimeout(() => modal.style.display = 'none', 300);
    }

    function copyEmbedCode() {
        let copyText = document.getElementById("embedCodeText");
        copyText.select();
        document.execCommand("copy");
        showToast('Код виджета скопирован!');
        closeEmbedModal();
    }

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

    document.addEventListener('DOMContentLoaded', () => {
        var list = document.getElementById('toursList');
        if (list && typeof Sortable !== 'undefined' && <?= $show_archive ? 'false' : 'true' ?>) {
            new Sortable(list, {
                animation: 150,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                onEnd: function () {
                    const formData = new FormData();
                    formData.append('action', 'update_sort');
                    Array.from(list.querySelectorAll('.tour-card')).forEach((item, index) => {
                        formData.append('order[]', item.getAttribute('data-id'));
                        let orderDiv = item.querySelector('.tour-badge-order');
                        if(orderDiv) orderDiv.innerText = '#' + (index + 1);
                    });

                    fetch('tours.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => { if (data.status === 'success') showToast('Порядок туров сохранен!'); });
                }
            });
        }

        const urlParams = new URLSearchParams(window.location.search);
        const msg = urlParams.get('msg');
        if (msg) {
            const messages = {
                'tour_deleted': 'Тур удален навсегда.',
                'cannot_delete': 'Нельзя удалить: по этому туру есть экскурсии!',
                'tour_duplicated': 'Тур успешно скопирован со всеми этапами!',
                'tour_archived': 'Тур перенесен в архив',
                'tour_restored': 'Тур восстановлен'
            };
            if (messages[msg]) showToast(messages[msg], msg === 'cannot_delete' ? 'error' : (msg === 'tour_archived' ? 'warning' : 'success'));
            window.history.replaceState({}, document.title, window.location.pathname + '<?= $show_archive ? "?show_archive=1" : "" ?>');
        }
    });
</script>

</body>
</html>