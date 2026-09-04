<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';

if ($current_user_role !== 'admin') {
    die("<h2 style='text-align:center; margin-top:50px;'>Доступ закрыт.</h2>");
}

$part_cols = $pdo->query("SHOW COLUMNS FROM participants")->fetchAll(PDO::FETCH_COLUMN);
$name_col = in_array('client_name', $part_cols) ? 'client_name' : 'name';

// --- ИНИЦИАЛИЗАЦИЯ БАЗЫ ДАННЫХ ДЛЯ ТЕГОВ ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_profiles (phone VARCHAR(50) PRIMARY KEY, tags VARCHAR(255) DEFAULT '', global_note TEXT DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS global_settings (setting_key VARCHAR(50) PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("INSERT IGNORE INTO global_settings (setting_key, setting_value) VALUES ('client_tags', 'VIP,Лояльный,Семья с детьми,Сложный клиент,Черный список')");
} catch (PDOException $e) {}

function getTagStyle($tagName) {
    $hash = crc32($tagName);
    $hue = abs($hash) % 360;
    return "background: hsl({$hue}, 80%, 94%); color: hsl({$hue}, 85%, 28%); border: 1px solid transparent;";
}

$tour_filter = (int)($_GET['tour_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$tag_filter = trim($_GET['tag'] ?? '');

$having_clauses = [];
$params = [];

if ($tour_filter > 0) {
    $having_clauses[] = "SUM(CASE WHEN e.tour_id = ? THEN 1 ELSE 0 END) > 0";
    $params[] = $tour_filter;
}
if ($search !== '') {
    $having_clauses[] = "(MAX(p.{$name_col}) LIKE ? OR p.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($tag_filter !== '') {
    $having_clauses[] = "cp.tags LIKE ?";
    $params[] = "%$tag_filter%";
}

$having_sql = $having_clauses ? "HAVING " . implode(" AND ", $having_clauses) : "";

// Достаем MAX(p.email), чтобы вывести почту клиента в списке
$sql = "SELECT 
            p.phone, 
            MAX(p.{$name_col}) as client_name, 
            MAX(p.email) as email,
            COUNT(DISTINCT p.event_id) as trips_count,
            SUM(CASE WHEN p.status != 'Отмена' THEN p.price ELSE 0 END) as ltv,
            cp.tags
        FROM participants p
        LEFT JOIN events e ON p.event_id = e.id
        LEFT JOIN client_profiles cp ON p.phone = cp.phone
        GROUP BY p.phone
        $having_sql
        ORDER BY ltv DESC, trips_count DESC
        LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем глобальный список тегов для выпадающего фильтра
$global_tags_str = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'client_tags'")->fetchColumn();
$all_available_tags = $global_tags_str ? explode(',', $global_tags_str) : [];

$tours = $pdo->query("SELECT id, name FROM tours_catalog ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$total_clients = count($clients);
$total_ltv = array_sum(array_column($clients, 'ltv'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>База клиентов — CRM</title>
    <style>
        :root { --primary: #4F46E5; --primary-hover: #4338CA; --primary-light: #EEF2FF; --bg: #F8FAFC; --card-bg: #FFFFFF; --border: #E2E8F0; --text-main: #0F172A; --text-muted: #64748B; --radius-lg: 12px; --radius-md: 8px; --radius-sm: 6px; --shadow-xs: 0 1px 2px rgba(0,0,0,0.05); --shadow-sm: 0 4px 6px -1px rgba(0,0,0,0.05); --transition: all 0.2s ease;}
        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); margin: 0; padding: 20px; }
        .container { max-width: 1350px; margin: 0 auto; box-sizing: border-box; }
        .navbar { display: flex; gap: 12px; margin-bottom: 24px; align-items: center; flex-wrap: wrap; background: var(--card-bg); padding: 12px 20px; border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-xs); }
        .nav-link { text-decoration: none; color: var(--text-muted); font-weight: 600; font-size: 14px; padding: 8px 14px; border-radius: var(--radius-sm); transition: var(--transition); }
        .nav-link.active { background: var(--primary); color: white; }
        .nav-link:hover:not(.active) { background: var(--primary-light); color: var(--primary); }

        .header-box { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        h2 { margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.02em; color: var(--text-main); }

        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .metric-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 18px 20px; box-shadow: var(--shadow-xs); display: flex; flex-direction: column; gap: 4px; }
        .metric-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.03em; }
        .metric-value { font-size: 26px; font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; }

        .filter-bar { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 16px 20px; margin-bottom: 24px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; box-shadow: var(--shadow-xs); }
        .t-input { padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 14px; background: #F8FAFC; color: var(--text-main); outline: none; font-weight: 500; transition: var(--transition); }
        .t-input:focus { background: #fff; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-light); }
        .btn-secondary { background: #F1F5F9; color: var(--text-main); border: 1px solid var(--border); padding: 10px 18px; border-radius: var(--radius-sm); font-weight: 600; font-size: 14px; cursor: pointer; text-decoration: none;}
        .btn-secondary:hover { background: #E2E8F0; }

        .table-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius-lg); box-shadow: var(--shadow-xs); overflow: hidden; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; text-align: left; }
        th, td { padding: 14px 20px; font-size: 14px; border-bottom: 1px solid #F1F5F9; }
        th { font-weight: 700; font-size: 12px; text-transform: uppercase; color: var(--text-muted); background: #F8FAFC; letter-spacing: 0.04em; }
        tr:hover td { background-color: #F8FAFC; }
        
        .client-name { font-weight: 800; color: var(--text-main); font-size: 15px; text-decoration: none;}
        .client-name:hover { color: var(--primary); text-decoration: underline;}
        .client-phone { font-size: 14px; color: var(--text-main); font-weight: 600; margin-bottom: 2px;}
        .client-email { font-size: 12px; color: var(--text-muted); }
        
        .val-ltv { font-weight: 800; color: #10B981; font-size: 15px;}
        .val-trips { font-weight: 800; color: var(--text-main); font-size: 15px; background: #F1F5F9; padding: 2px 8px; border-radius: 6px;}

        .tags-wrap { display: flex; gap: 6px; flex-wrap: wrap; }
        .c-tag { padding: 4px 10px; border-radius: 8px; font-size: 11px; font-weight: 800; letter-spacing: 0.02em; white-space: nowrap;}
        
        .btn-open { padding: 6px 14px; background: #fff; color: var(--text-main); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; text-decoration: none; transition: var(--transition); box-shadow: var(--shadow-xs); }
        .btn-open:hover { background: #F8FAFC; border-color: #CBD5E1; }
    </style>
</head>
<body>

<div class="container">
    <?php include 'navbar.php'; ?>

    <div class="header-box">
        <h2>База клиентов</h2>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">Найдено клиентов</div>
            <div class="metric-value"><?= $total_clients ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Суммарный LTV (Доход)</div>
            <div class="metric-value" style="color:#10B981;"><?= number_format($total_ltv, 0, '', ' ') ?> ₽</div>
        </div>
    </div>

    <form class="filter-bar" method="GET">
        <input type="text" name="search" class="t-input" style="flex:1; min-width:200px;" placeholder="Имя или телефон..." value="<?= htmlspecialchars($search) ?>">
        <select name="tour_id" class="t-input" onchange="this.form.submit()">
            <option value="0">Ездили на любой тур</option>
            <?php foreach($tours as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $t['id'] === $tour_filter ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="tag" class="t-input" onchange="this.form.submit()">
            <option value="">Все теги</option>
            <?php foreach($all_available_tags as $tag): ?>
                <option value="<?= htmlspecialchars($tag) ?>" <?= $tag === $tag_filter ? 'selected' : '' ?>><?= htmlspecialchars($tag) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-secondary">Найти</button>
        <?php if($search || $tour_filter || $tag_filter): ?>
            <a href="clients.php" class="btn-secondary" style="color:#EF4444;">Сбросить</a>
        <?php endif; ?>
    </form>

    <div class="table-card">
        <table>
            <thead>
                <tr>
                    <th>Клиент</th>
                    <th>Контакты</th>
                    <th>Поездок</th>
                    <th>Выручка (LTV)</th>
                    <th>Теги и Сегменты</th>
                    <th style="text-align: right;">Профиль</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($clients)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 40px; color: var(--text-muted);">Клиенты не найдены</td></tr>
                <?php else: ?>
                    <?php foreach($clients as $c): 
                        $client_tags = array_filter(explode(',', $c['tags'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <a href="client.php?phone=<?= urlencode($c['phone']) ?>" class="client-name"><?= htmlspecialchars($c['client_name'] ?: 'Без имени') ?></a>
                            </td>
                            <td>
                                <div class="client-phone"><?= htmlspecialchars($c['phone']) ?></div>
                                <?php if (!empty($c['email'])): ?>
                                    <div class="client-email"><?= htmlspecialchars($c['email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="val-trips"><?= $c['trips_count'] ?></span></td>
                            <td><span class="val-ltv"><?= number_format($c['ltv'], 0, '', ' ') ?> ₽</span></td>
                            <td>
                                <div class="tags-wrap">
                                    <?php foreach($client_tags as $t): 
                                        $t = trim($t);
                                        $style = getTagStyle($t);
                                    ?>
                                        <span class="c-tag" style="<?= $style ?>"><?= htmlspecialchars($t) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td style="text-align: right;">
                                <a href="client.php?phone=<?= urlencode($c['phone']) ?>" class="btn-open">Открыть карточку</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>