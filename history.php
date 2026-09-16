<?php
require_once 'auth.php';
require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/activity_log.php';

if ($current_user_role !== 'admin') { http_response_code(403); exit('Доступ запрещён.'); }
$page_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_activity'])) {
    requireFormToken();
    try {
        restoreActivity($pdo, (int)$_POST['restore_activity']);
        header('Location: history.php?msg=restored'); exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(422); $page_error = $e->getMessage();
    } catch (Throwable $e) {
        http_response_code(500); $page_error = 'Не удалось восстановить запись. Повторите попытку.';
    }
}

$filter = trim((string)($_GET['filter'] ?? 'all'));
if (!in_array($filter, ['all','delete','create','update','restore'], true)) $filter = 'all';
$params = [];
$where = '';
if ($filter !== 'all') { $where = 'WHERE action=?'; $params[] = $filter; }
$stmt = $pdo->prepare("SELECT * FROM activity_log {$where} ORDER BY id DESC LIMIT 200");
$stmt->execute($params);
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$labels = ['create'=>'Создание','update'=>'Изменение','delete'=>'Удаление','restore'=>'Восстановление'];
$types = ['event'=>'Выезд','participant'=>'Бронирование','expense'=>'Расход','payment'=>'Платёж','client'=>'Клиент'];
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>История изменений</title><link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
<link rel="stylesheet" href="assets/history-workspace.css?v=<?= filemtime(__DIR__.'/assets/history-workspace.css') ?>"></head><body>
<div id="toast-container"></div>
<div class="container">
 <?php require 'navbar.php'; ?>
 <header class="history-hero"><div><span class="eyebrow">Контроль изменений</span><h1>История</h1><p>Последние 200 действий с выездами, бронированиями и расходами. Удалённые записи можно восстановить.</p></div>
 <div class="history-filters"><?php foreach (['all'=>'Все','delete'=>'Удаления','create'=>'Создания','update'=>'Изменения','restore'=>'Восстановления'] as $key=>$label): ?><a class="history-filter <?= $filter===$key?'active':'' ?>" href="history.php?filter=<?= $key ?>"><?= $label ?></a><?php endforeach; ?></div></header>
 <?php if ($page_error): ?><div class="history-alert"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg><?= htmlspecialchars($page_error) ?></div><?php endif; ?>
 <section class="history-card">
 <div class="history-table-head"><span>Дата и время</span><span>Действие</span><span>Описание</span><span style="text-align:right">Результат</span></div>
 <?php if (!$activities): ?><div class="history-empty"><span class="history-empty-icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg></span><h2>В журнале пока нет действий</h2><p>Новые изменения появятся здесь автоматически.</p></div><?php endif; ?>
 <?php foreach ($activities as $activity): ?>
  <div class="history-row">
   <div class="history-date"><?= htmlspecialchars(date('d.m.Y', strtotime($activity['created_at']))) ?><span class="history-type"><?= htmlspecialchars(date('H:i', strtotime($activity['created_at']))) ?></span></div>
   <div class="history-action-cell"><span class="history-icon <?= htmlspecialchars($activity['action']) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php if ($activity['action']==='create'): ?><path d="M12 5v14M5 12h14"/><?php elseif ($activity['action']==='update'): ?><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/><?php elseif ($activity['action']==='delete'): ?><path d="M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15"/><?php else: ?><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><?php endif; ?></svg></span><span class="history-action"><?= htmlspecialchars($labels[$activity['action']] ?? $activity['action']) ?><span class="history-type"><?= htmlspecialchars($types[$activity['entity_type']] ?? $activity['entity_type']) ?></span></span></div>
   <div class="history-summary"><?= htmlspecialchars($activity['summary']) ?><span class="history-user"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg><?= htmlspecialchars($activity['user_name'] ?: 'Система') ?></span></div>
   <div class="history-result"><?php if ($activity['action']==='delete' && empty($activity['restored_at'])): ?><form method="post" onsubmit="return confirm('Восстановить эту запись?')"><?= formTokenInput() ?><button class="restore-btn" name="restore_activity" value="<?= (int)$activity['id'] ?>"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>Восстановить</button></form><?php elseif (!empty($activity['restored_at'])): ?><span class="restored"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="m5 12 4 4L19 6"/></svg>Восстановлено</span><?php endif; ?></div>
  </div>
 <?php endforeach; ?>
 </section>
</div>
<script src="assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>"></script>
<script><?php if (($_GET['msg']??'')==='restored'): ?>window.addEventListener('DOMContentLoaded',()=>{if(window.showToast)showToast('Запись восстановлена')});<?php endif; ?></script>
</body></html>
