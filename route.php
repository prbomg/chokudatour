<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Подключаем только базу, это публичная страница
require_once 'db.php';

$tour_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($tour_id === 0) {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif; color: #0F172A;'>Маршрут не найден.</h2>");
}

$stmt = $pdo->prepare("SELECT * FROM tours_catalog WHERE id = ?");
$stmt->execute([$tour_id]);
$tour = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tour) {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif; color: #0F172A;'>Извините, этот маршрут больше не доступен.</h2>");
}

// Данные для вывода
$title = !empty($tour['public_name']) ? $tour['public_name'] : $tour['name'];
$description = $tour['description'] ?? '';

// Читаем модули этапов из правильной таблицы!
$stmt_mod = $pdo->prepare("SELECT * FROM tour_modules WHERE tour_id = ? ORDER BY sort_order ASC, id ASC");
$stmt_mod->execute([$tour_id]);
$modules = $stmt_mod->fetchAll(PDO::FETCH_ASSOC);

$tour_type = !empty($tour['tour_type']) ? $tour['tour_type'] : 'Индивидуальная';
$duration = !empty($tour['duration']) ? $tour['duration'] : 'Не указано';
$difficulty = !empty($tour['difficulty']) ? $tour['difficulty'] : 'Легкая';
$start_time = !empty($tour['default_start_time']) ? $tour['default_start_time'] : 'По договоренности';

// Ищем цену для сайта
$stmt_src = $pdo->prepare("SELECT id FROM booking_sources WHERE name = 'Сайт' LIMIT 1");
$stmt_src->execute();
$source_id = $stmt_src->fetchColumn() ?: -1;

$prices = json_decode($tour['prices'], true) ?: [];
$price = isset($prices[$source_id]) ? $prices[$source_id] : (isset($prices[-1]) ? $prices[-1] : 0);

// Парсим списки "Включено" / "Не включено" / "FAQ"
$included = json_decode($tour['included_text'] ?? '[]', true);
if (!is_array($included)) $included = trim($tour['included_text']) ? [trim($tour['included_text'])] : [];

$not_included = json_decode($tour['not_included_text'] ?? '[]', true);
if (!is_array($not_included)) $not_included = trim($tour['not_included_text']) ? [trim($tour['not_included_text'])] : [];

$faq_items = json_decode($tour['faq_text'] ?? '[]', true);
if (!is_array($faq_items)) {
    $old_faq = trim($tour['faq_text'] ?? '');
    $faq_items = $old_faq ? [['q' => 'Информация', 'a' => $old_faq]] : [];
}

// Галерея (собираем все фото)
$images = json_decode($tour['images'], true) ?: [];
$main_img = !empty($tour['main_image']) ? $tour['main_image'] : 'https://images.unsplash.com/photo-1469854523086-cc02fe5d8800?auto=format&fit=crop&w=1600&q=80';

$gallery = array_filter($images, function($img) use ($tour) {
    return $img !== $tour['main_image'];
});

$price_label = ($tour_type === 'Групповая') ? 'Стоимость за человека' : 'Стоимость за экскурсию';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($title) ?> — Бронирование экскурсии</title>
    <style>
        :root { 
            --primary: #4F46E5; 
            --primary-hover: #4338CA; 
            --primary-light: #EEF2FF;
            --bg: #F8FAFC; 
            --card-bg: #FFFFFF; 
            --border: #E2E8F0; 
            --text-main: #0F172A; 
            --text-muted: #475569;
            --radius-xl: 24px;
            --radius-lg: 16px; 
            --radius-md: 12px; 
            --radius-sm: 8px;
            --shadow-sm: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 0; -webkit-font-smoothing: antialiased; padding-bottom: 90px;}
        
        /* Главный баннер */
        .hero { position: relative; width: 100%; height: 50vh; min-height: 400px; background-image: url('<?= htmlspecialchars($main_img) ?>'); background-size: cover; background-position: center; display: flex; align-items: flex-end; }
        .hero-overlay { position: absolute; inset: 0; background: linear-gradient(to top, rgba(15, 23, 42, 0.9) 0%, rgba(15, 23, 42, 0.4) 50%, rgba(15, 23, 42, 0.1) 100%); }
        .hero-content { position: relative; z-index: 1; max-width: 900px; margin: 0 auto; padding: 40px 20px; width: 100%; box-sizing: border-box; }
        .hero-title { font-size: 42px; font-weight: 900; color: white; margin: 0 0 12px 0; line-height: 1.1; letter-spacing: -0.02em; }
        .hero-desc { font-size: 18px; color: #E2E8F0; margin: 0; font-weight: 500; line-height: 1.5; max-width: 700px; }

        .container { max-width: 900px; margin: 0 auto; padding: 40px 20px; box-sizing: border-box; }

        /* Плашка с характеристиками */
        .features-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 20px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); margin-top: -60px; position: relative; z-index: 10; margin-bottom: 40px;}
        .feature-item { display: flex; align-items: center; gap: 12px; }
        .feature-icon { width: 42px; height: 42px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .feature-text { display: flex; flex-direction: column; }
        .feature-label { font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 2px;}
        .feature-val { font-size: 15px; font-weight: 700; color: var(--text-main); }

        .content-section { background: var(--card-bg); padding: 40px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); border: 1px solid var(--border); margin-bottom: 40px; }
        .section-title { font-size: 24px; font-weight: 800; margin: 0 0 24px 0; color: var(--text-main); display: flex; align-items: center; gap: 10px;}
        
        .timeline { display: flex; flex-direction: column; gap: 20px; position: relative; margin-top: 10px; }
        .timeline::before { content: ''; position: absolute; top: 0; left: 19px; width: 2px; height: 100%; background: #E2E8F0; z-index: 0; }
        .timeline-item { display: flex; gap: 20px; position: relative; z-index: 1; }
        .timeline-marker { width: 40px; height: 40px; background: var(--primary-light); color: var(--primary); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0; border: 4px solid #fff; box-shadow: 0 0 0 1px var(--border); }
        .timeline-content { background: #F8FAFC; border: 1px solid var(--border); border-radius: var(--radius-md); padding: 16px 20px; flex: 1; overflow: hidden;}
        .timeline-time { font-size: 12px; font-weight: 700; color: var(--primary); margin-bottom: 6px; display: inline-block; background: var(--primary-light); padding: 3px 8px; border-radius: 4px;}
        .timeline-title { font-size: 16px; font-weight: 800; color: var(--text-main); margin: 0 0 12px 0; }
        
        /* ИСПРАВЛЕНО: Теперь фото сохраняют естественные пропорции */
        .timeline-img { width: 100%; max-height: 450px; height: auto; object-fit: cover; object-position: center; border-radius: var(--radius-md); margin-bottom: 16px; border: 1px solid var(--border); display: block;}
        
        .timeline-desc { font-size: 14px; color: var(--text-muted); line-height: 1.6; margin: 0; }
        .timeline-desc p { margin: 0 0 10px 0; }
        .timeline-desc p:last-child { margin: 0; }

        .inc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;}
        .inc-box { background: var(--card-bg); padding: 25px; border-radius: var(--radius-md); border: 1px solid var(--border); box-shadow: var(--shadow-sm);}
        .inc-box h4 { margin: 0 0 15px 0; font-size: 16px; font-weight: 800; color: var(--text-main); display: flex; align-items: center; gap: 8px;}
        .inc-list { list-style: none; padding: 0; margin: 0; }
        .inc-list li { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; font-size: 15px; color: var(--text-muted); line-height: 1.4;}
        .inc-list li svg { flex-shrink: 0; margin-top: 2px; }

        .faq-accordion { display: flex; flex-direction: column; }
        .faq-item { border-bottom: 1px solid var(--border); transition: var(--transition); }
        .faq-item:last-child { border-bottom: none; }
        .faq-question { padding: 20px 0; font-weight: 700; font-size: 16px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; color: var(--text-main); user-select: none; transition: var(--transition);}
        .faq-question:hover { color: var(--primary); }
        .faq-answer { padding-bottom: 20px; color: var(--text-muted); display: none; line-height: 1.6; font-size: 15px; }
        .faq-item.active .faq-answer { display: block; animation: fadeIn 0.3s ease; }
        .faq-item.active .faq-icon { transform: rotate(180deg); color: var(--primary); }
        .faq-icon { transition: transform 0.3s ease; color: #94A3B8; flex-shrink: 0; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }

        .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; margin-bottom: 40px; }
        .gallery-img { width: 100%; height: 250px; object-fit: cover; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); transition: transform 0.3s ease; border: 1px solid var(--border);}
        .gallery-img:hover { transform: scale(1.02); z-index: 2; position: relative; }

        .sticky-book-bar { position: fixed; bottom: 0; left: 0; width: 100%; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px); border-top: 1px solid var(--border); padding: 16px 20px; box-sizing: border-box; z-index: 100; box-shadow: 0 -4px 20px rgba(0,0,0,0.05); display: flex; justify-content: center;}
        .sticky-content { max-width: 900px; width: 100%; display: flex; justify-content: space-between; align-items: center; }
        .price-box { display: flex; flex-direction: column; }
        .price-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;}
        .price-val { font-size: 22px; font-weight: 900; color: var(--text-main); }
        .btn-book { background: var(--primary); color: white; border: none; padding: 14px 32px; border-radius: 99px; font-size: 16px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3); cursor: pointer;}
        .btn-book:hover { background: var(--primary-hover); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(79, 70, 229, 0.4);}

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box; }
        .modal-container { background: transparent; max-width: 500px; width: 100%; position: relative; border-radius: var(--radius-lg); overflow: hidden; animation: modalPop 0.3s ease-out; }
        .modal-close { position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.5); color: white; border: none; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 10001; transition: 0.2s; font-size: 16px; font-weight: bold;}
        .modal-close:hover { background: rgba(0,0,0,0.8); transform: scale(1.1); }
        .modal-iframe { width: 100%; height: 650px; border: none; border-radius: var(--radius-lg); background: white; display: block; }
        
        @keyframes modalPop { 0% { opacity: 0; transform: translateY(20px) scale(0.95); } 100% { opacity: 1; transform: translateY(0) scale(1); } }

        @media (max-width: 768px) {
            .hero { height: 60vh; }
            .hero-title { font-size: 32px; }
            .hero-desc { font-size: 15px; }
            .content-section { padding: 25px 20px; }
            .features-card { margin-top: -30px; grid-template-columns: 1fr 1fr; gap: 15px; padding: 20px;}
            .inc-grid { grid-template-columns: 1fr; gap: 15px; }
            body { padding-bottom: 110px; }
            .sticky-book-bar { padding: 12px 16px; }
            .btn-book { padding: 12px 24px; font-size: 15px; }
            .modal-iframe { height: 80vh; }
        }
        @media (max-width: 480px) {
            .features-card { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <header class="hero">
        <div class="hero-overlay"></div>
        <div class="hero-content">
            <h1 class="hero-title"><?= htmlspecialchars($title) ?></h1>
            <?php if($description): ?>
                <p class="hero-desc"><?= htmlspecialchars($description) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">
        <div class="features-card">
            <div class="feature-item">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="feature-text">
                    <span class="feature-label">Длительность</span>
                    <span class="feature-val"><?= htmlspecialchars($duration) ?></span>
                </div>
            </div>
            
            <div class="feature-item">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </div>
                <div class="feature-text">
                    <span class="feature-label">Формат</span>
                    <span class="feature-val"><?= htmlspecialchars($tour_type) ?></span>
                </div>
            </div>

            <div class="feature-item">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                </div>
                <div class="feature-text">
                    <span class="feature-label">Сложность</span>
                    <span class="feature-val"><?= htmlspecialchars($difficulty) ?></span>
                </div>
            </div>

            <div class="feature-item">
                <div class="feature-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h20"></path><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                </div>
                <div class="feature-text">
                    <span class="feature-label">Старт</span>
                    <span class="feature-val"><?= htmlspecialchars($start_time) ?></span>
                </div>
            </div>
        </div>

        <?php if(!empty($modules)): ?>
        <section class="content-section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                Программа маршрута
            </h2>
            <div class="timeline">
                <?php $i = 1; foreach($modules as $step): ?>
                <div class="timeline-item">
                    <div class="timeline-marker"><?= $i++ ?></div>
                    <div class="timeline-content">
                        <?php if(!empty($step['timing'])): ?>
                            <span class="timeline-time"><?= htmlspecialchars($step['timing']) ?></span>
                        <?php endif; ?>
                        <h3 class="timeline-title"><?= htmlspecialchars($step['title']) ?></h3>
                        
                        <?php if(!empty($step['image_path']) && file_exists($step['image_path'])): ?>
                            <img src="<?= htmlspecialchars($step['image_path']) ?>" class="timeline-img" alt="Фото этапа" loading="lazy">
                        <?php endif; ?>

                        <div class="timeline-desc rich-text">
                            <?= $step['content'] ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if(!empty($included) || !empty($not_included)): ?>
        <section class="content-section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line></svg>
                Стоимость и условия
            </h2>
            
            <div class="inc-grid">
                <?php if(!empty($included)): ?>
                <div class="inc-box">
                    <h4>Включено в стоимость</h4>
                    <ul class="inc-list">
                        <?php foreach($included as $item): ?>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                <?= htmlspecialchars($item) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if(!empty($not_included)): ?>
                <div class="inc-box">
                    <h4>Не включено (по желанию)</h4>
                    <ul class="inc-list">
                        <?php foreach($not_included as $item): ?>
                            <li>
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                <?= htmlspecialchars($item) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if(!empty($gallery)): ?>
        <section>
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                Фотографии маршрута
            </h2>
            <div class="gallery-grid">
                <?php foreach($gallery as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="Фото экскурсии" class="gallery-img" loading="lazy">
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if(!empty($faq_items)): ?>
        <section class="content-section" style="margin-top: 40px;">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                Частые вопросы
            </h2>
            <div class="faq-accordion">
                <?php foreach($faq_items as $item): ?>
                <div class="faq-item">
                    <div class="faq-question" onclick="this.parentElement.classList.toggle('active')">
                        <span><?= htmlspecialchars($item['q']) ?></span>
                        <svg class="faq-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                    <div class="faq-answer">
                        <?= nl2br(htmlspecialchars($item['a'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

    </div>

    <div class="sticky-book-bar">
        <div class="sticky-content">
            <div class="price-box">
                <span class="price-label"><?= $price_label ?></span>
                <span class="price-val"><?= number_format($price, 0, '', ' ') ?> ₽</span>
            </div>
            <button onclick="openBookingModal()" class="btn-book">
                Выбрать дату
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="M12 5l7 7-7 7"></path></svg>
            </button>
        </div>
    </div>

    <div id="bookingModal" class="modal-overlay" onclick="if(event.target === this) closeBookingModal()">
        <div class="modal-container">
            <button class="modal-close" onclick="closeBookingModal()">✕</button>
            <iframe src="widget.php?tour_id=<?= $tour_id ?>" class="modal-iframe"></iframe>
        </div>
    </div>

    <script>
        function openBookingModal() {
            const modal = document.getElementById('bookingModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden'; 
        }

        function closeBookingModal() {
            const modal = document.getElementById('bookingModal');
            modal.style.display = 'none';
            document.body.style.overflow = ''; 
        }
    </script>

</body>
</html>