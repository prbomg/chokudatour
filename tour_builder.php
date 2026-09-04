<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') { die("<h2 style='text-align:center; margin-top:50px;'>Доступ закрыт.</h2>"); }

$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tour_id === 0) { header("Location: tours.php"); exit; }

function getSourceColor($name) {
    $colors = ['#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#F43F5E', '#06B6D4', '#84CC16'];
    $hash = crc32($name);
    return $colors[abs($hash) % count($colors)];
}

$pdo->exec("CREATE TABLE IF NOT EXISTS tour_modules (id INT AUTO_INCREMENT PRIMARY KEY, tour_id INT NOT NULL, title VARCHAR(255) NOT NULL, timing VARCHAR(255) DEFAULT NULL, content TEXT DEFAULT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 999)");

$columns = [
    'included_text' => 'TEXT DEFAULT NULL', 'not_included_text' => 'TEXT DEFAULT NULL', 
    'faq_text' => 'TEXT DEFAULT NULL', 'food_options' => 'TEXT DEFAULT NULL', 
    'program' => 'TEXT DEFAULT NULL', 'main_image' => 'VARCHAR(255) DEFAULT NULL',
    'prices' => 'TEXT DEFAULT NULL', 'description' => 'TEXT DEFAULT NULL',
    'default_start_time' => "VARCHAR(50) DEFAULT '10:00'",
    'difficulty' => "VARCHAR(255) DEFAULT 'Легкая'",
    'tour_type' => "VARCHAR(50) DEFAULT 'Индивидуальная'"
];
foreach ($columns as $col => $type) {
    try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN $col $type"); } catch(PDOException $e) {}
}

// --- ФУНКЦИЯ ДЛЯ СЖАТИЯ И КОНВЕРТАЦИИ В WebP ---
function optimizeImageToWebp($tmpName, $prefix) {
    $info = @getimagesize($tmpName);
    if (!$info) return false;
    
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': $img = imagecreatefromjpeg($tmpName); break;
        case 'image/png': $img = imagecreatefrompng($tmpName); break;
        case 'image/webp': $img = imagecreatefromwebp($tmpName); break;
        default: return false; 
    }
    
    $width = imagesx($img);
    $height = imagesy($img);
    $newWidth = $width;
    $newHeight = $height;
    
    if ($width > 1600) {
        $newWidth = 1600;
        $newHeight = floor($height * (1600 / $width));
    }
    
    $newImg = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($newImg, false);
    imagesavealpha($newImg, true);
    $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
    imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $transparent);
    
    imagecopyresampled($newImg, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    $filename = 'uploads/' . $prefix . '_' . time() . '.webp';
    
    imagewebp($newImg, $filename, 85);
    
    imagedestroy($img);
    imagedestroy($newImg);
    
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_module_ajax'])) {
    header('Content-Type: application/json');
    $module_id = (int)$_POST['module_id']; 
    $title = trim($_POST['title']); 
    $timing = trim($_POST['timing']); 
    $content = trim($_POST['content']);
    $image_path = '';
    
    if ($module_id > 0) { 
        $image_path = $pdo->query("SELECT image_path FROM tour_modules WHERE id = $module_id")->fetchColumn() ?: ''; 
    }
    
    // WebP оптимизация для фото этапа
    if (isset($_FILES['module_image']) && $_FILES['module_image']['error'] === UPLOAD_ERR_OK) {
        $optimized_path = optimizeImageToWebp($_FILES['module_image']['tmp_name'], 'mod_' . $tour_id);
        if ($optimized_path) {
            if ($image_path && file_exists($image_path)) { @unlink($image_path); }
            $image_path = $optimized_path;
        }
    }
    
    if ($title !== '') {
        if ($module_id > 0) { 
            $pdo->prepare("UPDATE tour_modules SET title=?, timing=?, content=?, image_path=? WHERE id=?")->execute([$title, $timing, $content, $image_path, $module_id]); 
        } else { 
            $pdo->prepare("INSERT INTO tour_modules (tour_id, title, timing, content, image_path) VALUES (?, ?, ?, ?, ?)")->execute([$tour_id, $title, $timing, $content, $image_path]); 
            $module_id = $pdo->lastInsertId();
        }
    }
    
    $saved_module = $pdo->query("SELECT * FROM tour_modules WHERE id = $module_id")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'module' => $saved_module]);
    exit;
}

if (isset($_GET['del_module_ajax'])) {
    header('Content-Type: application/json');
    $m_id = (int)$_GET['del_module_ajax'];
    $img = $pdo->query("SELECT image_path FROM tour_modules WHERE id = $m_id")->fetchColumn();
    if ($img && file_exists($img)) { @unlink($img); }
    $pdo->exec("DELETE FROM tour_modules WHERE id = $m_id AND tour_id = $tour_id");
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_module_sort') {
    $order = $_POST['order'] ?? [];
    foreach ($order as $index => $id) { $pdo->prepare("UPDATE tour_modules SET sort_order = ? WHERE id = ? AND tour_id = ?")->execute([$index, (int)$id, $tour_id]); }
    echo json_encode(['status' => 'success']); exit;
}

// --- СОХРАНЕНИЕ ОБЩИХ НАСТРОЕК ТУРА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tour_settings'])) {
    $name = trim($_POST['name'] ?? '');
    $public_name = trim($_POST['public_name'] ?? '');
    $tour_type = trim($_POST['tour_type'] ?? 'Индивидуальная');
    $duration = trim($_POST['duration'] ?? '');
    $default_start_time = trim($_POST['default_start_time'] ?? '10:00');
    $difficulty = trim($_POST['difficulty'] ?? 'Легкая');
    $coordinates = trim($_POST['coordinates'] ?? '');
    $food_options = trim($_POST['food_options'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $assigned_guides = $_POST['guides'] ?? [];

    $included_arr = $_POST['included'] ?? [];
    $not_included_arr = $_POST['not_included'] ?? [];
    $included_json = json_encode(array_values(array_filter(array_map('trim', $included_arr))), JSON_UNESCAPED_UNICODE);
    $not_included_json = json_encode(array_values(array_filter(array_map('trim', $not_included_arr))), JSON_UNESCAPED_UNICODE);

    $faq_q_arr = $_POST['faq_q'] ?? [];
    $faq_a_arr = $_POST['faq_a'] ?? [];
    $faq_data = [];
    foreach ($faq_q_arr as $i => $q) {
        $q_val = trim($q);
        $a_val = trim($faq_a_arr[$i] ?? '');
        if ($q_val !== '' || $a_val !== '') {
            $faq_data[] = ['q' => $q_val, 'a' => $a_val];
        }
    }
    $faq_json = json_encode($faq_data, JSON_UNESCAPED_UNICODE);

    $enabled_sources = $_POST['source_enabled'] ?? [];
    $posted_prices = $_POST['source_price'] ?? [];
    $prices_to_save = [];
    foreach ($enabled_sources as $s_id => $val) {
        $prices_to_save[$s_id] = (int)($posted_prices[$s_id] ?? 0);
    }
    $prices_json = json_encode($prices_to_save, JSON_UNESCAPED_UNICODE);

    $stmt_img = $pdo->prepare("SELECT main_image FROM tours_catalog WHERE id = ?");
    $stmt_img->execute([$tour_id]);
    $main_image = $stmt_img->fetchColumn() ?: '';

    // WebP оптимизация для главной обложки
    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $optimized_path = optimizeImageToWebp($_FILES['main_image']['tmp_name'], 'tour_main_' . $tour_id);
        if ($optimized_path) {
            if ($main_image && file_exists($main_image)) { @unlink($main_image); }
            $main_image = $optimized_path;
        }
    }

    if ($name !== '') {
        $stmt = $pdo->prepare("UPDATE tours_catalog SET name=?, public_name=?, tour_type=?, duration=?, default_start_time=?, difficulty=?, coordinates=?, description=?, food_options=?, program=?, prices=?, main_image=?, included_text=?, not_included_text=?, faq_text=? WHERE id=?");
        $stmt->execute([$name, $public_name, $tour_type, $duration, $default_start_time, $difficulty, $coordinates, $description, $food_options, $program, $prices_json, $main_image, $included_json, $not_included_json, $faq_json, $tour_id]);

        $all_guides = $pdo->query("SELECT id, allowed_tours FROM guides")->fetchAll(PDO::FETCH_ASSOC);
        $all_tours = $pdo->query("SELECT id FROM tours_catalog")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($all_guides as $g) {
            $g_id = $g['id'];
            $is_checked = in_array($g_id, $assigned_guides);
            $current_tours_arr = ($g['allowed_tours'] === 'all') ? $all_tours : explode(',', $g['allowed_tours']);
            $current_tours_arr = array_filter($current_tours_arr);

            if ($is_checked) {
                if (!in_array($tour_id, $current_tours_arr) && $g['allowed_tours'] !== 'all') {
                    $current_tours_arr[] = $tour_id;
                    $pdo->prepare("UPDATE guides SET allowed_tours = ? WHERE id = ?")->execute([implode(',', $current_tours_arr), $g_id]);
                }
            } else {
                if (in_array($tour_id, $current_tours_arr)) {
                    $current_tours_arr = array_diff($current_tours_arr, [$tour_id]);
                    $new_val = empty($current_tours_arr) ? '' : implode(',', $current_tours_arr);
                    $pdo->prepare("UPDATE guides SET allowed_tours = ? WHERE id = ?")->execute([$new_val, $g_id]);
                }
            }
        }
    }
    header("Location: tour_builder.php?id=$tour_id&msg=saved"); exit;
}

$stmt = $pdo->prepare("SELECT * FROM tours_catalog WHERE id = ?"); $stmt->execute([$tour_id]); $tour = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tour) { die("Тур не найден."); }

$guides = $pdo->query("SELECT * FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$modules = $pdo->query("SELECT * FROM tour_modules WHERE tour_id = $tour_id ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$sources_list = $pdo->query("SELECT * FROM booking_sources ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$saved_prices = json_decode($tour['prices'] ?? '', true) ?: [];

$included_items = json_decode($tour['included_text'] ?? '[]', true);
if (!is_array($included_items)) $included_items = trim($tour['included_text']) ? [trim($tour['included_text'])] : [];

$not_included_items = json_decode($tour['not_included_text'] ?? '[]', true);
if (!is_array($not_included_items)) $not_included_items = trim($tour['not_included_text']) ? [trim($tour['not_included_text'])] : [];

$faq_items = json_decode($tour['faq_text'] ?? '[]', true);
if (!is_array($faq_items)) {
    $old_faq = trim($tour['faq_text'] ?? '');
    $faq_items = $old_faq ? [['q' => 'Частый вопрос', 'a' => $old_faq]] : [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Конструктор: <?= htmlspecialchars($tour['name']) ?></title>
    
    <link rel="stylesheet" href="assets/style.css">
    
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <style>
        /* Специфичные стили для конструктора */
        .tour-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        
        .prices-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px;}
        .price-card { background: #F8FAFC; padding: 15px; border-radius: var(--radius-md); border: 1px solid var(--border); position: relative; display: flex; flex-direction: column; gap: 8px;}
        .pc-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
        .price-card label.src-name { margin:0; font-size:12px; font-weight:800; text-transform:uppercase; line-height: 1.4; word-break: break-word; flex: 1; }
        .price-card input { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px; font-size: 16px; font-weight: 700; width: 100%; box-sizing: border-box; outline: none; transition: var(--transition);}
        
        .toggle-switch { position: relative; display: inline-block; width: 34px; height: 20px; flex-shrink: 0; margin-top: 2px;}
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #CBD5E1; transition: .3s; border-radius: 20px; }
        .toggle-slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1);}
        .toggle-switch input:checked + .toggle-slider { background-color: var(--primary); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(14px); }

        /* Динамические списки */
        .dynamic-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 10px; }
        .dyn-item { display: flex; align-items: center; gap: 8px; background: #F8FAFC; border: 1px solid var(--border); padding: 6px 10px; border-radius: var(--radius-sm); transition: var(--transition);}
        .dyn-item:hover { border-color: #CBD5E1; }
        .dyn-item .drag-handle { cursor: grab; color: #94A3B8; display: flex; align-items: center; padding: 0 4px;}
        .dyn-item .drag-handle:active { cursor: grabbing; color: var(--primary); }
        .dyn-item input { flex: 1; border: none; background: transparent; outline: none; font-size: 14px; font-family: inherit; color: var(--text-main); font-weight: 500;}
        .dyn-item .btn-remove { background: transparent; border: none; color: #EF4444; cursor: pointer; padding: 4px; border-radius: 4px; transition: 0.2s; display: flex; align-items: center;}
        .dyn-item .btn-remove:hover { background: #FEE2E2; }
        .btn-add-item { background: transparent; border: 1px dashed var(--primary); color: var(--primary); font-size: 13px; font-weight: 700; padding: 8px 14px; border-radius: var(--radius-sm); cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-add-item:hover { background: var(--primary-light); }

        .dyn-faq-item { flex-direction: column; align-items: stretch; padding: 12px; gap: 10px; }
        .dyn-faq-item input { font-weight: 700; border-bottom: 1px dashed var(--border); padding-bottom: 8px; }
        .dyn-faq-item textarea { width: 100%; border: none; background: transparent; outline: none; font-size: 13px; font-family: inherit; color: var(--text-muted); resize: vertical; min-height: 40px; padding: 0;}

        .guide-checkboxes { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;}
        .hidden-cb { display: none; }
        .guide-label { display: inline-flex; align-items: center; justify-content: center; background: #F8FAFC; padding: 10px 18px; border: 1px solid var(--border); border-radius: 99px; font-size: 13px; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: var(--transition); user-select: none; box-shadow: var(--shadow-sm);}
        .hidden-cb:checked + .guide-label { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25); transform: translateY(-1px);}

        .drop-zone { position: relative; border: 2px dashed #CBD5E1; border-radius: var(--radius-md); padding: 40px 20px; text-align: center; background: #F8FAFC; cursor: pointer; transition: var(--transition); color: var(--text-muted); font-weight: 600; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; overflow: hidden; min-height: 160px; box-sizing: border-box;}
        .drop-zone.dragover { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
        .drop-zone svg { width: 36px; height: 36px; color: currentColor; z-index: 2; position: relative; transition: var(--transition);}
        .drop-zone .dz-text { z-index: 2; position: relative; transition: var(--transition); font-size: 13px;}
        .drop-zone .preview { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 1; transition: opacity 0.3s;}
        .drop-zone.has-image .dz-text, .drop-zone.has-image svg { background: rgba(255,255,255,0.9); padding: 4px 12px; border-radius: 8px; backdrop-filter: blur(4px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); color: var(--text-main);}

        .builder-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
        .modules-list { display: flex; flex-direction: column; gap: 15px; }
        .module-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 15px; display: flex; gap: 15px; position: relative; transition: var(--transition); box-shadow: var(--shadow-sm); align-items: center;}
        
        .drag-handle { cursor: grab; font-size: 20px; color: #9CA3AF; display: flex; align-items: center; flex-shrink: 0; padding: 0 5px;}
        .mod-img { width: 85px; height: 85px; border-radius: var(--radius-sm); object-fit: cover; background: #F8FAFC; flex-shrink: 0; border: 1px solid var(--border);}
        .mod-info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center;}
        .mod-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; gap: 10px;}
        .mod-title { font-weight: 800; font-size: 16px; margin: 0; color: var(--text-main); line-height: 1.2;}
        .mod-timing { display: inline-block; background: var(--primary-light); color: var(--primary); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800;}
        .mod-desc { font-size: 13px; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.4; font-weight: 500; margin:0;}
        
        .mod-actions { display: flex; gap: 8px; flex-shrink: 0;}
        .btn-icon { width: 36px; height: 36px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; background: #F8FAFC; color: #64748B; transition: var(--transition);}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        .btn-icon.edit { background: var(--primary-light); color: var(--primary); }
        .btn-icon.del { background: #FEF2F2; color: #EF4444; }

        .editor-box { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 25px; position: sticky; top: 20px; box-shadow: var(--shadow-md);}
        .ql-toolbar.ql-snow { border-radius: var(--radius-sm) var(--radius-sm) 0 0; border-color: var(--border); background: #F8FAFC; padding: 12px; font-family: inherit;}
        .ql-container.ql-snow { border-radius: 0 0 var(--radius-sm) var(--radius-sm); border-color: var(--border); background: #fff; min-height: 220px; font-size: 14px; font-family: inherit;}

        @media (max-width: 992px) { 
            .tour-settings-grid, .builder-grid { grid-template-columns: 1fr; gap: 20px;} 
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <a href="tours.php" class="back-link" style="display: inline-flex; align-items: center; gap: 8px; color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 700; margin-bottom: 15px; padding: 8px 16px; background: var(--primary-light); border-radius: 99px;">← Назад в каталог</a>
        <h2>Настройка: <?= htmlspecialchars($tour['name']) ?></h2>
    </div>

    <div class="card">
        <form method="POST" enctype="multipart/form-data" id="mainTourForm">
            <input type="hidden" name="save_tour_settings" value="1">
            
            <div class="tour-settings-grid">
                <div>
                    <h3 class="section-title">Главная информация</h3>
                    <div class="form-group">
                        <label>Название для CRM (Кратко) *</label>
                        <input type="text" name="name" id="inp_name" class="t-input" value="<?= htmlspecialchars($tour['name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Название для Сайта (Красиво)</label>
                        <input type="text" name="public_name" id="inp_public" class="t-input" value="<?= htmlspecialchars($tour['public_name'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Тип экскурсии</label>
                        <select name="tour_type" id="inp_tour_type" class="t-input" onchange="updatePriceLabels()">
                            <option value="Индивидуальная" <?= ($tour['tour_type'] ?? 'Индивидуальная') === 'Индивидуальная' ? 'selected' : '' ?>>Индивидуальная (цена за группу)</option>
                            <option value="Групповая" <?= ($tour['tour_type'] ?? '') === 'Групповая' ? 'selected' : '' ?>>Групповая (цена за человека)</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Подробное описание маршрута (внутреннее)</label>
                        <textarea name="description" id="inp_desc" class="t-input" rows="3" style="resize:vertical;"><?= htmlspecialchars($tour['description'] ?? '') ?></textarea>
                    </div>
                    
                    <h3 class="section-title" style="margin-top: 30px;" id="price_section_label">Источники продаж и Стоимость (₽)</h3>
                    <p style="font-size:12px; color:var(--text-muted); margin-top:-15px; margin-bottom:15px; line-height:1.4;">Включите источники, через которые продается этот тур, и укажите цену для каждого.</p>
                    <div class="prices-grid">
                        <?php foreach($sources_list as $src): 
                            $src_id = $src['id'];
                            $is_enabled = isset($saved_prices[$src_id]);
                            $price_val = $is_enabled ? $saved_prices[$src_id] : '';
                            $color = getSourceColor($src['name']);
                        ?>
                        <div class="price-card" style="border-left-width: 4px; border-left-style: solid; border-left-color: <?= $color ?>;">
                            <div class="pc-header">
                                <label class="src-name" style="color:<?= $color ?>;"><?= htmlspecialchars($src['name']) ?></label>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="source_enabled[<?= $src_id ?>]" value="1" <?= $is_enabled ? 'checked' : '' ?> onchange="togglePrice(<?= $src_id ?>)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <input type="number" name="source_price[<?= $src_id ?>]" id="price_inp_<?= $src_id ?>" value="<?= $price_val ?>" placeholder="0" <?= $is_enabled ? '' : 'disabled style="opacity:0.5;"' ?>>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="section-title" style="margin-top: 30px;">УТП для сайта (Списки)</h3>
                    
                    <div class="form-group">
                        <label style="color: #10B981;">✅ Что ВКЛЮЧЕНО в стоимость</label>
                        <div id="included-list" class="dynamic-list">
                            <?php foreach($included_items as $item): ?>
                                <div class="dyn-item">
                                    <div class="drag-handle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></div>
                                    <input type="text" name="included[]" value="<?= htmlspecialchars($item) ?>">
                                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add-item" onclick="addListItem('included-list', 'included')">+ Добавить пункт</button>
                    </div>

                    <div class="form-group" style="margin-top: 20px;">
                        <label style="color: #EF4444;">❌ Что НЕ ВКЛЮЧЕНО</label>
                        <div id="not-included-list" class="dynamic-list">
                            <?php foreach($not_included_items as $item): ?>
                                <div class="dyn-item">
                                    <div class="drag-handle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></div>
                                    <input type="text" name="not_included[]" value="<?= htmlspecialchars($item) ?>">
                                    <button type="button" class="btn-remove" onclick="this.parentElement.remove()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add-item" onclick="addListItem('not-included-list', 'not_included')">+ Добавить пункт</button>
                    </div>

                    <div class="form-group" style="margin-top: 30px;">
                        <label>FAQ / Частые вопросы (Аккордеон)</label>
                        <div id="faq-list" class="dynamic-list">
                            <?php foreach($faq_items as $item): ?>
                                <div class="dyn-item dyn-faq-item">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div class="drag-handle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line></svg></div>
                                        <input type="text" name="faq_q[]" value="<?= htmlspecialchars($item['q']) ?>" placeholder="Вопрос..." style="flex:1;">
                                        <button type="button" class="btn-remove" onclick="this.closest('.dyn-item').remove()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                                    </div>
                                    <textarea name="faq_a[]" placeholder="Ответ на вопрос..." rows="2"><?= htmlspecialchars($item['a']) ?></textarea>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add-item" onclick="addFaqItem()">+ Добавить Вопрос-Ответ</button>
                    </div>
                </div>

                <div>
                    <h3 class="section-title">Главная обложка тура</h3>
                    <div class="form-group">
                        <div id="main-drop-zone" class="drop-zone <?= !empty($tour['main_image']) ? 'has-image' : '' ?>">
                            <img src="<?= htmlspecialchars($tour['main_image'] ?? '') ?>" class="preview" id="main-preview-img" style="<?= empty($tour['main_image']) ? 'display:none;' : '' ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            <span class="dz-text">Перетащите обложку сюда (WebP авто-сжатие)</span>
                            <input type="file" name="main_image" id="main_image_input" accept="image/*" style="display: none;">
                        </div>
                    </div>

                    <h3 class="section-title" style="margin-top: 30px;">Детали маршрута</h3>
                    <div class="form-group">
                        <label style="color: var(--primary);">⏱ Время старта по умолчанию</label>
                        <input type="time" name="default_start_time" class="t-input" value="<?= htmlspecialchars($tour['default_start_time'] ?? '10:00') ?>" style="font-weight: 800; font-size: 16px;">
                    </div>
                    <div class="form-group">
                        <label>Продолжительность (Тайминг)</label>
                        <input type="text" name="duration" id="inp_dur" class="t-input" value="<?= htmlspecialchars($tour['duration'] ?? '') ?>" placeholder="2 часа">
                    </div>
                    <div class="form-group">
                        <label>Сложность маршрута</label>
                        <input type="text" name="difficulty" class="t-input" value="<?= htmlspecialchars($tour['difficulty'] ?? 'Легкая') ?>" placeholder="Например: Легкая, Средняя, Для всех">
                    </div>
                    <div class="form-group">
                        <label>Координаты / Точка сбора</label>
                        <input type="text" name="coordinates" class="t-input" value="<?= htmlspecialchars($tour['coordinates'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Питание / Кафе по маршруту</label>
                        <textarea name="food_options" class="t-input" rows="2"><?= htmlspecialchars($tour['food_options'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Краткая памятка для гида</label>
                        <textarea name="program" class="t-input" rows="2"><?= htmlspecialchars($tour['program'] ?? '') ?></textarea>
                    </div>

                    <h3 class="section-title" style="margin-top: 30px;">Кто проводит этот тур?</h3>
                    <div class="guide-checkboxes">
                        <?php foreach($guides as $g): 
                            $is_assigned = ($g['allowed_tours'] === 'all' || in_array((string)$tour_id, explode(',', $g['allowed_tours'])));
                        ?>
                            <div>
                                <input type="checkbox" name="guides[]" value="<?= $g['id'] ?>" id="g_<?= $g['id'] ?>" class="hidden-cb" <?= $is_assigned ? 'checked' : '' ?>>
                                <label for="g_<?= $g['id'] ?>" class="guide-label"><?= htmlspecialchars($g['name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="border-top: 1px solid var(--border); margin-top: 30px; padding-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap:wrap; gap:20px;">
                <button type="submit" class="btn-save">💾 Сохранить общие настройки</button>
            </div>
        </form>
    </div>

    <div class="header-box" style="margin-top: 50px;">
        <h2>Конструктор маршрута (Этапы)</h2>
    </div>

    <div class="builder-grid">
        <div>
            <div class="modules-list" id="modulesList">
                <?php if(empty($modules)): ?>
                    <div style="padding: 40px; text-align: center; border: 2px dashed var(--border); border-radius: var(--radius-md); color: var(--text-muted); font-weight:600;" class="ignore-sort">
                        Маршрут пока пуст. Добавьте первый этап справа!
                    </div>
                <?php endif; ?>

                <?php foreach($modules as $m): ?>
                    <div class="module-card" data-id="<?= $m['id'] ?>">
                        <textarea id="raw_content_<?= $m['id'] ?>" style="display:none;"><?= htmlspecialchars($m['content']) ?></textarea>
                        <div class="drag-handle" title="Потяните для сортировки"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></div>
                        
                        <?php if($m['image_path'] && file_exists($m['image_path'])): ?>
                            <img src="<?= htmlspecialchars($m['image_path']) ?>" class="mod-img">
                        <?php else: ?>
                            <div class="mod-img" style="display:flex; align-items:center; justify-content:center; color:#CBD5E1; font-size:11px; font-weight:600;">Нет фото</div>
                        <?php endif; ?>

                        <div class="mod-info">
                            <div class="mod-header">
                                <div class="mod-title-wrap">
                                    <?php if($m['timing']): ?><span class="mod-timing">⏱ <?= htmlspecialchars($m['timing']) ?></span><?php endif; ?>
                                    <h4 class="mod-title"><?= htmlspecialchars($m['title']) ?></h4>
                                </div>
                                <div class="mod-actions">
                                    <button type="button" class="btn-icon edit" onclick="editModule(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['title'])) ?>', '<?= htmlspecialchars(addslashes($m['timing'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($m['image_path'] ?? '')) ?>')" title="Редактировать"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                                    <button type="button" class="btn-icon del" onclick="deleteModuleAjax(<?= $m['id'] ?>, this)" title="Удалить"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                                </div>
                            </div>
                            <p class="mod-desc"><?= strip_tags($m['content']) ?: 'Без описания...' ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="editor-box">
            <h3 id="formTitle" class="section-title" style="border:none; margin-bottom:15px; padding:0; justify-content:flex-start;">Добавить этап</h3>
            
            <form id="ajaxModuleForm">
                <input type="hidden" name="save_module_ajax" value="1">
                <input type="hidden" name="module_id" id="fModuleId" value="0">
                <input type="hidden" name="content" id="fContentHidden">

                <div class="form-group">
                    <label>Название локации *</label>
                    <input type="text" name="title" id="fTitle" class="t-input" required placeholder="Например: Тульский Кремль">
                </div>

                <div class="form-group">
                    <label>Метка времени</label>
                    <input type="text" name="timing" id="fTiming" class="t-input" placeholder="Например: 10:00 - 12:30">
                </div>

                <div class="form-group">
                    <label>Фотография локации (WebP сжатие)</label>
                    <div id="mod-drop-zone" class="drop-zone">
                        <img src="" class="preview" id="mod-preview-img" style="display:none;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <span class="dz-text">Перетащите фото сюда</span>
                        <input type="file" name="module_image" id="mod_image_input" accept="image/*" style="display: none;">
                    </div>
                </div>

                <div class="form-group">
                    <label>Текст описания</label>
                    <div id="quill-editor"></div>
                </div>

                <button type="submit" id="btnSaveModule" class="btn-save" style="width:100%; margin-top:10px;">Сохранить этап</button>
                <button type="button" class="btn-danger" onclick="resetForm()" style="width:100%; justify-content:center; margin-top:10px; background:transparent; border-color:transparent; color:var(--text-muted);">Отменить / Очистить</button>
            </form>
        </div>
    </div>
</div>

<script src="assets/app.js"></script>
<script>
    function updatePriceLabels() {
        const type = document.getElementById('inp_tour_type').value;
        const label = document.getElementById('price_section_label');
        if(type === 'Групповая') {
            label.textContent = 'Источники продаж и Стоимость за 1 человека (₽)';
        } else {
            label.textContent = 'Источники продаж и Стоимость за всю экскурсию (₽)';
        }
    }

    function togglePrice(id) {
        const inp = document.getElementById('price_inp_' + id);
        if (inp.disabled) { inp.disabled = false; inp.style.opacity = '1'; if(inp.value == 0) inp.value = ''; inp.focus();
        } else { inp.disabled = true; inp.style.opacity = '0.5'; }
    }

    function addListItem(containerId, inputName) {
        const container = document.getElementById(containerId);
        const div = document.createElement('div');
        div.className = 'dyn-item';
        div.innerHTML = `
            <div class="drag-handle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></div>
            <input type="text" name="${inputName}[]" placeholder="Введите пункт..." required>
            <button type="button" class="btn-remove" onclick="this.parentElement.remove()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
        `;
        container.appendChild(div);
        div.querySelector('input').focus();
    }

    function addFaqItem() {
        const container = document.getElementById('faq-list');
        const div = document.createElement('div');
        div.className = 'dyn-item dyn-faq-item';
        div.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px;">
                <div class="drag-handle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line></svg></div>
                <input type="text" name="faq_q[]" placeholder="Вопрос..." style="flex:1;" required>
                <button type="button" class="btn-remove" onclick="this.closest('.dyn-item').remove()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
            </div>
            <textarea name="faq_a[]" placeholder="Ответ на вопрос..." rows="2" required></textarea>
        `;
        container.appendChild(div);
        div.querySelector('input').focus();
    }

    function setupDropZone(dropZoneId, inputId, previewId) {
        const dropZone = document.getElementById(dropZoneId);
        const input = document.getElementById(inputId);
        const preview = document.getElementById(previewId);
        dropZone.addEventListener('click', () => input.click());
        dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault(); dropZone.classList.remove('dragover');
            if (e.dataTransfer.files.length && e.dataTransfer.files[0].type.startsWith('image/')) {
                input.files = e.dataTransfer.files; showImagePreview(input.files[0], preview, dropZone);
            }
        });
        input.addEventListener('change', () => { if(input.files.length) showImagePreview(input.files[0], preview, dropZone); });
    }

    function showImagePreview(file, imgElement, dropZone) {
        const reader = new FileReader();
        reader.onload = e => { imgElement.src = e.target.result; imgElement.style.display = 'block'; dropZone.classList.add('has-image'); }
        reader.readAsDataURL(file);
    }

    setupDropZone('main-drop-zone', 'main_image_input', 'main-preview-img');
    setupDropZone('mod-drop-zone', 'mod_image_input', 'mod-preview-img');

    var quill = new Quill('#quill-editor', {
        theme: 'snow', placeholder: 'Напишите текст описания локации...',
        modules: { toolbar: [ ['bold', 'italic'], [{ 'list': 'ordered'}, { 'list': 'bullet' }], ['clean'] ] }
    });

    document.getElementById('ajaxModuleForm').onsubmit = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSaveModule');
        btn.textContent = 'Обработка (сжатие фото)...'; btn.style.opacity = '0.7';

        var htmlContent = quill.root.innerHTML;
        if (htmlContent === '<p><br></p>') htmlContent = ''; 
        document.getElementById('fContentHidden').value = htmlContent;

        const formData = new FormData(this);
        
        try {
            const response = await fetch('tour_builder.php?id=<?= $tour_id ?>', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') { showToast('Этап сохранен!'); renderModuleCard(result.module); resetForm(); }
        } catch(err) { showToast('Ошибка сохранения', 'error'); } 
        finally { btn.textContent = 'Сохранить этап'; btn.style.opacity = '1'; }
    };

    function renderModuleCard(m) {
        let existingCard = document.querySelector(`.module-card[data-id="${m.id}"]`);
        let imgSrc = m.image_path ? `<img src="${m.image_path}?t=${new Date().getTime()}" class="mod-img">` : `<div class="mod-img" style="display:flex; align-items:center; justify-content:center; color:#CBD5E1; font-size:11px; font-weight:600;">Нет фото</div>`;
        let timingHtml = m.timing ? `<span class="mod-timing">⏱ ${m.timing}</span>` : '';
        let tempDiv = document.createElement('div'); tempDiv.innerHTML = m.content;
        let plainDesc = tempDiv.textContent || tempDiv.innerText || 'Без описания...';

        let safeTitle = m.title.replace(/'/g, "\\'");
        let safeTiming = (m.timing||'').replace(/'/g, "\\'");
        let safeImg = (m.image_path||'').replace(/'/g, "\\'");

        let innerHTML = `
            <textarea id="raw_content_${m.id}" style="display:none;">${m.content || ''}</textarea>
            <div class="drag-handle" title="Потяните для сортировки"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></div>
            ${imgSrc}
            <div class="mod-info">
                <div class="mod-header">
                    <div class="mod-title-wrap">${timingHtml}<h4 class="mod-title">${m.title}</h4></div>
                    <div class="mod-actions">
                        <button type="button" class="btn-icon edit" onclick="editModule(${m.id}, '${safeTitle}', '${safeTiming}', '${safeImg}')" title="Редактировать"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
                        <button type="button" class="btn-icon del" onclick="deleteModuleAjax(${m.id}, this)" title="Удалить"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>
                    </div>
                </div>
                <p class="mod-desc">${plainDesc}</p>
            </div>
        `;

        if(existingCard) { existingCard.innerHTML = innerHTML; } 
        else {
            let newCard = document.createElement('div');
            newCard.className = 'module-card'; newCard.setAttribute('data-id', m.id);
            newCard.innerHTML = innerHTML;
            document.getElementById('modulesList').appendChild(newCard);
            let emptyMsg = document.querySelector('.ignore-sort'); if(emptyMsg) emptyMsg.remove();
        }
    }

    async function deleteModuleAjax(id, btnElement) {
        if(!confirm('Точно удалить этот этап?')) return;
        try {
            const response = await fetch(`tour_builder.php?id=<?= $tour_id ?>&del_module_ajax=${id}`);
            const result = await response.json();
            if(result.status === 'success') {
                const card = btnElement.closest('.module-card');
                card.style.transform = 'scale(0.9)'; card.style.opacity = '0';
                setTimeout(() => card.remove(), 200); showToast('Этап удален', 'error');
            }
        } catch(err) { showToast('Ошибка удаления', 'error'); }
    }

    function editModule(id, title, timing, imagePath) {
        document.getElementById('fModuleId').value = id;
        document.getElementById('fTitle').value = title;
        document.getElementById('fTiming').value = timing;
        quill.root.innerHTML = document.getElementById('raw_content_' + id).value;
        document.getElementById('formTitle').textContent = 'Редактировать этап';
        
        let modPreview = document.getElementById('mod-preview-img');
        let modDrop = document.getElementById('mod-drop-zone');
        if (imagePath && imagePath !== 'undefined') {
            modPreview.src = imagePath; modPreview.style.display = 'block'; modDrop.classList.add('has-image');
        } else {
            modPreview.src = ''; modPreview.style.display = 'none'; modDrop.classList.remove('has-image');
        }
        window.scrollTo({ top: document.querySelector('.editor-box').offsetTop - 20, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('fModuleId').value = '0';
        document.getElementById('fTitle').value = ''; document.getElementById('fTiming').value = '';
        quill.root.innerHTML = ''; document.getElementById('formTitle').textContent = 'Добавить этап';
        document.getElementById('mod_image_input').value = '';
        let modPreview = document.getElementById('mod-preview-img');
        let modDrop = document.getElementById('mod-drop-zone');
        modPreview.src = ''; modPreview.style.display = 'none'; modDrop.classList.remove('has-image');
    }

    document.addEventListener('DOMContentLoaded', () => {
        updatePriceLabels();

        var list = document.getElementById('modulesList');
        if (list) {
            new Sortable(list, {
                animation: 150, handle: '.drag-handle', filter: '.ignore-sort',
                onEnd: function () {
                    const formData = new FormData();
                    formData.append('action', 'update_module_sort');
                    Array.from(list.querySelectorAll('.module-card')).forEach(item => { formData.append('order[]', item.getAttribute('data-id')); });
                    fetch('tour_builder.php?id=<?= $tour_id ?>', { method: 'POST', body: formData }).catch(e => console.error(e));
                }
            });
        }

        const incList = document.getElementById('included-list');
        if (incList) new Sortable(incList, { animation: 150, handle: '.drag-handle' });
        
        const notIncList = document.getElementById('not-included-list');
        if (notIncList) new Sortable(notIncList, { animation: 150, handle: '.drag-handle' });

        const faqList = document.getElementById('faq-list');
        if (faqList) new Sortable(faqList, { animation: 150, handle: '.drag-handle' });

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('msg') === 'saved') {
            showToast('Настройки сохранены!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tour_id ?>');
        }
    });
</script>

</body>
</html>