<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';

$phone = $_GET['phone'] ?? '';
if (empty($phone)) {
    header("Location: participants.php");
    exit;
}

// Получаем ВСЮ историю этого клиента по номеру телефона
$stmt = $pdo->prepare("
    SELECT p.*, e.tour_date, t.name AS tour_name, e.guide, e.id AS event_id 
    FROM participants p 
    JOIN events e ON p.event_id = e.id 
    JOIN tours_catalog t ON e.tour_id = t.id 
    WHERE p.phone = ? 
    ORDER BY e.tour_date DESC
");
$stmt->execute([$phone]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($history) === 0) {
    die("<div style='text-align:center; margin-top:50px; font-family:sans-serif; color:#475569;'><h2>Клиент не найден или удален.</h2><a href='participants.php' style='color:#4F46E5;'>Вернуться в базу</a></div>");
}

// Собираем актуальные данные клиента (берем из самой свежей записи)
$client_name = $history[0]['client_name'] ?? 'Неизвестно';
$client_email = '';
foreach ($history as $h) {
    if (!empty($h['email'])) {
        $client_email = $h['email'];
        break;
    }
}

// Подсчитываем аналитику (LTV клиента)
$total_spent = 0;
$total_tours = 0;
$total_seats = 0;
$cancelled_tours = 0;

foreach ($history as $h) {
    if (($h['status'] ?? '') !== 'Отмена') {
        $total_spent += (int)($h['price'] ?? 0);
        $total_seats += (int)($h['seats'] ?? 0);
        $total_tours++;
    } else {
        $cancelled_tours++;
    }
}

// Форматируем телефон для ссылок
$clean_phone = preg_replace('/[^0-9]/', '', $phone);
if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { 
    $clean_phone = '7' . substr($clean_phone, 1); 
}

// Функция для генерации цвета гида
function getGuideColorStyle($guideName) {
    if (empty($guideName) || $guideName === 'Не назначен') return "background: #F1F5F9; color: #475569; border-color: transparent;";
    $hash = substr(md5($guideName), 0, 6);
    $hue = hexdec($hash) % 360; 
    return "background: hsl({$hue}, 85%, 94%); color: hsl({$hue}, 85%, 25%); border-color: transparent;";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($client_name) ?> — Профиль клиента</title>
    <style>
        /* ПРЕМИУМ ДИЗАЙН (Единый стиль CRM) */
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --shadow-float: 0 10px 30px -5px rgba(0,0,0,0.08);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; -webkit-font-smoothing: antialiased; letter-spacing: -0.01em;}
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box;}
        
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

        /* Профиль клиента (Header) */
        .profile-header { background: var(--card-bg); border-radius: var(--radius-lg); padding: 30px; margin-bottom: 30px; box-shadow: var(--shadow-md); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px; border: 1px solid rgba(255,255,255,0.8);}
        .profile-info { display: flex; align-items: center; gap: 20px; }
        .profile-avatar { width: 70px; height: 70px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 800; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.2);}
        .profile-details h1 { margin: 0 0 5px 0; font-size: 26px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;}
        .profile-contacts { display: flex; align-items: center; gap: 15px; font-size: 14px; color: var(--text-muted); font-weight: 500;}
        .contact-item { display: flex; align-items: center; gap: 5px; }

        .profile-actions { display: flex; gap: 10px; }
        .btn-call { background: #E0F2FE; color: #0284C7; padding: 10px 20px; border-radius: var(--radius-sm); font-weight: 700; text-decoration: none; transition: var(--transition); display: flex; align-items: center; gap: 8px; font-size: 14px;}
        .btn-call:hover { background: #BAE6FD; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(2, 132, 199, 0.15);}
        .btn-whatsapp { background: #DCFCE7; color: #16A34A; padding: 10px 20px; border-radius: var(--radius-sm); font-weight: 700; text-decoration: none; transition: var(--transition); display: flex; align-items: center; gap: 8px; font-size: 14px;}
        .btn-whatsapp:hover { background: #BBF7D0; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(22, 163, 74, 0.15);}

        /* Дашборды аналитики */
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 22px; box-shadow: var(--shadow-md); transition: var(--transition); position: relative; overflow: hidden;}
        .dash-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-float); }
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; background: var(--border);}
        .dash-card.profit::before { background: #10B981; }
        .dash-card.blue::before { background: var(--primary); }
        .dash-card.warning::before { background: #F59E0B; }
        .dash-title { font-size: 12px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;}
        .dash-val { font-size: 26px; font-weight: 800; color: var(--text-main); }
        .val-green { color: #10B981; } 

        .section-title { font-size: 20px; font-weight: 800; margin: 0 0 15px 0; color: var(--text-main); letter-spacing: -0.01em;}

        /* Таблица истории */
        .table-responsive { width: 100%; overflow-x: auto; max-height: 65vh; overflow-y: auto; background: var(--card-bg); border-radius: var(--radius-lg); box-shadow: var(--shadow-md);}
        table { width: 100%; min-width: 1000px; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 16px 20px; text-align: left; font-size: 14px; vertical-align: middle; border-bottom: 1px solid #F1F5F9;}
        th { position: sticky; top: 0; z-index: 10; background-color: rgba(255,255,255,0.95); backdrop-filter: blur(8px); font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; box-shadow: 0 1px 0 #F1F5F9; letter-spacing: 0.05em;}
        tr:hover td { background-color: #F8FAFC; }
        tr:last-child td { border-bottom: none; }
        
        .col-price { white-space: nowrap; width: 100px; font-weight: 700; font-size: 15px; color: #10B981;}
        .col-note { width: 180px; }
        
        .note-truncate { max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; color: var(--text-muted); font-size: 13px; padding: 6px 8px; border-radius: 6px; transition: var(--transition); border: 1px dashed transparent;}
        .note-truncate:hover { background: var(--card-bg); color: var(--text-main); border-color: #CBD5E1; box-shadow: var(--shadow-sm);}

        .link-tour { color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 14px; transition: var(--transition); } 
        .link-tour:hover { color: var(--primary); }

        .guide-tag { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; white-space: nowrap; display: inline-block;}
        .seats-badge { background: #F1F5F9; color: #475569; font-weight: 700; padding: 4px 12px; border-radius: 12px; font-size: 13px;}
        
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 99px; font-size: 12px; font-weight: 700; background: #F1F5F9; color: var(--text-muted); }
        .status-<?php echo md5('Бронь'); ?> { background: #FEF3C7; color: #B45309; }
        .status-<?php echo md5('Предоплата'); ?> { background: #DBEAFE; color: #1D4ED8; }
        .status-<?php echo md5('Оплачено'); ?> { background: #D1FAE5; color: #047857; }
        .status-<?php echo md5('Отмена'); ?> { background: #FEE2E2; color: #B91C1C; text-decoration: line-through; }

        .action-cell { display: flex; gap: 8px; justify-content: flex-end; align-items: center; }
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: var(--radius-sm); font-size: 14px; border: none; cursor: pointer; transition: var(--transition); background: #F0FDF4; color: #10B981; text-decoration: none;}
        .btn-icon:hover { background: #DCFCE7; color: #047857; transform: translateY(-1px); box-shadow: var(--shadow-sm);}

        /* Модалка для примечания */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.4); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(4px); padding: 20px; box-sizing: border-box;}
        .modal-content { background: var(--card-bg); padding: 30px; border-radius: 24px; max-width: 420px; width: 100%; box-shadow: 0 20px 40px rgba(0,0,0,0.2); transform: scale(0.95); animation: modalIn 0.2s forwards cubic-bezier(0.4, 0, 0.2, 1);}
        @keyframes modalIn { to { transform: scale(1); } }
        .modal-content h3 { font-size: 20px; font-weight: 800; margin-bottom: 20px;}
        .btn-cancel { background: transparent; color: var(--text-muted); padding: 14px; border: 1px solid var(--border); border-radius: var(--radius-md); font-weight: 600; font-size: 15px; width: 100%; cursor: pointer; transition: var(--transition);}
        .btn-cancel:hover { background: #F8FAFC; color: var(--text-main); }

        @media (max-width: 768px) {
            body { padding: 10px; } .container { padding: 10px; }
            .profile-header { flex-direction: column; align-items: stretch; text-align: center;}
            .profile-info { flex-direction: column; gap: 10px; }
            .profile-contacts { flex-direction: column; gap: 5px; justify-content: center; }
            .profile-actions { justify-content: center; width: 100%; margin-top: 10px; flex-direction: column;}
            .btn-call, .btn-whatsapp { justify-content: center; width: 100%;}
            .dash-grid { grid-template-columns: 1fr 1fr; }
            .dash-val { font-size: 20px; }
        }
    </style>
</head>
<body>

<div class="container">
    <?php include 'navbar.php'; ?>

    <a href="participants.php" class="back-link">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Назад в базу
    </a>

    <div class="profile-header">
        <div class="profile-info">
            <div class="profile-avatar">
                <?= mb_strtoupper(mb_substr($client_name, 0, 1)) ?>
            </div>
            <div class="profile-details">
                <h1><?= htmlspecialchars($client_name) ?></h1>
                <div class="profile-contacts">
                    <div class="contact-item">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                        <?= htmlspecialchars($phone) ?>
                    </div>
                    <?php if (!empty($client_email)): ?>
                    <div class="contact-item">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                        <?= htmlspecialchars($client_email) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="profile-actions">
            <a href="tel:<?= htmlspecialchars($phone) ?>" class="btn-call">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                Позвонить
            </a>
            <a href="https://wa.me/<?= $clean_phone ?>" target="_blank" class="btn-whatsapp">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.3 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                WhatsApp
            </a>
        </div>
    </div>

    <div class="dash-grid">
        <div class="dash-card profit">
            <div class="dash-title">Сумма покупок (LTV)</div>
            <div class="dash-val val-green"><?= number_format($total_spent, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card blue">
            <div class="dash-title">Успешных туров</div>
            <div class="dash-val"><?= $total_tours ?></div>
        </div>
        <div class="dash-card">
            <div class="dash-title">Куплено мест</div>
            <div class="dash-val"><?= $total_seats ?> шт.</div>
        </div>
        <?php if ($cancelled_tours > 0): ?>
        <div class="dash-card warning">
            <div class="dash-title">Отмененных записей</div>
            <div class="dash-val val-red"><?= $cancelled_tours ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="section-title">История поездок</div>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th style="min-width: 100px;">Дата</th>
                        <th style="min-width: 180px;">Название тура</th>
                        <th>Гид</th>
                        <th>Мест</th>
                        <th>Сумма (₽)</th>
                        <th>Источник</th>
                        <th>Статус</th>
                        <th>Примечание</th>
                        <th style="text-align: right;">Перейти</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): 
                        $date_str = date('d.m.Y', strtotime($h['tour_date']));
                        $opacity = ($h['status'] ?? '') === 'Отмена' ? '0.5' : '1';
                        $guide_style = getGuideColorStyle($h['guide']);
                        $price_str = number_format($h['price'] ?? 0, 0, '', ' ') . ' ₽';
                        $note_html = !empty($h['notes']) ? "<div class='note-truncate' data-note='".htmlspecialchars($h['notes'], ENT_QUOTES)."' onclick=\"showNoteModal(this.getAttribute('data-note'))\">" . htmlspecialchars($h['notes']) . "</div>" : "—";
                    ?>
                    <tr style="opacity: <?= $opacity ?>;">
                        <td><strong style="color:var(--text-main);"><?= $date_str ?></strong></td>
                        <td><a href="event.php?id=<?= $h['event_id'] ?>" class="link-tour"><?= htmlspecialchars($h['tour_name']) ?></a></td>
                        <td><span class="guide-tag" style="<?= $guide_style ?>"><?= htmlspecialchars($h['guide'] ?: 'Не назначен') ?></span></td>
                        <td><span class="seats-badge"><?= htmlspecialchars($h['seats'] ?? '0') ?></span></td>
                        <td class="col-price"><?= $price_str ?></td>
                        <td style="color: var(--text-muted); font-size: 13px; font-weight:600;"><?= htmlspecialchars($h['source'] ?? '') ?></td>
                        <td><span class="status-badge status-<?= md5($h['status'] ?? '') ?>"><?= htmlspecialchars($h['status'] ?? '') ?></span></td>
                        <td class="col-note"><?= $note_html ?></td>
                        <td style="text-align: right;">
                            <div class="action-cell">
                                <a href="event.php?id=<?= $h['event_id'] ?>" class="btn-icon btn-view" title="Открыть карточку тура">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
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

<div class="modal-overlay" id="noteModal">
    <div class="modal-content">
        <h3>Примечание к бронированию</h3>
        <p id="noteModalText" style="font-size:15px; line-height:1.6; white-space:pre-wrap; color:var(--text-main); margin-bottom:25px;"></p>
        <button type="button" class="btn-cancel" style="margin-top:0;" onclick="document.getElementById('noteModal').style.display='none'">Закрыть</button>
    </div>
</div>

<script>
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('mousedown', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });

    function showNoteModal(text) {
        document.getElementById('noteModalText').textContent = text;
        document.getElementById('noteModal').style.display = 'flex';
    }
</script>

</body>
</html>