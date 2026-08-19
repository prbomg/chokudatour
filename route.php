<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once 'db.php'; // или auth.php, если нужно подключение к базе

$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tour_id === 0) { die("Маршрут не найден."); }

$stmt = $pdo->prepare("SELECT * FROM tours_catalog WHERE id = ?");
$stmt->execute([$tour_id]);
$tour = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tour) { die("Маршрут не найден."); }

$gallery = $pdo->query("SELECT * FROM tour_gallery WHERE tour_id = $tour_id ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$modules = $pdo->query("SELECT * FROM tour_modules WHERE tour_id = $tour_id ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Маршрутная карта: <?= htmlspecialchars($tour['public_name'] ?: $tour['name']) ?></title>
    <style>
        :root { --primary: #4F46E5; --bg: #F8FAFC; --card-bg: #FFFFFF; --text-main: #0F172A; --text-muted: #64748B; --border: #E2E8F0; }
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 0; line-height: 1.6;}
        .route-container { max-width: 700px; margin: 0 auto; background: #fff; min-height: 100vh; box-shadow: 0 0 40px rgba(0,0,0,0.05); box-sizing: border-box; padding-bottom: 50px;}
        .r-cover { width: 100%; height: 320px; object-fit: cover; background: #CBD5E1; }
        .r-body { padding: 30px 25px; }
        .r-tag { display: inline-block; background: #EEF2FF; color: var(--primary); padding: 6px 14px; border-radius: 99px; font-size: 13px; font-weight: 800; margin-bottom: 15px;}
        .r-title { font-size: 28px; font-weight: 900; margin: 0 0 10px 0; color: var(--text-main); line-height: 1.2;}
        .r-desc { font-size: 15px; color: var(--text-muted); margin-bottom: 30px;}
        
        .r-box { background: #F8FAFC; border: 1px solid var(--border); border-radius: 16px; padding: 20px; margin-bottom: 25px;}
        .r-box h3 { margin: 0 0 12px 0; font-size: 16px; font-weight: 800; color: var(--text-main); display:flex; align-items:center; gap:8px;}
        .r-list { margin: 0; padding-left: 20px; color: var(--text-muted); font-size: 14px;}
        .r-list li { margin-bottom: 8px;}

        /* Таймлайн программы */
        .timeline { position: relative; padding-left: 24px; margin-top: 20px;}
        .timeline::before { content:''; position: absolute; top: 8px; left: 7px; width: 2px; height: calc(100% - 16px); background: #E2E8F0;}
        .t-item { position: relative; margin-bottom: 35px;}
        .t-dot { position: absolute; left: -24px; top: 4px; width: 14px; height: 14px; border-radius: 50%; background: var(--primary); border: 3px solid #fff; box-shadow: 0 0 0 2px var(--primary-light);}
        .t-timing { font-size: 12px; font-weight: 800; color: var(--primary); text-transform: uppercase; margin-bottom: 4px;}
        .t-title { font-size: 18px; font-weight: 800; color: var(--text-main); margin: 0 0 8px 0;}
        .t-img { width: 100%; height: 200px; object-fit: cover; border-radius: 12px; margin-bottom: 12px; border: 1px solid var(--border);}
        .t-text { font-size: 14px; color: var(--text-muted); line-height: 1.5;}
        
        .r-footer { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 40px; border-top: 1px solid var(--border); padding-top: 20px;}
    </style>
</head>
<body>

<div class="route-container">
    <?php if(!empty($tour['main_image']) && file_exists($tour['main_image'])): ?>
        <img src="<?= htmlspecialchars($tour['main_image']) ?>" class="r-cover">
    <?php endif; ?>

    <div class="r-body">
        <?php if(!empty($tour['duration'])): ?>
            <span class="r-tag">⏱ Продолжительность: <?= htmlspecialchars($tour['duration']) ?></span>
        <?php endif; ?>
        
        <h1 class="r-title"><?= htmlspecialchars($tour['public_name'] ?: $tour['name']) ?></h1>
        
        <?php if(!empty($tour['description'])): ?>
            <div class="r-desc"><?= nl2br(htmlspecialchars($tour['description'])) ?></div>
        <?php endif; ?>

        <?php if(!empty($tour['included_text']) || !empty($tour['not_included_text'])): ?>
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px;">
            <?php if(!empty($tour['included_text'])): ?>
                <div class="r-box" style="margin:0; background:#ECFDF5; border-color:#A7F3D0;">
                    <h3 style="color:#047857;">✅ Включено</h3>
                    <ul class="r-list" style="color:#065F46;">
                        <?php foreach(explode("\n", $tour['included_text']) as $inc) if(trim($inc)) echo "<li>".htmlspecialchars(trim($inc))."</li>"; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if(!empty($tour['not_included_text'])): ?>
                <div class="r-box" style="margin:0; background:#FEF2F2; border-color:#FECACA;">
                    <h3 style="color:#DC2626;">❌ Не включено</h3>
                    <ul class="r-list" style="color:#B91C1C;">
                        <?php foreach(explode("\n", $tour['not_included_text']) as $exc) if(trim($exc)) echo "<li>".htmlspecialchars(trim($exc))."</li>"; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($tour['faq_text'])): ?>
            <div class="r-box">
                <h3>📌 Важная информация (FAQ)</h3>
                <div style="font-size:14px; color:var(--text-muted); line-height:1.5;"><?= nl2br(htmlspecialchars($tour['faq_text'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if(!empty($modules)): ?>
            <h2 style="font-size: 22px; font-weight: 800; margin: 35px 0 20px 0;">Программа экскурсии</h2>
            <div class="timeline">
                <?php foreach($modules as $m): ?>
                    <div class="t-item">
                        <div class="t-dot"></div>
                        <?php if(!empty($m['timing'])): ?><div class="t-timing"><?= htmlspecialchars($m['timing']) ?></div><?php endif; ?>
                        <h4 class="t-title"><?= htmlspecialchars($m['title']) ?></h4>
                        <?php if(!empty($m['image_path']) && file_exists($m['image_path'])): ?>
                            <img src="<?= htmlspecialchars($m['image_path']) ?>" class="t-img">
                        <?php endif; ?>
                        <?php if(!empty($m['content'])): ?>
                            <div class="t-text"><?= $m['content'] ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="r-footer">
            Авторские туры • Маршрутная карта путешественника
        </div>
    </div>
</div>

</body>
</html>