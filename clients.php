<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';

// --- ПОИСК ---
$search = trim($_GET['search'] ?? '');
$params = [];

// Умный SQL-запрос: группируем туристов по номеру телефона, 
// считаем общее количество поездок и сумму покупок (LTV), исключая отмены.
$sql = "SELECT 
            MAX(client_name) AS client_name, 
            phone, 
            MAX(email) AS email, 
            COUNT(id) AS total_tours, 
            SUM(CASE WHEN status != 'Отмена' THEN price ELSE 0 END) AS ltv
        FROM participants 
        WHERE 1=1 ";

// Ограничение для гидов (видят только тех клиентов, которые были у них на турах)
if ($current_user_role !== 'admin') {
    $sql .= " AND event_id IN (SELECT id FROM events WHERE guide = ?) ";
    $params[] = $_SESSION['user_name'];
}

if ($search !== '') {
    $sql .= " AND (client_name LIKE ? OR phone LIKE ? OR email LIKE ?) ";
    array_push($params, "%$search%", "%$search%", "%$search%");
}

// Группируем по телефону и сортируем по LTV (сначала самые "дорогие" клиенты)
$sql .= " GROUP BY phone ORDER BY ltv DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Подсчет статистики для дашбордов
$total_unique_clients = count($clients);
$total_ltv = 0;
foreach ($clients as $c) {
    $total_ltv += (int)$c['ltv'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Клиентская база — CRM</title>
    <style>
        /* ПРЕМИУМ ДИЗАЙН (Soft UI & Glassmorphism) */
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

        .navbar { display: flex; gap: 15px; margin-bottom: 30px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;}
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em;}
        
        /* Дашборды */
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 22px; box-shadow: var(--shadow-md); transition: var(--transition); position: relative; overflow: hidden;}
        .dash-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-float); }
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; background: var(--border);}
        .dash-card.profit::before { background: #10B981; }
        .dash-card.blue::before { background: var(--primary); }
        .dash-title { font-size: 12px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;}
        .dash-val { font-size: 26px; font-weight: 800; color: var(--text-main); }
        .val-green { color: #10B981; } 

        /* Поисковая панель */
        .search-box { display: flex; gap: 12px; background: var(--card-bg); padding: 20px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); margin-bottom: 30px; flex-wrap: wrap; align-items: flex-end; }
        .search-group { flex: 1; min-width: 280px; }
        .search-input-wrapper { position: relative; width: 100%; display: flex; align-items: center; }
        .search-input-wrapper svg { position: absolute; left: 16px; color: var(--text-muted); width: 18px; height: 18px; pointer-events: none;}
        .search-input-wrapper input { 
            width: 100%; padding: 12px 16px 12px 46px; border: 1px solid var(--border); 
            border-radius: var(--radius-sm); font-size: 14px; outline: none; transition: var(--transition); 
            background: #F8FAFC; box-sizing: border-box; font-weight: 500; color: var(--text-main); height: 44px;
        }
        .search-input-wrapper input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }
        
        .btn-filter { background: var(--primary); color: white; padding: 0 28px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); font-size: 14px; height: 44px; display: inline-flex; align-items: center; justify-content: center;}
        .btn-filter:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}
        .btn-reset { display: inline-flex; align-items: center; justify-content: center; background: #FEE2E2; color: #DC2626; padding: 0 24px; border-radius: var(--radius-sm); font-weight: 700; font-size: 14px; text-decoration: none; transition: var(--transition); height: 44px;}
        .btn-reset:hover { background: #FECACA; color: #B91C1C; }

        /* Идеальная SaaS Таблица */
        .table-responsive { width: 100%; overflow-x: auto; max-height: 70vh; overflow-y: auto; background: var(--card-bg); border-radius: var(--radius-lg); box-shadow: var(--shadow-md);}
        table { width: 100%; min-width: 900px; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 16px 20px; text-align: left; font-size: 14px; vertical-align: middle; border-bottom: 1px solid #F1F5F9;}
        th { position: sticky; top: 0; z-index: 10; background-color: rgba(255,255,255,0.95); backdrop-filter: blur(8px); font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); white-space: nowrap; box-shadow: 0 1px 0 #F1F5F9; letter-spacing: 0.05em;}
        tr:hover td { background-color: #F8FAFC; }
        tr:last-child td { border-bottom: none; }
        
        .col-price { white-space: nowrap; font-weight: 700; font-size: 16px; color: #10B981;}
        
        .client-avatar { width: 36px; height: 36px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; margin-right: 12px; vertical-align: middle;}
        .client-link { color: var(--text-main); text-decoration: none; font-weight: 700; font-size: 15px; transition: var(--transition); }
        .client-link:hover { color: var(--primary); }

        .contact-col { display: flex; flex-direction: column; gap: 4px; }
        .seats-badge { background: #F1F5F9; color: #475569; font-weight: 700; padding: 4px 12px; border-radius: 12px; font-size: 13px;}

        /* Кнопки действий */
        .action-cell { display: flex; gap: 8px; justify-content: flex-end; align-items: center; }
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: var(--radius-sm); font-size: 14px; border: none; cursor: pointer; transition: var(--transition); background: #F8FAFC; color: #64748B; text-decoration: none;}
        .btn-icon:hover { background: #F1F5F9; color: var(--text-main); transform: translateY(-1px); box-shadow: var(--shadow-sm);}
        
        .btn-wa { background: #DCFCE7; color: #16A34A; } .btn-wa:hover { background: #BBF7D0; color: #15803D; }
        .btn-view { background: var(--primary-light); color: var(--primary); } .btn-view:hover { background: #E0E7FF; color: #3730A3; }

        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state svg { width: 64px; height: 64px; color: #E2E8F0; margin-bottom: 20px; }
        .empty-state h3 { font-size: 20px; color: var(--text-main); margin: 0 0 8px 0; font-weight: 800;}
        .empty-state p { font-size: 15px; margin: 0; }

        @media (max-width: 768px) {
            body { padding: 10px; } .container { padding: 10px; }
            .search-box { flex-direction: column; align-items: stretch; padding: 15px; }
            .search-group { width: 100%; }
            .btn-filter, .btn-reset { width: 100%; justify-content: center; }
            .table-responsive { border-radius: 12px; }
        }
    </style>
</head>
<body>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>Клиентская база</h2>
    </div>

    <div class="dash-grid">
        <div class="dash-card blue">
            <div class="dash-title">Уникальных клиентов</div>
            <div class="dash-val"><?= $total_unique_clients ?> чел.</div>
        </div>
        <div class="dash-card profit">
            <div class="dash-title">Суммарный LTV базы</div>
            <div class="dash-val val-green"><?= number_format($total_ltv, 0, '', ' ') ?> ₽</div>
        </div>
    </div>

    <form class="search-box" method="GET">
        <div class="search-group">
            <div class="search-input-wrapper">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" name="search" placeholder="Поиск по имени, номеру телефона или почте..." value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        <button type="submit" class="btn-filter">Найти клиента</button>
        <?php if ($search !== ''): ?>
            <a href="clients.php" class="btn-reset">Сбросить</a>
        <?php endif; ?>
    </form>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Клиент</th>
                        <th>Контакты</th>
                        <th style="width: 120px;">Поездок</th>
                        <th>LTV (Сумма покупок)</th>
                        <th style="text-align: right; width: 120px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($clients) === 0): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <h3>Ничего не найдено</h3>
                                <p>В базе нет клиентов, подходящих под ваш запрос.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($clients as $c): 
                        $clean_phone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
                        if (str_starts_with($clean_phone, '8') && strlen($clean_phone) == 11) { $clean_phone = '7' . substr($clean_phone, 1); }
                        $avatar_letter = mb_strtoupper(mb_substr($c['client_name'] ?: '?', 0, 1));
                    ?>
                    
                    <tr>
                        <td style="white-space: nowrap;">
                            <div class="client-avatar"><?= $avatar_letter ?></div>
                            <a href="client.php?phone=<?= urlencode($c['phone'] ?? '') ?>" class="client-link" title="Открыть профиль клиента"><?= htmlspecialchars($c['client_name'] ?? 'Без имени') ?></a>
                        </td>
                        <td>
                            <div class="contact-col">
                                <span style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($c['phone'] ?? '') ?></span>
                                <?php if (!empty($c['email'])): ?><span style="color:var(--text-muted); font-size:12px; font-weight:500;"><?= htmlspecialchars($c['email'] ?? '') ?></span><?php endif; ?>
                            </div>
                        </td>
                        <td><span class="seats-badge"><?= $c['total_tours'] ?> шт.</span></td>
                        <td class="col-price"><?= number_format($c['ltv'] ?? 0, 0, '', ' ') ?> ₽</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div class="action-cell">
                                <a href="https://wa.me/<?= $clean_phone ?>" target="_blank" class="btn-icon btn-wa" title="Написать в WhatsApp">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.3 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path></svg>
                                </a>
                                <a href="client.php?phone=<?= urlencode($c['phone'] ?? '') ?>" class="btn-icon btn-view" title="История поездок">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
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

</body>
</html>