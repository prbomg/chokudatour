<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'db.php'; // Подключаем только базу данных, без авторизации

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die("<h2 style='text-align:center; padding:50px; font-family:sans-serif;'>Билет не найден или ссылка устарела.</h2>");
}

// Ищем туриста по секретному токену
$sql = "SELECT p.*, e.tour_date, e.guide, 
               t.name as tour_name, t.public_name, t.duration, t.coordinates, 
               t.food_options, t.program as tour_program, t.main_image, 
               t.included_text, t.not_included_text, t.faq_text, t.id as tour_id
        FROM participants p
        JOIN events e ON p.event_id = e.id
        JOIN tours_catalog t ON e.tour_id = t.id
        WHERE p.ticket_token = ? LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([$token]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die("<h2 style='text-align:center; padding:50px; font-family:sans-serif;'>Билет не найден или ссылка недействительна.</h2>");
}

// Загружаем этапы маршрута
$stmt_mods = $pdo->prepare("SELECT * FROM tour_modules WHERE tour_id = ? ORDER BY sort_order ASC");
$stmt_mods->execute([$ticket['tour_id']]);
$modules = $stmt_mods->fetchAll(PDO::FETCH_ASSOC);

// Форматируем дату красиво
$months_ru = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$date_timestamp = strtotime($ticket['tour_date']);
$formatted_date = date('j', $date_timestamp) . ' ' . $months_ru[date('n', $date_timestamp)] . ' ' . date('Y', $date_timestamp);

// Функция для красивого вывода списков
function renderList($text, $icon) {
    if (empty(trim($text))) return '';
    $lines = explode("\n", trim($text));
    $html = '<ul style="list-style:none; padding:0; margin:0;">';
    foreach($lines as $line) {
        if(trim($line)) {
            $html .= '<li style="margin-bottom:10px; display:flex; gap:10px; align-items:flex-start;"><span style="flex-shrink:0;">'.$icon.'</span> <span>'.htmlspecialchars(trim($line)).'</span></li>';
        }
    }
    $html .= '</ul>';
    return $html;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Билет: <?= htmlspecialchars($ticket['public_name'] ?: $ticket['tour_name']) ?></title>
    <style>
        :root { --primary: #4F46E5; --bg: #F3F4F6; --text-main: #111827; --text-muted: #6B7280; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 0; -webkit-font-smoothing: antialiased; }
        
        /* Мобильный контейнер */
        .mobile-wrap { max-width: 500px; margin: 0 auto; background: #fff; min-height: 100vh; position: relative; padding-bottom: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.05);}
        
        /* Обложка */
        .cover-img { width: 100%; height: 280px; object-fit: cover; display: block; }
        .no-cover { width: 100%; height: 150px; background: linear-gradient(135deg, #4F46E5, #818CF8); }
        
        /* Основной контент (наезжает на обложку) */
        .content-area { background: #fff; border-radius: 24px 24px 0 0; padding: 25px 20px; margin-top: -30px; position: relative; z-index: 2; }
        
        /* Заголовок билета */
        .welcome-text { font-size: 14px; color: var(--text-muted); margin-bottom: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;}
        .tour-title { font-size: 26px; font-weight: 800; line-height: 1.2; margin: 0 0 15px 0; color: #1E1B4B; }
        .tour-date { display: inline-block; background: #EEF2FF; color: var(--primary); font-weight: 700; padding: 8px 16px; border-radius: 12px; font-size: 15px; margin-bottom: 25px; border: 1px solid #C7D2FE;}

        /* Информационные плашки */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 30px; }
        .info-box { background: #F9FAFB; padding: 15px; border-radius: 12px; border: 1px solid #E5E7EB; }
        .info-box span { display: block; font-size: 12px; color: var(--text-muted); font-weight: 600; margin-bottom: 4px; text-transform: uppercase;}
        .info-box strong { display: block; font-size: 14px; color: var(--text-main); line-height: 1.4; }

        /* Разделитель */
        .section-title { font-size: 20px; font-weight: 800; margin: 35px 0 20px 0; padding-bottom: 10px; border-bottom: 2px solid #F3F4F6; }

        /* Таймлайн программы */
        .timeline { position: relative; margin-top: 20px; padding-left: 20px; border-left: 2px dashed #D1D5DB; margin-left: 10px; }
        .timeline-item { position: relative; margin-bottom: 30px; }
        .timeline-dot { position: absolute; left: -27px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: var(--primary); border: 2px solid #fff; box-shadow: 0 0 0 2px var(--primary);}
        .timeline-time { font-size: 13px; font-weight: 800; color: var(--primary); margin-bottom: 4px; display: inline-block; background: #EEF2FF; padding: 2px 8px; border-radius: 8px;}
        .timeline-title { font-size: 17px; font-weight: 700; margin: 6px 0 8px 0; color: #111827; }
        .timeline-content { font-size: 15px; color: #4B5563; line-height: 1.6; }
        .timeline-content ul, .timeline-content ol { padding-left: 20px; margin: 8px 0; }
        .timeline-img { width: 100%; border-radius: 12px; margin-top: 12px; object-fit: cover; max-height: 250px; }

        /* Блоки с условиями */
        .card-block { background: #F9FAFB; padding: 20px; border-radius: 16px; margin-bottom: 20px; border: 1px solid #E5E7EB; }
        .card-block h4 { margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 8px; }
        .faq-text { font-size: 14px; line-height: 1.6; color: #4B5563; white-space: pre-wrap; }

        .client-name-footer { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 40px; padding-top: 20px; border-top: 1px solid #E5E7EB; }
    </style>
</head>
<body>

<div class="mobile-wrap">
    <?php if ($ticket['main_image']): ?>
        <img src="<?= htmlspecialchars($ticket['main_image']) ?>" class="cover-img" alt="Обложка тура">
    <?php else: ?>
        <div class="no-cover"></div>
    <?php endif; ?>

    <div class="content-area">
        <div class="welcome-text">Маршрутный лист</div>
        <h1 class="tour-title"><?= htmlspecialchars($ticket['public_name'] ?: $ticket['tour_name']) ?></h1>
        <div class="tour-date">🗓 <?= $formatted_date ?></div>

        <div class="info-grid">
            <?php if ($ticket['guide']): ?>
            <div class="info-box">
                <span>Ваш гид</span>
                <strong>🙋‍♂️ <?= htmlspecialchars($ticket['guide']) ?></strong>
            </div>
            <?php endif; ?>
            
            <?php if ($ticket['duration']): ?>
            <div class="info-box">
                <span>Тайминг</span>
                <strong>⏱ <?= htmlspecialchars($ticket['duration']) ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($ticket['coordinates']): ?>
            <div class="info-box" style="grid-column: 1 / -1;">
                <span>Место встречи / Старт</span>
                <strong>📍 <?= htmlspecialchars($ticket['coordinates']) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($modules)): ?>
            <h2 class="section-title">Программа маршрута</h2>
            <div class="timeline">
                <?php foreach ($modules as $m): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <?php if ($m['timing']): ?>
                            <div class="timeline-time"><?= htmlspecialchars($m['timing']) ?></div>
                        <?php endif; ?>
                        <div class="timeline-title"><?= htmlspecialchars($m['title']) ?></div>
                        <?php if ($m['content']): ?>
                            <div class="timeline-content"><?= $m['content'] ?></div>
                        <?php endif; ?>
                        <?php if ($m['image_path']): ?>
                            <img src="<?= htmlspecialchars($m['image_path']) ?>" class="timeline-img" alt="Фото локации">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($ticket['included_text'] || $ticket['not_included_text'] || $ticket['faq_text']): ?>
            <h2 class="section-title">Важная информация</h2>
            
            <?php if ($ticket['included_text']): ?>
                <div class="card-block" style="background: #F0FDF4; border-color: #A7F3D0;">
                    <h4 style="color: #166534;">Что включено в стоимость:</h4>
                    <?= renderList($ticket['included_text'], '✅') ?>
                </div>
            <?php endif; ?>

            <?php if ($ticket['not_included_text']): ?>
                <div class="card-block" style="background: #FEF2F2; border-color: #FECACA;">
                    <h4 style="color: #991B1B;">Что НЕ включено:</h4>
                    <?= renderList($ticket['not_included_text'], '❌') ?>
                </div>
            <?php endif; ?>

            <?php if ($ticket['faq_text']): ?>
                <div class="card-block">
                    <h4>💡 Полезно знать:</h4>
                    <div class="faq-text"><?= htmlspecialchars($ticket['faq_text']) ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="client-name-footer">
            Билет оформлен для: <strong><?= htmlspecialchars($ticket['client_name']) ?></strong> (<?= $ticket['seats'] ?> чел.)<br><br>
            До встречи на маршруте! 👋
        </div>
    </div>
</div>

</body>
</html>