<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') {
    die("<h2 style='text-align:center; margin-top:50px; font-family:sans-serif;'>Доступ закрыт. Только для администратора.</h2>");
}

// --- ФИЛЬТРЫ ДАТ (ДЛЯ ВЕРХНИХ БЛОКОВ) ---
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

// Чистая прибыль и Рентабельность
$net_profit = $total_revenue - $total_expenses;
$margin_percent = $total_revenue > 0 ? round(($net_profit / $total_revenue) * 100, 1) : 0;

// Функция для уникального цвета источника
function getSourceColor($name) {
    $colors = ['#10B981', '#3B82F6', '#8B5CF6', '#F59E0B', '#EC4899', '#14B8A6', '#F43F5E', '#06B6D4', '#84CC16'];
    $hash = crc32($name);
    return $colors[abs($hash) % count($colors)];
}

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

// ДАННЫЕ ДЛЯ КОЛЬЦЕВОЙ ДИАГРАММЫ ИСТОЧНИКОВ
$doughnut_labels = [];
$doughnut_data = [];
$doughnut_colors = [];
foreach ($sources as $s) {
    $src_name = $s['source'] ?: 'Прямые продажи';
    $doughnut_labels[] = $src_name;
    $doughnut_data[] = $s['rev'];
    $doughnut_colors[] = getSourceColor($src_name);
}

// --- 3. ТОП ТУРОВ (ДОРАБОТАНО: Считаем расходы и маржу по туру) ---
$stmt_top_tours = $pdo->prepare("
    SELECT t.id, t.name, SUM(p.price) as rev, SUM(p.seats) as pax 
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

foreach ($top_tours as &$tt) {
    // Считаем расходы на этот конкретный тур за период
    $stmt_t_exp = $pdo->prepare("
        SELECT SUM(ex.amount) FROM expenses ex 
        JOIN events e ON ex.event_id = e.id 
        WHERE e.tour_id = ? AND e.tour_date BETWEEN ? AND ?
    ");
    $stmt_t_exp->execute([$tt['id'], $date_from, $date_to]);
    $tt['exp'] = (int)$stmt_t_exp->fetchColumn();
    $tt['profit'] = $tt['rev'] - $tt['exp'];
    $tt['margin'] = $tt['rev'] > 0 ? round(($tt['profit'] / $tt['rev']) * 100, 1) : 0;
}
unset($tt);

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

// =========================================================================
// --- 5. ГОДОВАЯ СТАТИСТИКА ПО МЕСЯЦАМ (НЕ ЗАВИСИТ ОТ ВЕРХНИХ ФИЛЬТРОВ) ---
// =========================================================================
$stat_year = isset($_GET['stat_year']) ? (int)$_GET['stat_year'] : (int)date('Y');

// Доходы по месяцам
$stmt_m_rev = $pdo->prepare("
    SELECT MONTH(e.tour_date) as m, SUM(p.price) as rev, SUM(p.seats) as pax 
    FROM participants p 
    JOIN events e ON p.event_id = e.id 
    WHERE p.status != 'Отмена' AND YEAR(e.tour_date) = ? 
    GROUP BY m
");
$stmt_m_rev->execute([$stat_year]);
$m_rev_data = $stmt_m_rev->fetchAll(PDO::FETCH_ASSOC);

// Расходы по месяцам
$stmt_m_exp = $pdo->prepare("
    SELECT MONTH(e.tour_date) as m, SUM(ex.amount) as exp 
    FROM expenses ex 
    JOIN events e ON ex.event_id = e.id 
    WHERE YEAR(e.tour_date) = ? 
    GROUP BY m
");
$stmt_m_exp->execute([$stat_year]);
$m_exp_data = $stmt_m_exp->fetchAll(PDO::FETCH_ASSOC);

// Формируем сводный массив на 12 месяцев
$months_stats = [];
for ($i = 1; $i <= 12; $i++) {
    $months_stats[$i] = ['rev' => 0, 'pax' => 0, 'exp' => 0, 'profit' => 0];
}
foreach ($m_rev_data as $r) {
    $months_stats[$r['m']]['rev'] = $r['rev'];
    $months_stats[$r['m']]['pax'] = $r['pax'];
}
foreach ($m_exp_data as $e) {
    $months_stats[$e['m']]['exp'] = $e['exp'];
}
$yearly_totals = ['rev' => 0, 'pax' => 0, 'exp' => 0, 'profit' => 0];

// ДАННЫЕ ДЛЯ ГРАФИКА CHART.JS
$chart_labels = [];
$chart_rev = [];
$chart_exp = [];
$chart_profit = [];

$months_names_ru = [1=>'Январь', 2=>'Февраль', 3=>'Март', 4=>'Апрель', 5=>'Май', 6=>'Июнь', 7=>'Июль', 8=>'Август', 9=>'Сентябрь', 10=>'Октябрь', 11=>'Ноябрь', 12=>'Декабрь'];

foreach ($months_stats as $m => &$data) {
    $data['profit'] = $data['rev'] - $data['exp'];
    $yearly_totals['rev'] += $data['rev'];
    $yearly_totals['pax'] += $data['pax'];
    $yearly_totals['exp'] += $data['exp'];
    $yearly_totals['profit'] += $data['profit'];
    
    // Заполняем массивы для графиков
    $chart_labels[] = mb_substr($months_names_ru[$m], 0, 3); // Янв, Фев, Мар...
    $chart_rev[] = $data['rev'];
    $chart_exp[] = $data['exp'];
    $chart_profit[] = $data['profit'];
}
unset($data);

// Получаем список доступных лет из базы (для выпадающего списка)
$years_stmt = $pdo->query("SELECT DISTINCT YEAR(tour_date) as y FROM events ORDER BY y DESC");
$available_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
if (empty($available_years)) {
    $available_years = [date('Y')];
}
if (!in_array($stat_year, $available_years)) {
    $available_years[] = $stat_year;
    rsort($available_years);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Финансовая Аналитика — CRM</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Дашборды (Метрики P&L) */
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
        
        /* Таблицы */
        .table-responsive { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 14px 16px; border-bottom: 1px solid #F1F5F9; font-size: 14px; text-align: left; vertical-align: middle; white-space: nowrap;}
        tr:last-child td { border-bottom: none; }
        th { font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 700; background: #F8FAFC; letter-spacing: 0.05em;}
        
        .rank-num { width: 24px; height: 24px; background: #F1F5F9; color: var(--text-muted); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800;}
        .rank-1 { background: #FEF3C7; color: #D97706; }
        .rank-2 { background: #F1F5F9; color: #64748B; }
        .rank-3 { background: #FFEDD5; color: #B45309; }
        
        .margin-badge { padding: 4px 8px; border-radius: 6px; font-size: 12px; font-weight: 800;}
        .mb-high { background: #D1FAE5; color: #047857; }
        .mb-mid { background: #FEF3C7; color: #B45309; }
        .mb-low { background: #FEE2E2; color: #B91C1C; }

        /* Выбор года */
        .year-select { padding: 8px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; outline: none; background: #F8FAFC; color: var(--text-main); font-weight: 700; cursor: pointer; transition: var(--transition); appearance: none; -webkit-appearance: none; background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%2364748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>'); background-repeat: no-repeat; background-position: right 12px center; padding-right: 36px; }
        .year-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }

        /* Таблица месяцев */
        .monthly-table td { padding: 16px; }
        .monthly-table tr:hover td { background-color: #F8FAFC; }
        .monthly-table .current-month td { background-color: var(--primary-light); }
        .monthly-table tfoot td { font-weight: 800; background: #F8FAFC; border-top: 2px solid var(--border); border-bottom: none;}

        @media (max-width: 992px) {
            .charts-layout { grid-template-columns: 1fr; }
            .search-box { flex-direction: column; align-items: stretch;}
            .btn-filter, .btn-reset { width: 100%; justify-content: center;}
            .source-split { grid-template-columns: 1fr !important; }
        }
    </style>
</head>
<body>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>Финансовая Аналитика</h2>
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
        <a href="?date_from=<?= $tdy ?>&date_to=<?= $tdy ?>&stat_year=<?= $stat_year ?>" class="pill">Сегодня</a>
        <a href="?date_from=<?= $m_start ?>&date_to=<?= $m_end ?>&stat_year=<?= $stat_year ?>" class="pill">Этот месяц</a>
        <a href="?date_from=<?= $lm_start ?>&date_to=<?= $lm_end ?>&stat_year=<?= $stat_year ?>" class="pill">Прошлый месяц</a>
        <a href="?date_from=<?= $y_start ?>&date_to=<?= $y_end ?>&stat_year=<?= $stat_year ?>" class="pill">Весь год</a>
    </div>

    <form class="search-box" method="GET">
        <input type="hidden" name="stat_year" value="<?= htmlspecialchars($stat_year) ?>">
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
        <div class="dash-card blue">
            <div class="dash-title">Общая выручка</div>
            <div class="dash-val"><?= number_format($total_revenue, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card expense">
            <div class="dash-title">Сумма расходов</div>
            <div class="dash-val" style="color: #EF4444;"><?= number_format($total_expenses, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card profit">
            <div class="dash-title">Чистая прибыль</div>
            <div class="dash-val" style="color: #10B981;"><?= number_format($net_profit, 0, '', ' ') ?> ₽</div>
        </div>
        <div class="dash-card neutral">
            <div class="dash-title">Рентабельность</div>
            <div class="dash-val" style="color: #3B82F6;"><?= $margin_percent ?>%</div>
        </div>
    </div>

    <div class="charts-layout">
        <div class="card">
            <h3>Эффективность источников</h3>
            <div class="source-split" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: center;">
                
                <div>
                    <?php if (empty($sources)): ?>
                        <div style="color:var(--text-muted); font-size:13px;">Нет данных</div>
                    <?php else: ?>
                        <?php 
                        foreach ($sources as $s): 
                            $percent = getPercent($s['rev'], $total_revenue);
                            $src_name = $s['source'] ?: 'Прямые продажи';
                            $color = getSourceColor($src_name);
                        ?>
                        <div class="bar-item">
                            <div class="bar-header">
                                <span class="bar-name"><?= htmlspecialchars($src_name) ?></span>
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

                <div style="position: relative; height: 220px; width: 100%;">
                    <?php if (!empty($sources)): ?>
                        <canvas id="sourceChart"></canvas>
                    <?php else: ?>
                        <div style="height:100%; display:flex; align-items:center; justify-content:center; color:var(--text-muted);">Пусто</div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:30px;">
            <div class="card" style="padding: 25px;">
                <h3 style="font-size:16px; margin-top:0; border-bottom: 2px solid #F1F5F9; padding-bottom: 15px; margin-bottom: 15px;">🔥 Топ-5 популярных туров</h3>
                <?php if (empty($top_tours)): ?>
                    <div style="color:var(--text-muted); font-size:13px; text-align:center; padding: 20px;">Нет данных</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Тур</th>
                                    <th style="text-align:right;">Выручка</th>
                                    <th style="text-align:right;">Прибыль</th>
                                    <th style="text-align:center;">Маржа</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_tours as $idx => $tt): 
                                    $rank_class = $idx < 3 ? "rank-" . ($idx + 1) : "";
                                    $badge_class = 'mb-high';
                                    if ($tt['margin'] < 15) $badge_class = 'mb-low';
                                    elseif ($tt['margin'] < 35) $badge_class = 'mb-mid';
                                ?>
                                <tr>
                                    <td style="font-weight:600; color:var(--text-main);">
                                        <span class="rank-num <?= $rank_class ?>"><?= $idx + 1 ?></span>
                                        <?= htmlspecialchars($tt['name']) ?>
                                    </td>
                                    <td style="text-align:right; font-weight:700;"><?= number_format($tt['rev'], 0, '', ' ') ?> ₽</td>
                                    <td style="text-align:right; font-weight:800; color:#10B981;"><?= number_format($tt['profit'], 0, '', ' ') ?> ₽</td>
                                    <td style="text-align:center;"><span class="margin-badge <?= $badge_class ?>"><?= $tt['margin'] ?>%</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card" style="padding: 25px;">
                <h3 style="font-size:16px; margin-top:0; border-bottom: 2px solid #F1F5F9; padding-bottom: 15px; margin-bottom: 15px;">🌟 Эффективность гидов</h3>
                <?php if (empty($top_guides)): ?>
                    <div style="color:var(--text-muted); font-size:13px; text-align:center; padding: 20px;">Нет данных</div>
                <?php else: ?>
                    <div class="table-responsive">
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
                                    <td style="font-weight:600; color:var(--text-main);">
                                        <span class="rank-num <?= $rank_class ?>"><?= $idx + 1 ?></span>
                                        <?= htmlspecialchars($tg['guide'] ?: 'Без гида') ?>
                                    </td>
                                    <td style="text-align:center; color:var(--text-muted); font-weight: 500;"><?= $tg['pax'] ?></td>
                                    <td style="text-align:right; font-weight:800; color:var(--primary);"><?= number_format($tg['rev'], 0, '', ' ') ?> ₽</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 30px; padding: 0; overflow: hidden;">
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 25px; border-bottom: 2px solid #F1F5F9; flex-wrap: wrap; gap: 15px;">
            <h3 style="margin: 0; border: none; padding: 0;">Сводка по месяцам</h3>
            
            <form method="GET" style="display: flex; align-items: center; gap: 10px;">
                <input type="hidden" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                <input type="hidden" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                
                <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">Отчетный год:</span>
                <select name="stat_year" onchange="this.form.submit()" class="year-select">
                    <?php foreach($available_years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == $stat_year ? 'selected' : '' ?>><?= $y ?> год</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <div style="padding: 25px 25px 0 25px;">
            <canvas id="monthlyChart" height="70"></canvas>
        </div>
        
        <div class="table-responsive">
            <table class="monthly-table" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th>Месяц</th>
                        <th style="text-align: center;">Туристов</th>
                        <th style="text-align: right;">Выручка</th>
                        <th style="text-align: right;">Расходы</th>
                        <th style="text-align: right;">Чистая прибыль</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $current_month = (int)date('n');
                    $is_current_year = ($stat_year == (int)date('Y'));
                    
                    for($i = 1; $i <= 12; $i++): 
                        $m_data = $months_stats[$i];
                        $row_class = ($is_current_year && $i === $current_month) ? 'current-month' : '';
                        
                        $profit_color = 'var(--text-main)';
                        if ($m_data['profit'] > 0) $profit_color = '#10B981';
                        elseif ($m_data['profit'] < 0) $profit_color = '#EF4444';
                        elseif ($m_data['rev'] == 0 && $m_data['exp'] == 0) $profit_color = '#94A3B8';
                    ?>
                        <tr class="<?= $row_class ?>">
                            <td style="font-weight: 600; color: var(--text-main);">
                                <?= $months_names_ru[$i] ?>
                                <?php if($row_class === 'current-month'): ?>
                                    <span style="font-size:10px; background:var(--primary); color:white; padding:2px 6px; border-radius:4px; margin-left:6px; vertical-align:middle;">Текущий</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center; color: var(--text-muted); font-weight: 500;">
                                <?= $m_data['pax'] ?> чел.
                            </td>
                            <td style="text-align: right; font-weight: 600; color: var(--primary);">
                                <?= number_format($m_data['rev'], 0, '', ' ') ?> ₽
                            </td>
                            <td style="text-align: right; font-weight: 500; color: #EF4444;">
                                <?= $m_data['exp'] > 0 ? '- ' . number_format($m_data['exp'], 0, '', ' ') . ' ₽' : '0 ₽' ?>
                            </td>
                            <td style="text-align: right; font-weight: 800; color: <?= $profit_color ?>;">
                                <?= number_format($m_data['profit'], 0, '', ' ') ?> ₽
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td style="color: var(--text-main);">Итого за год:</td>
                        <td style="text-align: center; color: var(--text-main);"><?= $yearly_totals['pax'] ?> чел.</td>
                        <td style="text-align: right; color: var(--primary);"><?= number_format($yearly_totals['rev'], 0, '', ' ') ?> ₽</td>
                        <td style="text-align: right; color: #EF4444;"><?= $yearly_totals['exp'] > 0 ? '- ' . number_format($yearly_totals['exp'], 0, '', ' ') . ' ₽' : '0 ₽' ?></td>
                        <td style="text-align: right; color: <?= $yearly_totals['profit'] > 0 ? '#10B981' : ($yearly_totals['profit'] < 0 ? '#EF4444' : 'var(--text-main)') ?>;">
                            <?= number_format($yearly_totals['profit'], 0, '', ' ') ?> ₽
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        
        Chart.defaults.font.family = "'Inter', 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.color = '#64748B';

        // 1. КОЛЬЦЕВАЯ ДИАГРАММА ИСТОЧНИКОВ
        const srcLabels = <?= json_encode($doughnut_labels) ?>;
        const srcData = <?= json_encode($doughnut_data) ?>;
        const srcColors = <?= json_encode($doughnut_colors) ?>;

        if (document.getElementById('sourceChart')) {
            const ctxSource = document.getElementById('sourceChart').getContext('2d');
            new Chart(ctxSource, {
                type: 'doughnut',
                data: {
                    labels: srcLabels,
                    datasets: [{
                        data: srcData,
                        backgroundColor: srcColors,
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: { display: false }, // Легенда у нас слева текстом
                        tooltip: {
                            callbacks: { label: function(context) { return ' ' + context.label + ': ' + new Intl.NumberFormat('ru-RU').format(context.raw) + ' ₽'; } }
                        }
                    }
                }
            });
        }

        // 2. ГРАФИК ПО МЕСЯЦАМ (Твой оригинальный код)
        const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
        new Chart(ctxMonthly, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [
                    {
                        label: 'Чистая прибыль',
                        type: 'line',
                        data: <?= json_encode($chart_profit) ?>,
                        borderColor: '#10B981',
                        backgroundColor: '#10B981',
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        tension: 0.4,
                        fill: false
                    },
                    {
                        label: 'Выручка',
                        data: <?= json_encode($chart_rev) ?>,
                        backgroundColor: '#4F46E5',
                        borderRadius: 4
                    },
                    {
                        label: 'Расходы',
                        data: <?= json_encode($chart_exp) ?>,
                        backgroundColor: '#EF4444',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { 
                        position: 'top', 
                        labels: { usePointStyle: true, boxWidth: 10, font: { weight: '600' } } 
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.9)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + new Intl.NumberFormat('ru-RU').format(context.raw) + ' ₽';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F1F5F9' },
                        border: { display: false },
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000) return (value / 1000000) + 'М';
                                if (value >= 1000) return (value / 1000) + 'К';
                                return value;
                            }
                        }
                    },
                    x: {
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    });
</script>

</body>
</html>