<?php
require_once 'auth.php';
require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/activity_log.php';

if ($current_user_role !== 'admin') { http_response_code(403); exit('Доступ запрещён.'); }
ensureActivityLog($pdo);
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
$types = ['event'=>'Выезд','participant'=>'Бронирование','expense'=>'Расход'];
?>
<!DOCTYPE html>
<html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>История изменений</title><link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__.'/assets/style.css') ?>">
<style>
body{background:#f5f7fb}.history-shell{max-width:1180px;margin:0 auto;padding:28px 20px 60px}.history-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:20px}.history-head h1{margin:0;font-size:30px}.history-head p{margin:7px 0 0;color:#64748b}.history-filters{display:flex;gap:8px;flex-wrap:wrap}.history-filters a{padding:9px 13px;border-radius:10px;background:#fff;color:#475569;text-decoration:none;border:1px solid #e2e8f0;font-weight:650}.history-filters a.active{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.history-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(15,23,42,.05)}.history-row{display:grid;grid-template-columns:145px 150px 1fr 190px;gap:16px;align-items:center;padding:16px 18px;border-bottom:1px solid #edf2f7}.history-row:last-child{border-bottom:0}.history-meta{font-size:13px;color:#64748b}.history-action{font-weight:750}.history-action.delete{color:#b91c1c}.history-action.restore{color:#047857}.history-summary{font-weight:650;color:#1e293b}.history-user{display:block;font-size:13px;color:#64748b;margin-top:4px}.restore-btn{border:0;background:#eef2ff;color:#3730a3;border-radius:9px;padding:9px 12px;font-weight:700;cursor:pointer}.restored{font-size:13px;color:#047857;font-weight:700}.empty{padding:48px;text-align:center;color:#64748b}.alert{padding:12px 14px;border-radius:10px;background:#fef2f2;color:#991b1b;margin-bottom:16px}@media(max-width:760px){.history-head{display:block}.history-filters{margin-top:16px}.history-row{grid-template-columns:1fr;gap:7px}.history-row form{justify-self:start}}
</style></head><body>
<?php require 'navbar.php'; ?>
<main class="history-shell">
 <div class="history-head"><div><h1>История изменений</h1><p>Последние 200 действий. Удалённые выезды, бронирования и расходы можно вернуть.</p></div>
 <div class="history-filters"><?php foreach (['all'=>'Все','delete'=>'Удаления','create'=>'Создания','update'=>'Изменения','restore'=>'Восстановления'] as $key=>$label): ?><a class="<?= $filter===$key?'active':'' ?>" href="history.php?filter=<?= $key ?>"><?= $label ?></a><?php endforeach; ?></div></div>
 <?php if ($page_error): ?><div class="alert"><?= htmlspecialchars($page_error) ?></div><?php endif; ?>
 <section class="history-card">
 <?php if (!$activities): ?><div class="empty">В журнале пока нет действий.</div><?php endif; ?>
 <?php foreach ($activities as $activity): ?>
  <div class="history-row">
   <div class="history-meta"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($activity['created_at']))) ?></div>
   <div class="history-action <?= htmlspecialchars($activity['action']) ?>"><?= htmlspecialchars($labels[$activity['action']] ?? $activity['action']) ?><span class="history-user"><?= htmlspecialchars($types[$activity['entity_type']] ?? $activity['entity_type']) ?></span></div>
   <div class="history-summary"><?= htmlspecialchars($activity['summary']) ?><span class="history-user"><?= htmlspecialchars($activity['user_name'] ?: 'Система') ?></span></div>
   <div><?php if ($activity['action']==='delete' && empty($activity['restored_at'])): ?><form method="post" onsubmit="return confirm('Восстановить эту запись?')"><?= formTokenInput() ?><button class="restore-btn" name="restore_activity" value="<?= (int)$activity['id'] ?>">Восстановить</button></form><?php elseif (!empty($activity['restored_at'])): ?><span class="restored">Восстановлено</span><?php endif; ?></div>
  </div>
 <?php endforeach; ?>
 </section>
</main>
<script><?php if (($_GET['msg']??'')==='restored'): ?>window.addEventListener('DOMContentLoaded',()=>{if(window.showToast)showToast('Запись восстановлена')});<?php endif; ?></script>
</body></html>
