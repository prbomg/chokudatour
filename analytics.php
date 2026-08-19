<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>Доступ закрыт. Только для администратора.</h2>");
}

// --- ФИЛЬТРЫ ДАТ ---
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // По умолчанию с 1 числа текущего месяца
$date_to = $_GET['date_to'] ?? date('Y-m-t');      // По умолчанию до конца текущего месяца

// --- 1. СБОР ОСНОВНОЙ ФИНАНСОВОЙ СТАТИСТИКИ ---
// Выручка и количество мест (только не отмененные)
$stmt_rev = $pdo->prepare("
    SELECT SUM(p.price) as total_revenue, SUM(p.seats) as total_seats 
    FROM participants p 
    JOIN events e ON p.event_id = e.id 
    WHERE p.status != 'Отмена' AND e.tour_date BETWEEN ? AND ?
");
$stmt_rev->execute([$date_from, $date_to]);
$rev_data = $stmt_rev->fetch(PDO::FETCH_ASSOC);
$total_revenue = (int)($rev_data['total_revenue'] ?? 0);
$total_seats = (int)($rev_data['total_seats'] ?? 0);

// Расходы
$stmt_exp = $pdo->prepare("
    SELECT SUM(ex.amount) as total_expenses 
    FROM expenses ex 
    JOIN events e ON ex.event_id = e.id 
    WHERE e.tour_date BETWEEN ? AND ?
");
$stmt_exp->execute([$date_from, $date_to]);
$total_expenses = (int)($stmt_exp->fetchColumn() ?? 0);

// Чистая прибыль
$net_profit = $total_revenue - $total_expenses;

// --- 2. СТАТИСТИКА ПО ИСТОЧНИКАМ (Воронка) ---
$stmt_sources = $pdo->prepare("
    SELECT p.source, SUM(p.price) as rev, SUM(p.seats) as pax 
    FROM participants p 
    JOIN events e ON p.event_id = e.id 
    WHERE p.status != 'Отмена' AND e.tour_date BETWEEN ? AND ? 
    GROUP BY p.source 
    ORDER BY rev DESC
");
$stmt_sources->execute([$date_from, $date_to]);
$sources = $stmt_sources->fetchAll(PDO::FETCH_ASSOC);

// --- 3. ТОП ТУРОВ ---
$stmt_top_tours = $pdo->prepare("
    SELECT t.name, SUM(p.price) as rev, SUM(p.seats) as pax 
    FROM participants p 
    JOIN events e ON p.event_id = e.id 
    JOIN tours_catalog t ON e.tour_id = t.id 
    WHERE p.status != 'Отмена' AND e.tour_date BETWEEN ? AND ? 
    GROUP BY t.id 
    ORDER BY rev DESC 
    LIMIT 5
");
$stmt_top_tours->execute([$date_from, $date_to]);
$top_tours = $stmt_top_tours->fetchAll(PDO::FETCH_ASSOC);

// --- 4. ТОП ГИДОВ ---
$stmt_top_guides = $pdo->prepare("
    SELECT e.guide, SUM(p.price) as rev, SUM(p.seats) as pax 
    FROM participants p 
    JOIN events e ON p.event_id = e.id 
    WHERE p.status != 'Отмена' AND e.tour_date BETWEEN ? AND ? 
    GROUP BY e.guide 
    ORDER BY rev DESC 
    LIMIT 5
");
$stmt_top_guides->execute([$date_from, $date_to]);
$top_guides = $stmt_top_guides->fetchAll(PDO::FETCH_ASSOC);

// Вспомогательная функция для прогресс-баров
function getPercent($part, $total) {
    if ($total <= 0) return 0;
    return round(($part / $total) * 100);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Финансовая Аналитика — CRM</title>
    <style>
        /* ПРЕМИУМ ДИЗАЙН (Soft UI & Glassmorphism) */
        :root { 
            --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF;
            --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; 
            --text-main: #0F172A; --text-muted: #64748B;
            --radius-lg: 16px; --radius-md: 12px; --radius-sm: 8px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05); --shadow-md: 0 4px 15px -3px rgba(0,0,0,0.05);
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body { font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; }
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box;}
        
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 10px; }
        
        .navbar { display: flex; gap: 15px; margin-bottom: 25px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 15px 25px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);}
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; padding: 10px 18px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);}
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { margin-bottom: 25px;}
        h2 { margin: 0; font-size: 28px; font-weight: 800; color: var(--text-main); }

        /* Фильтры дат */
        .search-box { display: flex; gap: 12px; background: var(--card-bg); padding: 20px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); margin-bottom: 30px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 150px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .filter-group input { padding: 11px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; outline: none; background: #F8FAFC; color: var(--text-main); font-weight: 600; cursor: pointer; transition: var(--transition); height: 44px; box-sizing: border-box;}
        .filter-group input:focus { border-color: var(--primary); background: #fff; box-shadow: 0 0 0 3px var(--primary-light); }
        
        .btn-filter { background: var(--primary); color: white; padding: 0 28px; border: none; border-radius: var(--radius-sm); font-weight: 700; cursor: pointer; transition: var(--transition); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); font-size: 14px; height: 44px; display: inline-flex; align-items: center; justify-content: center;}
        .btn-filter:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(79, 70, 229, 0.3);}
        .btn-reset { display: inline-flex; align-items: center; justify-content: center; background: #FEE2E2; color: #DC2626; padding: 0 24px; border-radius: var(--radius-sm); font-weight: 700; font-size: 14px; text-decoration: none; transition: var(--transition); height: 44px;}
        .btn-reset:hover { background: #FECACA; color: #B91C1C; }

        .quick-filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .pill { background: var(--card-bg); color: var(--text-muted); padding: 8px 18px; border-radius: 99px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid var(--border); transition: var(--transition); white-space: nowrap; box-shadow: var(--shadow-sm);}
        .pill:hover { background: var(--primary-light); color: var(--primary); border-color: transparent; transform: translateY(-1px); box-shadow: var(--shadow-md);}

        /* Дашборды */
        .dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .dash-card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 25px; box-shadow: var(--shadow-md); position: relative; overflow: hidden; border: 1px solid var(--border);}
        .dash-card::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; border-radius: 4px 0 0 4px; background: var(--border);}
        .dash-card.profit::before { background: #10B981; }
        .dash-card.blue::before { background: var(--primary); }
        .dash-card.expense::before { background: #EF4444; }
        .dash-card.neutral::before { background: #F59E0B; }
        .dash-title { font-size: 12px; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 8px; letter-spacing: 0.05em;}
        .dash-val { font-size: 28px; font-weight: 800; color: var(--text-main); }

        /* Графики и рейтинги */
        .charts-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start; margin-bottom: 30px;}
        .card { background: var(--card-bg); border-radius: var(--radius-lg); padding: 30px; box-shadow: var(--shadow-md); border: 1px solid var(--border); }
        h3 { margin-top: 0; font-size: 18px; font-weight: 800; border-bottom: 2px solid #F1F5F9; padding-bottom: 15px; margin-bottom: 20px; color: var(--text-main); display: flex; align-items: center; gap: 10px;}

        /* CSS Прогресс-бары для источников */
        .bar-item { margin-bottom: 18px; }
        .bar-header { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 14px; font-weight: 600;}
        .bar-name { color: var(--text-main); }
        .bar-stats { color: var(--text-muted); }
        .bar-stats strong { color: var(--text-main); }
        .bar-track { width: 100%; height: 10px; background: #F1F5F9; border-radius: 99px; overflow: hidden;}
        .bar-fill { height: 100%; border-radius: 99px; transition: width 1s ease-out;}
        
        /* Таблицы для рейтингов */
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 12px 10px; border-bottom: 1px solid #F1F5F9; font-size: 14px; text-align: left; vertical-align: middle;}
        tr:last-child td { border-bottom: none; }
        th { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700;}
        
        .rank-num { width: 24px; height: 24px; background: #F1F5F9; color: var(--text-muted); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800;}
        .rank-1 { background: #FEF3C7; color: #D97706; }
        .rank-2 { background: #F1F5F9; color: #64748B; }
        .rank-3 { background: #FFEDD5; color: #B45309; }

        @media (max-width: 992px) {
            .charts-layout { grid-template-columns: 1fr; }
            .search-box { flex-direction: column; align-items: stretch;}
            .btn-filter, .btn-reset { width: 100%; justify-content: center;}
        }
    </style>
</head>
<body>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>Финансовая аналитика</h2>
    </div>

    <?php 
        $tdy = date('Y-m-d');
        $m_start = date('Y-m-01');
        $m_end = date('Y-m-t');
        $lm_start = date('Y-m-01', strtotime('first day of last month'));
        $lm_end = date('Y-m-t', strtotime('last day of last month'));
        $y_start = date('Y-01-01');
        $y_end = date('Y-12-31');
    ?>
    <div class="quick-filters">
        <a href="?date_from=<?= $tdy ?>&date_to=<?= $tdy ?>" class="pill">Сегодня</a>
        <a href="?date_from=<?= $m_start ?>&date_to=<?= $m_end ?>" class="pill">Этот месяц</a>
        <a href="?date_from=<?= $lm_start ?>&date_to=<?= $lm_end ?>" class="pill">Прошлый месяц</a>
        <a href="?date_from=<?= $y_start ?>&date_to=<?= $y_end ?>" class="pill">Весь год</a>
    </div>

    <form class="search-box" method="GET">
        <div class="filter-group">
            <label>Начало периода</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
        </div>
        <div class="filter-group">
            <label>Конец периода</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
        </div>
        <button type="submit" class="btn-filter">Показать статистику</button>
        <a href="analytics.php" class="btn-reset">Сбросить</a>
    </form>

    <div class="dash-grid">
        <div class="dash-card profit">
            <div class="dash-title">Чистая прибыль</div>
            <div class="dash-val" style="color: #10B981;"><?= number_format($net_profit, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card blue">
            <div class="dash-title">Общая выручка</div>
            <div class="dash-val"><?= number_format($total_revenue, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card expense">
            <div class="dash-title">Сумма расходов</div>
            <div class="dash-val" style="color: #EF4444;"><?= number_format($total_expenses, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card neutral">
            <div class="dash-title">Обслужено туристов</div>
            <div class="dash-val"><?= $total_seats ?> чел.</div>
        </div>
    </div>

    <div class="charts-layout">
        <div class="card">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--primary);"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path><path d="M22 12A10 10 0 0 0 12 2v10z"></path></svg>
                Эффективность источников
            </h3>
            
            <?php if (empty($sources)): ?>
                <div style="text-align:center; padding:40px; color:var(--text-muted);">Нет данных за выбранный период</div>
            <?php else: ?>
                <?php 
                $colors = ['#4F46E5', '#10B981', '#F59E0B', '#EC4899', '#8B5CF6', '#14B8A6'];
                foreach ($sources as $index => $s): 
                    $percent = getPercent($s['rev'], $total_revenue);
                    $color = $colors[$index % count($colors)];
                ?>
                <div class="bar-item">
                    <div class="bar-header">
                        <span class="bar-name"><?= htmlspecialchars($s['source'] ?: 'Не указан') ?></span>
                        <span class="bar-stats"><strong><?= number_format($s['rev'], 0, '', ' ') ?> ₽</strong> (<?= $percent ?>%)</span>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width: <?= $percent ?>%; background: <?= $color ?>;"></div>
                    </div>
                    <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px; text-align: right;">
                        <?= $s['pax'] ?> чел.
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="display:flex; flex-direction:column; gap:30px;">
            <div class="card" style="padding: 25px;">
                <h3 style="font-size:16px;">🔥 Топ-5 популярных туров</h3>
                <?php if (empty($top_tours)): ?>
                    <div style="color:var(--text-muted); font-size:13px;">Нет данных</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Тур</th>
                                <th style="text-align:center;">Туристов</th>
                                <th style="text-align:right;">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_tours as $idx => $tt): 
                                $rank_class = $idx < 3 ? "rank-" . ($idx + 1) : "";
                            ?>
                            <tr>
                                <td style="font-weight:600;">
                                    <span class="rank-num <?= $rank_class ?>"><?= $idx + 1 ?></span>
                                    <?= htmlspecialchars($tt['name']) ?>
                                </td>
                                <td style="text-align:center; color:var(--text-muted);"><?= $tt['pax'] ?></td>
                                <td style="text-align:right; font-weight:700; color:#10B981;"><?= number_format($tt['rev'], 0, '', ' ') ?> ₽</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card" style="padding: 25px;">
                <h3 style="font-size:16px;">🌟 Эффективность гидов</h3>
                <?php if (empty($top_guides)): ?>
                    <div style="color:var(--text-muted); font-size:13px;">Нет данных</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Гид</th>
                                <th style="text-align:center;">Туристов</th>
                                <th style="text-align:right;">Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_guides as $idx => $tg): 
                                $rank_class = $idx < 3 ? "rank-" . ($idx + 1) : "";
                            ?>
                            <tr>
                                <td style="font-weight:600;">
                                    <span class="rank-num <?= $rank_class ?>"><?= $idx + 1 ?></span>
                                    <?= htmlspecialchars($tg['guide'] ?: 'Без гида') ?>
                                </td>
                                <td style="text-align:center; color:var(--text-muted);"><?= $tg['pax'] ?></td>
                                <td style="text-align:right; font-weight:700; color:var(--primary);"><?= number_format($tg['rev'], 0, '', ' ') ?> ₽</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>