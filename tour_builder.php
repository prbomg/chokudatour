<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') { die("<h2 style='text-align:center; margin-top:50px;'>Доступ закрыт.</h2>"); }

$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tour_id === 0) { header("Location: tours.php"); exit; }

// --- АВТО-ОБНОВЛЕНИЕ БАЗЫ ДАННЫХ ---
$pdo->exec("CREATE TABLE IF NOT EXISTS tour_modules (id INT AUTO_INCREMENT PRIMARY KEY, tour_id INT NOT NULL, title VARCHAR(255) NOT NULL, timing VARCHAR(255) DEFAULT NULL, content TEXT DEFAULT NULL, image_path VARCHAR(255) DEFAULT NULL, sort_order INT DEFAULT 999)");
$pdo->exec("CREATE TABLE IF NOT EXISTS tour_gallery (id INT AUTO_INCREMENT PRIMARY KEY, tour_id INT NOT NULL, image_path VARCHAR(255) NOT NULL)");

$columns = [
    'included_text' => 'TEXT DEFAULT NULL', 'not_included_text' => 'TEXT DEFAULT NULL', 
    'faq_text' => 'TEXT DEFAULT NULL', 'food_options' => 'TEXT DEFAULT NULL', 
    'program' => 'TEXT DEFAULT NULL', 'main_image' => 'VARCHAR(255) DEFAULT NULL',
    'price_direct' => 'INT DEFAULT 0', 'price_tripster' => 'INT DEFAULT 0', 
    'price_sputnik' => 'INT DEFAULT 0', 'price_site' => 'INT DEFAULT 0', 'description' => 'TEXT DEFAULT NULL',
    'default_start_time' => "VARCHAR(50) DEFAULT '10:00'"
];
foreach ($columns as $col => $type) {
    try { $pdo->exec("ALTER TABLE tours_catalog ADD COLUMN $col $type"); } catch(PDOException $e) {}
}

// ==========================================
// ⚡ AJAX ОБРАБОТЧИКИ ДЛЯ ЭТАПОВ (БЕЗ ПЕРЕЗАГРУЗКИ)
// ==========================================
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
    
    if (isset($_FILES['module_image']) && $_FILES['module_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['module_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            $new_name = 'uploads/mod_' . $tour_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['module_image']['tmp_name'], $new_name)) {
                if ($image_path && file_exists($image_path)) { @unlink($image_path); }
                $image_path = $new_name;
            }
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
// ==========================================


// --- СОХРАНЕНИЕ ОБЩИХ НАСТРОЕК ТУРА ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_tour_settings'])) {
    $name = trim($_POST['name'] ?? '');
    $public_name = trim($_POST['public_name'] ?? '');
    $duration = trim($_POST['duration'] ?? '');
    $default_start_time = trim($_POST['default_start_time'] ?? '10:00');
    $coordinates = trim($_POST['coordinates'] ?? '');
    $food_options = trim($_POST['food_options'] ?? '');
    $program = trim($_POST['program'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $included_text = trim($_POST['included_text'] ?? '');
    $not_included_text = trim($_POST['not_included_text'] ?? '');
    $faq_text = trim($_POST['faq_text'] ?? '');
    
    $price_direct = (int)($_POST['price_direct'] ?? 0);
    $price_tripster = (int)($_POST['price_tripster'] ?? 0);
    $price_sputnik = (int)($_POST['price_sputnik'] ?? 0);
    $price_site = (int)($_POST['price_site'] ?? 0);
    $assigned_guides = $_POST['guides'] ?? [];

    $stmt_img = $pdo->prepare("SELECT main_image FROM tours_catalog WHERE id = ?");
    $stmt_img->execute([$tour_id]);
    $main_image = $stmt_img->fetchColumn() ?: '';

    if (isset($_FILES['main_image']) && $_FILES['main_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['main_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            $new_name = 'uploads/tour_main_' . $tour_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['main_image']['tmp_name'], $new_name)) {
                if ($main_image && file_exists($main_image)) { @unlink($main_image); }
                $main_image = $new_name;
            }
        }
    }

    if (!empty($_FILES['gallery_images']['name'][0])) {
        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        foreach ($_FILES['gallery_images']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['gallery_images']['error'][$key] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['gallery_images']['name'][$key], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $new_name = 'uploads/gal_' . $tour_id . '_' . time() . '_' . $key . '.' . $ext;
                    if (move_uploaded_file($tmp_name, $new_name)) {
                        $pdo->prepare("INSERT INTO tour_gallery (tour_id, image_path) VALUES (?, ?)")->execute([$tour_id, $new_name]);
                    }
                }
            }
        }
    }

    if ($name !== '') {
        $stmt = $pdo->prepare("UPDATE tours_catalog SET name=?, public_name=?, duration=?, default_start_time=?, coordinates=?, description=?, food_options=?, program=?, price_direct=?, price_tripster=?, price_sputnik=?, price_site=?, main_image=?, included_text=?, not_included_text=?, faq_text=? WHERE id=?");
        $stmt->execute([$name, $public_name, $duration, $default_start_time, $coordinates, $description, $food_options, $program, $price_direct, $price_tripster, $price_sputnik, $price_site, $main_image, $included_text, $not_included_text, $faq_text, $tour_id]);

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

if (isset($_GET['del_gallery_img'])) {
    $img_id = (int)$_GET['del_gallery_img'];
    $path = $pdo->query("SELECT image_path FROM tour_gallery WHERE id = $img_id AND tour_id = $tour_id")->fetchColumn();
    if ($path && file_exists($path)) { @unlink($path); }
    $pdo->exec("DELETE FROM tour_gallery WHERE id = $img_id AND tour_id = $tour_id");
    header("Location: tour_builder.php?id=$tour_id&msg=img_deleted"); exit;
}

$stmt = $pdo->prepare("SELECT * FROM tours_catalog WHERE id = ?"); $stmt->execute([$tour_id]); $tour = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tour) { die("Тур не найден."); }

$guides = $pdo->query("SELECT * FROM guides ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$gallery = $pdo->query("SELECT * FROM tour_gallery WHERE tour_id = $tour_id ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$modules = $pdo->query("SELECT * FROM tour_modules WHERE tour_id = $tour_id ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Конструктор: <?= htmlspecialchars($tour['name']) ?></title>
    
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

    <style>
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05); --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em; }
        .container { max-width: 1350px; padding: 0; margin: 0 auto; box-sizing: border-box; }
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }

        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { margin-bottom: 25px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--primary); text-decoration: none; font-size: 14px; font-weight: 700; margin-bottom: 15px; transition: var(--transition); padding: 8px 16px; background: var(--primary-light); border-radius: 99px; }
        .back-link:hover { background: #E0E7FF; transform: translateX(-3px); }
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); word-break: break-word; letter-spacing: -0.02em;}

        .card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 30px; box-shadow: var(--shadow-md); border: 1px solid var(--border); margin-bottom: 30px;}
        .section-title { font-size: 18px; font-weight: 800; color: var(--text-main); border-bottom: 2px solid #F1F5F9; padding-bottom: 10px; margin-top: 0; margin-bottom: 20px;}
        
        .tour-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; margin-bottom: 6px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em;}
        .t-input { width: 100%; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; background: #F8FAFC; color: var(--text-main); box-sizing: border-box; font-family: inherit; font-weight: 500; transition: var(--transition); outline: none;}
        .t-input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light);}
        
        .prices-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-bottom: 25px;}
        .price-card { background: #F8FAFC; padding: 15px; border-radius: var(--radius-md); border: 1px solid var(--border); position: relative; overflow: hidden; display: flex; flex-direction: column; gap: 8px;}
        .price-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; }
        .price-card.c-direct::before { background: #10B981; }
        .price-card.c-site::before { background: #F59E0B; }
        .price-card.c-tripster::before { background: #3B82F6; }
        .price-card.c-sputnik::before { background: #8B5CF6; }
        .price-card label { margin:0; font-size:12px; font-weight:800; text-transform:uppercase;}
        .price-card input { background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px; font-size: 16px; font-weight: 700; width: 100%; box-sizing: border-box; outline: none; transition: var(--transition);}
        .price-card input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

        .guide-checkboxes { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;}
        .hidden-cb { display: none; }
        .guide-label { display: inline-flex; align-items: center; justify-content: center; background: #F8FAFC; padding: 10px 18px; border: 1px solid var(--border); border-radius: 99px; font-size: 13px; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: var(--transition); user-select: none; box-shadow: var(--shadow-sm);}
        .hidden-cb:checked + .guide-label { background: var(--primary); color: white; border-color: var(--primary); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25); transform: translateY(-1px);}
        .guide-label:hover { border-color: var(--primary); }

        .btn-save { background: var(--primary); color: white; padding: 14px 28px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; font-size: 15px; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);}
        .btn-save:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}
        
        .btn-danger { color: #EF4444; font-size: 14px; font-weight: 700; text-decoration: none; padding: 12px 20px; border: 1px solid #FECACA; border-radius: var(--radius-sm); transition: var(--transition); background: #FEF2F2; display: inline-flex; align-items: center; gap: 8px;}
        .btn-danger:hover { background: #FEE2E2; color: #DC2626; border-color: #FCA5A5; }

        .drop-zone { border: 2px dashed #CBD5E1; border-radius: var(--radius-md); padding: 30px 20px; text-align: center; background: #F8FAFC; cursor: pointer; transition: var(--transition); color: var(--text-muted); font-weight: 600; display: flex; flex-direction: column; align-items: center; gap: 10px;}
        .drop-zone.dragover { border-color: var(--primary); background: var(--primary-light); color: var(--primary); }
        .drop-zone svg { width: 32px; height: 32px; color: currentColor; }
        
        .gallery-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 15px; }
        .gal-item { position: relative; width: 90px; height: 90px; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border); box-shadow: var(--shadow-sm); transition: var(--transition);}
        .gal-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .gal-del { position: absolute; top: 4px; right: 4px; background: rgba(239, 68, 68, 0.9); color: white; border-radius: 4px; width: 22px; height: 22px; display:flex; align-items:center; justify-content:center; text-decoration: none; font-size: 12px; font-weight: bold;}

        .builder-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; }
        .modules-list { display: flex; flex-direction: column; gap: 15px; }
        .module-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-md); padding: 15px; display: flex; gap: 15px; position: relative; transition: var(--transition); box-shadow: var(--shadow-sm); align-items: center;}
        
        .drag-handle { cursor: grab; font-size: 20px; color: #9CA3AF; display: flex; align-items: center; flex-shrink: 0; padding: 0 5px;}
        .drag-handle:active { cursor: grabbing; color: var(--primary); }
        .mod-img { width: 85px; height: 85px; border-radius: var(--radius-sm); object-fit: cover; background: #F8FAFC; flex-shrink: 0; border: 1px solid var(--border);}
        .mod-info { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center;}
        .mod-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; gap: 10px;}
        .mod-title { font-weight: 800; font-size: 16px; margin: 0; color: var(--text-main); line-height: 1.2;}
        .mod-timing { display: inline-block; background: var(--primary-light); color: var(--primary); padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; letter-spacing: 0.03em;}
        .mod-desc { font-size: 13px; color: var(--text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.4; font-weight: 500; margin:0;}
        
        .mod-actions { display: flex; gap: 8px; flex-shrink: 0;}
        .btn-icon { width: 36px; height: 36px; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; border: none; cursor: pointer; background: #F8FAFC; color: #64748B; text-decoration: none; transition: var(--transition);}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        .btn-icon.edit { background: var(--primary-light); color: var(--primary); }
        .btn-icon.edit:hover { background: #E0E7FF; color: #3730A3; }
        .btn-icon.del { background: #FEF2F2; color: #EF4444; }
        .btn-icon.del:hover { background: #FEE2E2; color: #DC2626; }

        .editor-box { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 25px; position: sticky; top: 20px; box-shadow: var(--shadow-md);}
        
        /* Quill Override */
        .ql-toolbar.ql-snow { border-radius: var(--radius-sm) var(--radius-sm) 0 0; border-color: var(--border); background: #F8FAFC; padding: 12px; font-family: inherit;}
        .ql-container.ql-snow { border-radius: 0 0 var(--radius-sm) var(--radius-sm); border-color: var(--border); background: #fff; min-height: 220px; font-size: 14px; font-family: inherit;}
        .ql-editor.ql-blank::before { color: #94A3B8; font-style: normal; }

        /* 👁️ И 🔗 ПЛАВАЮЩИЕ КНОПКИ */
        .preview-btn { 
            position: fixed; right: 30px; max-width: 56px; height: 56px; 
            background: var(--text-main); color: white; border-radius: 28px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.2); cursor: pointer; z-index: 9000; 
            transition: max-width 0.4s cubic-bezier(0.4, 0, 0.2, 1), background 0.3s, transform 0.3s, padding 0.4s; 
            display: flex; align-items: center; border: 2px solid rgba(255,255,255,0.1);
            text-decoration: none; box-sizing: border-box; font-family: inherit;
            bottom: 30px; overflow: hidden; white-space: nowrap; padding: 0 16px;
        }
        .preview-btn svg { flex-shrink: 0; display: block; }
        .preview-btn span { font-weight: 800; font-size: 15px; margin-left: 12px; opacity: 0; transition: opacity 0.2s ease; pointer-events: none; }
        .preview-btn:hover { max-width: 300px; padding-right: 24px; transform: translateY(-3px); background: #000; color: white; }
        .preview-btn:hover span { opacity: 1; transition-delay: 0.15s; }

        .preview-btn.link-btn { bottom: 96px; background: #10B981; }
        .preview-btn.link-btn:hover { background: #059669; }
        
        .preview-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 10000; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 20px; box-sizing: border-box; opacity: 0; transition: opacity 0.3s ease;}
        .preview-overlay.show { opacity: 1; }
        .preview-modal { background: #F8FAFC; width: 100%; max-width: 480px; height: 90vh; border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); overflow: hidden; display: flex; flex-direction: column; position: relative; transform: translateY(20px); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);}
        .preview-overlay.show .preview-modal { transform: translateY(0); }
        .preview-content { flex: 1; overflow-y: auto; padding: 0; background: #fff;}
        .prev-cover { width: 100%; height: 250px; object-fit: cover; background: #E2E8F0; }
        .prev-body { padding: 25px; }
        .prev-tag { display: inline-block; background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 800; margin-bottom: 10px; }
        .prev-title { font-size: 24px; font-weight: 900; color: var(--text-main); margin: 0 0 15px 0; line-height: 1.2;}
        .close-preview { position: absolute; top: 15px; right: 15px; width: 36px; height: 36px; background: rgba(0,0,0,0.5); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-weight: bold; border: none; z-index: 10; transition: var(--transition);}
        .close-preview:hover { background: black; transform: scale(1.1); }

        #toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 10000; display: flex; flex-direction: column; gap: 12px; pointer-events: none;}
        .toast { padding: 16px 24px; border-radius: var(--radius-md); color: white; font-weight: 600; font-size: 14px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); opacity: 0; transform: translateX(100%); transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55); display: flex; align-items: center; gap: 12px; pointer-events: auto;}
        .toast.show { opacity: 1; transform: translateX(0); }
        .toast.success { background: #10B981; }
        .toast.error { background: #EF4444; }

        @media (max-width: 992px) { 
            .tour-settings-grid, .builder-grid { grid-template-columns: 1fr; gap: 20px;} 
            .preview-btn { right: 20px; bottom: 20px; }
            .preview-btn.link-btn { bottom: 86px; }
        }
    </style>
</head>
<body>

<div id="toast-container"></div>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <a href="tours.php" class="back-link">← Назад в каталог</a>
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
                        <label>Подробное описание маршрута (внутреннее)</label>
                        <textarea name="description" id="inp_desc" class="t-input" rows="3" style="resize:vertical;"><?= htmlspecialchars($tour['description'] ?? '') ?></textarea>
                    </div>
                    
                    <h3 class="section-title" style="margin-top: 30px;">Стоимость тура (₽)</h3>
                    <div class="prices-grid">
                        <div class="price-card c-direct">
                            <label style="color:#047857;">Прямые / CRM</label>
                            <input type="number" name="price_direct" id="inp_price" value="<?= $tour['price_direct'] ?? 0 ?>">
                        </div>
                        <div class="price-card c-site">
                            <label style="color:#C2410C;">Сайт</label>
                            <input type="number" name="price_site" value="<?= $tour['price_site'] ?? 0 ?>">
                        </div>
                        <div class="price-card c-tripster">
                            <label style="color:#1D4ED8;">Tripster</label>
                            <input type="number" name="price_tripster" value="<?= $tour['price_tripster'] ?? 0 ?>">
                        </div>
                        <div class="price-card c-sputnik">
                            <label style="color:#6B21A8;">Спутник 8</label>
                            <input type="number" name="price_sputnik" value="<?= $tour['price_sputnik'] ?? 0 ?>">
                        </div>
                    </div>

                    <h3 class="section-title" style="margin-top: 30px;">Тексты для сайта (УТП)</h3>
                    <div class="form-group">
                        <label>Что ВКЛЮЧЕНО в стоимость</label>
                        <textarea name="included_text" class="t-input" rows="3"><?= htmlspecialchars($tour['included_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Что НЕ ВКЛЮЧЕНО</label>
                        <textarea name="not_included_text" class="t-input" rows="3"><?= htmlspecialchars($tour['not_included_text'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>FAQ / Важная информация</label>
                        <textarea name="faq_text" class="t-input" rows="3"><?= htmlspecialchars($tour['faq_text'] ?? '') ?></textarea>
                    </div>
                </div>

                <div>
                    <h3 class="section-title">Медиа и Фото</h3>
                    <div class="form-group">
                        <label>Главная обложка тура (.jpg, .png)</label>
                        <?php if(!empty($tour['main_image'])): ?>
                            <img src="<?= htmlspecialchars($tour['main_image']) ?>" id="current_main_img" style="height: 140px; width: 100%; border-radius: var(--radius-sm); object-fit: cover; margin-bottom: 10px; display: block; border:1px solid var(--border);">
                        <?php endif; ?>
                        <input type="file" name="main_image" class="t-input" accept="image/*" style="padding: 9px 12px;">
                    </div>
                    
                    <div class="form-group">
                        <label>Галерея тура (Умная загрузка)</label>
                        <div id="drop-zone" class="drop-zone">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                            <span>Перетащите фото сюда или кликните</span>
                            <input type="file" name="gallery_images[]" id="file-input" multiple accept="image/*" style="display: none;">
                        </div>
                        <div id="local-preview-grid" class="gallery-grid" style="display:none;"></div>

                        <?php if(!empty($gallery)): ?>
                            <div class="gallery-grid" style="margin-top: 15px;">
                                <?php foreach($gallery as $img): ?>
                                    <div class="gal-item">
                                        <img src="<?= htmlspecialchars($img['image_path']) ?>">
                                        <a href="?id=<?= $tour_id ?>&del_gallery_img=<?= $img['id'] ?>" class="gal-del" title="Удалить фото">✕</a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h3 class="section-title" style="margin-top: 30px;">Детали маршрута</h3>
                    
                    <div class="form-group">
                        <label style="color: var(--primary);">⏱ Время старта по умолчанию (для уведомлений)</label>
                        <input type="time" name="default_start_time" class="t-input" value="<?= htmlspecialchars($tour['default_start_time'] ?? '10:00') ?>" style="font-weight: 800; font-size: 16px;">
                    </div>

                    <div class="form-group">
                        <label>Продолжительность (Тайминг)</label>
                        <input type="text" name="duration" id="inp_dur" class="t-input" value="<?= htmlspecialchars($tour['duration'] ?? '') ?>" placeholder="2 часа">
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
                <a href="?id=<?= $tour_id ?>&delete_full_tour=1" class="btn-danger" onclick="return confirm('Вы уверены? Тур и все его этапы будут удалены навсегда!');">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                    Удалить тур целиком
                </a>
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
                                    <button type="button" class="btn-icon edit" onclick="editModule(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['title'])) ?>', '<?= htmlspecialchars(addslashes($m['timing'] ?? '')) ?>')" title="Редактировать"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
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
                    <label>Фотография локации</label>
                    <input type="file" name="module_image" class="t-input" accept="image/*" style="padding: 9px 12px; background: #fff;">
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

<a href="route.php?id=<?= $tour_id ?>" target="_blank" class="preview-btn link-btn">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
    <span>Ссылка для туриста</span>
</a>

<div class="preview-btn" onclick="openPreview()">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
    <span>Предпросмотр</span>
</div>

<div id="previewModal" class="preview-overlay">
    <div class="preview-modal">
        <button class="close-preview" onclick="closePreview()">✕</button>
        <div class="preview-content" id="previewContent"></div>
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
        setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 400); }, 3000);
    }

    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const previewGrid = document.getElementById('local-preview-grid');

    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault(); dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; showLocalPreviews(fileInput.files); }
    });
    fileInput.addEventListener('change', () => showLocalPreviews(fileInput.files));

    function showLocalPreviews(files) {
        previewGrid.innerHTML = ''; previewGrid.style.display = 'flex';
        Array.from(files).forEach(file => {
            if(file.type.startsWith('image/')){
                const reader = new FileReader();
                reader.onload = e => {
                    const div = document.createElement('div');
                    div.className = 'gal-item'; div.innerHTML = `<img src="${e.target.result}">`;
                    previewGrid.appendChild(div);
                }
                reader.readAsDataURL(file);
            }
        });
        showToast(`Выбрано фото: ${files.length}. Сохраните настройки!`, 'success');
    }

    var quill = new Quill('#quill-editor', {
        theme: 'snow', placeholder: 'Напишите текст описания локации...',
        modules: { toolbar: [ ['bold', 'italic'], [{ 'list': 'ordered'}, { 'list': 'bullet' }], ['clean'] ] }
    });

    document.getElementById('ajaxModuleForm').onsubmit = async function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSaveModule');
        btn.textContent = 'Сохранение...'; btn.style.opacity = '0.7';

        var htmlContent = quill.root.innerHTML;
        if (htmlContent === '<p><br></p>') htmlContent = ''; 
        document.getElementById('fContentHidden').value = htmlContent;

        const formData = new FormData(this);
        
        try {
            const response = await fetch('tour_builder.php?id=<?= $tour_id ?>', { method: 'POST', body: formData });
            const result = await response.json();
            if (result.status === 'success') {
                showToast('Этап сохранен!'); renderModuleCard(result.module); resetForm();
            }
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

        let innerHTML = `
            <textarea id="raw_content_${m.id}" style="display:none;">${m.content || ''}</textarea>
            <div class="drag-handle" title="Потяните для сортировки"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg></div>
            ${imgSrc}
            <div class="mod-info">
                <div class="mod-header">
                    <div class="mod-title-wrap">${timingHtml}<h4 class="mod-title">${m.title}</h4></div>
                    <div class="mod-actions">
                        <button type="button" class="btn-icon edit" onclick="editModule(${m.id}, '${safeTitle}', '${safeTiming}')" title="Редактировать"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></button>
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

    function editModule(id, title, timing) {
        document.getElementById('fModuleId').value = id;
        document.getElementById('fTitle').value = title;
        document.getElementById('fTiming').value = timing;
        quill.root.innerHTML = document.getElementById('raw_content_' + id).value;
        document.getElementById('formTitle').textContent = 'Редактировать этап';
        window.scrollTo({ top: document.querySelector('.editor-box').offsetTop - 20, behavior: 'smooth' });
    }

    function resetForm() {
        document.getElementById('fModuleId').value = '0';
        document.getElementById('fTitle').value = ''; document.getElementById('fTiming').value = '';
        quill.root.innerHTML = ''; document.getElementById('formTitle').textContent = 'Добавить этап';
        document.querySelector('input[type=file][name="module_image"]').value = '';
    }

    function openPreview() {
        let name = document.getElementById('inp_public').value || document.getElementById('inp_name').value;
        let dur = document.getElementById('inp_dur').value;
        let price = document.getElementById('inp_price').value;
        let desc = document.getElementById('inp_desc').value.replace(/\n/g, '<br>');
        let coverImg = document.getElementById('current_main_img') ? document.getElementById('current_main_img').src : '';

        let html = '';
        if(coverImg) html += `<img src="${coverImg}" class="prev-cover">`;
        html += `<div class="prev-body">`;
        if(dur) html += `<div class="prev-tag">⏱ ${dur}</div>`;
        html += `<h2 class="prev-title">${name}</h2>`;
        if(price > 0) html += `<div style="font-size:22px; font-weight:800; color:#10B981; margin-bottom:20px;">${new Intl.NumberFormat('ru-RU').format(price)} ₽</div>`;
        if(desc) html += `<div style="font-size:15px; color:var(--text-muted); line-height:1.6; margin-bottom:25px;">${desc}</div>`;

        let modules = document.querySelectorAll('.module-card');
        if(modules.length > 0) {
            html += `<h3 style="font-size:18px; font-weight:800; margin-bottom:20px;">Программа:</h3><div style="position:relative; padding-left:20px; border-left:2px solid #E2E8F0;">`;
            modules.forEach(m => {
                let mTitle = m.querySelector('.mod-title').textContent;
                let mTiming = m.querySelector('.mod-timing') ? m.querySelector('.mod-timing').textContent : '';
                let mText = m.querySelector('textarea').value;
                let mImg = m.querySelector('img.mod-img') ? m.querySelector('img.mod-img').src : '';

                html += `<div style="margin-bottom:25px; position:relative;"><div style="position:absolute; left:-27px; top:4px; width:12px; height:12px; border-radius:50%; background:var(--primary); border:2px solid #fff;"></div>`;
                if(mTiming) html += `<div style="font-size:12px; font-weight:700; color:var(--primary); margin-bottom:4px;">${mTiming}</div>`;
                html += `<h4 style="font-size:16px; font-weight:800; margin:0 0 6px 0;">${mTitle}</h4>`;
                if(mImg) html += `<img src="${mImg}" style="width:100%; height:120px; object-fit:cover; border-radius:8px; margin-bottom:8px;">`;
                html += `<div style="font-size:14px; color:var(--text-muted);">${mText}</div></div>`;
            });
            html += `</div>`;
        }
        html += `</div>`;
        
        document.getElementById('previewContent').innerHTML = html;
        document.getElementById('previewModal').style.display = 'flex';
        setTimeout(() => document.getElementById('previewModal').classList.add('show'), 10);
        document.body.style.overflow = 'hidden';
    }

    function closePreview() {
        document.getElementById('previewModal').classList.remove('show');
        setTimeout(() => { document.getElementById('previewModal').style.display = 'none'; document.body.style.overflow = ''; }, 300);
    }

    document.addEventListener('DOMContentLoaded', () => {
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
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('msg') === 'saved') {
            showToast('Общие настройки сохранены!', 'success');
            window.history.replaceState({}, document.title, window.location.pathname + '?id=<?= $tour_id ?>');
        }
    });
</script>

</body>
</html>