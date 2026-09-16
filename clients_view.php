<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>База клиентов — CRM</title>
    <link rel="stylesheet" href="assets/client-workspace.css?v=<?= @filemtime(__DIR__ . '/assets/client-workspace.css') ?: 1 ?>">
</head>
<body>
<div class="container">
    <?php include 'navbar.php'; ?>
    <main class="clients-page">
        <header class="page-heading"><div><span class="eyebrow">Работа с аудиторией</span><h1>База клиентов</h1><p>Контакты, история поездок, заметки и сегменты постоянных гостей.</p></div></header>

        <section class="client-metrics" aria-label="Сводка по клиентам">
            <div class="client-metric"><span>Найдено клиентов</span><strong><?= $total_clients ?></strong></div>
            <div class="client-metric"><span>Активных поездок</span><strong><?= $total_trips ?></strong></div>
            <div class="client-metric metric-accent"><span>Стоимость бронирований</span><strong><?= clientMoney($total_value) ?></strong></div>
        </section>

        <form class="client-filters" method="GET">
            <label class="filter-search"><span>Поиск</span><input type="search" name="search" placeholder="Имя, телефон или e-mail" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>"></label>
            <label><span>Маршрут</span><select name="tour_id"><option value="0">Все маршруты</option><?php foreach ($tours as $tour): ?><option value="<?= (int)$tour['id'] ?>" <?= (int)$tour['id'] === $tour_filter ? 'selected' : '' ?>><?= htmlspecialchars($tour['public_name'] ?: $tour['name']) ?></option><?php endforeach; ?></select></label>
            <label><span>Сегмент</span><select name="tag"><option value="">Все теги</option><?php foreach ($all_available_tags as $tag): ?><option value="<?= htmlspecialchars($tag, ENT_QUOTES) ?>" <?= $tag === $tag_filter ? 'selected' : '' ?>><?= htmlspecialchars($tag) ?></option><?php endforeach; ?></select></label>
            <div class="filter-actions"><button class="btn btn-primary" type="submit">Показать</button><?php if ($search !== '' || $tour_filter || $tag_filter !== ''): ?><a class="btn btn-quiet" href="clients.php">Сбросить</a><?php endif; ?></div>
        </form>

        <?php if ($search !== '' || $tour_filter || $tag_filter !== ''): ?>
            <div class="active-filters"><?php if ($search !== ''): ?><span>Поиск: <?= htmlspecialchars($search) ?></span><?php endif; ?><?php if ($tour_filter): ?><span>Выбран маршрут</span><?php endif; ?><?php if ($tag_filter !== ''): ?><span>Тег: <?= htmlspecialchars($tag_filter) ?></span><?php endif; ?></div>
        <?php endif; ?>

        <section class="duplicate-review" id="possible-duplicates">
            <header><div><span class="eyebrow">Контроль качества базы</span><h2>Возможные дубли</h2><p>Система только предлагает совпадения. Решение об объединении остаётся за вами.</p></div><span class="duplicate-count"><?= count($potential_duplicates) ?></span></header>
            <?php if ($potential_duplicates): ?><div class="duplicate-list">
                <?php foreach ($potential_duplicates as $duplicate):
                    $primary = $duplicate['primary']; $candidate = $duplicate['candidate'];
                    $compareUrl = 'client.php?phone=' . rawurlencode($primary['phone']) . '&return_to=' . rawurlencode($clients_url) . '&merge_phone=' . rawurlencode($candidate['phone']);
                ?>
                <article class="duplicate-row">
                    <div class="duplicate-people">
                        <div><strong><?= htmlspecialchars($primary['client_name'] ?: 'Без имени') ?></strong><span><?= htmlspecialchars(displayPhone($primary['phone'])) ?><?= !empty($primary['email']) ? ' · '.htmlspecialchars($primary['email']) : '' ?></span></div>
                        <span class="duplicate-separator">↔</span>
                        <div><strong><?= htmlspecialchars($candidate['client_name'] ?: 'Без имени') ?></strong><span><?= htmlspecialchars(displayPhone($candidate['phone'])) ?><?= !empty($candidate['email']) ? ' · '.htmlspecialchars($candidate['email']) : '' ?></span></div>
                    </div>
                    <div class="duplicate-reasons"><?php foreach ($duplicate['reasons'] as $reason): ?><span><?= htmlspecialchars($reason) ?></span><?php endforeach; ?></div>
                    <a class="btn btn-quiet duplicate-review-button" href="<?= htmlspecialchars($compareUrl, ENT_QUOTES) ?>">Проверить</a>
                </article>
                <?php endforeach; ?>
            </div><?php else: ?><div class="duplicate-empty"><strong>Явных совпадений нет</strong><span>Новые варианты появятся здесь автоматически.</span></div><?php endif; ?>
        </section>

        <section class="client-directory">
            <div class="directory-head" aria-hidden="true"><span>Клиент</span><span>Активность</span><span>Теги и заметка</span><span></span></div>
            <?php foreach ($clients as $client):
                $tags = clientTagsFromString($client['tags'] ?? '');
                $profileUrl = 'client.php?phone=' . rawurlencode($client['phone']) . '&return_to=' . rawurlencode($clients_url);
            ?>
                <article class="client-row">
                    <div class="client-identity"><a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($client['client_name'] ?: 'Без имени') ?></a><div><a href="tel:<?= htmlspecialchars(normalizePhone($client['phone']), ENT_QUOTES) ?>"><?= htmlspecialchars(displayPhone($client['phone'])) ?></a><?php if (!empty($client['email'])): ?><a href="mailto:<?= htmlspecialchars($client['email'], ENT_QUOTES) ?>"><?= htmlspecialchars($client['email']) ?></a><?php endif; ?></div></div>
                    <div class="client-activity"><strong><?= clientCountPhrase((int)$client['active_trips'], ['поездка','поездки','поездок']) ?> · <?= clientCountPhrase((int)$client['total_seats'], ['место','места','мест']) ?></strong><span><?= clientMoney($client['booking_value']) ?><?php if (!empty($client['last_trip_date'])): ?> · последняя <?= date('d.m.Y', strtotime($client['last_trip_date'])) ?><?php endif; ?></span></div>
                    <div class="client-segments"><div class="tag-list"><?php foreach ($tags as $tag): ?><span class="tag" style="<?= clientTagStyle($tag) ?>"><?= htmlspecialchars($tag) ?></span><?php endforeach; ?><?php if (!$tags): ?><span class="muted">Без тегов</span><?php endif; ?></div><?php if (!empty($client['global_note'])): ?><p><?= htmlspecialchars($client['global_note']) ?></p><?php endif; ?></div>
                    <a class="open-profile" href="<?= htmlspecialchars($profileUrl, ENT_QUOTES) ?>">Открыть <span>→</span></a>
                </article>
            <?php endforeach; ?>
            <?php if (!$clients): ?><div class="empty-state"><strong>Клиенты не найдены</strong><span>Попробуйте изменить поиск или сбросить фильтры.</span></div><?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
