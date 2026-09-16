<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/backup_helpers.php';
require_once __DIR__ . '/booking_helpers.php';

if ($current_user_role !== 'admin') { http_response_code(403); exit('Доступ закрыт.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit('Метод не поддерживается.'); }
requireFormToken();

$action = (string)($_POST['backup_action'] ?? '');
$stamp = date('Y-m-d_H-i-s');

try {
    if ($action === 'clients') {
        $seatSql = participantSeatsSql($pdo, 'p');
        $rows = $pdo->query("SELECT p.phone,MAX(p.client_name) client_name,MAX(p.email) email,COUNT(DISTINCT CASE WHEN p.status!='Отмена' THEN p.event_id END) trips,SUM(CASE WHEN p.status!='Отмена' THEN {$seatSql} ELSE 0 END) seats,SUM(CASE WHEN p.status!='Отмена' THEN p.price ELSE 0 END) booking_value,MAX(CASE WHEN p.status!='Отмена' THEN e.tour_date END) last_trip,MAX(cp.tags) tags,MAX(cp.global_note) note FROM participants p LEFT JOIN events e ON e.id=p.event_id LEFT JOIN client_profiles cp ON cp.phone=p.phone GROUP BY p.phone ORDER BY client_name")->fetchAll(PDO::FETCH_ASSOC);
        sendCsvDownload("clients_{$stamp}.csv", ['Имя','Телефон','E-mail','Поездок','Мест','Стоимость бронирований','Последняя поездка','Теги','Заметка'], array_map(static fn(array $r): array => [$r['client_name'],displayPhone($r['phone']),$r['email'],$r['trips'],$r['seats'],$r['booking_value'],$r['last_trip'],$r['tags'],$r['note']], $rows));
    }
    if ($action === 'events') {
        $seatSql = participantSeatsSql($pdo, 'p');
        $rows = $pdo->query("SELECT e.id,e.tour_date,e.time,t.name tour_name,e.guide,e.notes,e.completed_at,COUNT(DISTINCT CASE WHEN p.status!='Отмена' THEN p.id END) bookings,COALESCE(SUM(CASE WHEN p.status!='Отмена' THEN {$seatSql} ELSE 0 END),0) seats,COALESCE(SUM(CASE WHEN p.status!='Отмена' THEN p.price ELSE 0 END),0) income,COALESCE((SELECT SUM(ex.amount) FROM expenses ex WHERE ex.event_id=e.id),0) expenses FROM events e LEFT JOIN tours_catalog t ON t.id=e.tour_id LEFT JOIN participants p ON p.event_id=e.id GROUP BY e.id,e.tour_date,e.time,t.name,e.guide,e.notes,e.completed_at ORDER BY e.tour_date,e.time,e.id")->fetchAll(PDO::FETCH_ASSOC);
        sendCsvDownload("events_{$stamp}.csv", ['ID','Дата','Время','Маршрут','Гид','Примечание','Проведён','Броней','Мест','Доход','Расходы'], $rows);
    }
    if ($action === 'payments') {
        $rows = $pdo->query("SELECT py.id,py.paid_at,e.tour_date,t.name tour_name,pt.client_name,pt.phone,py.operation,py.amount,py.method,py.note,py.created_by,py.created_at FROM payments py JOIN events e ON e.id=py.event_id JOIN tours_catalog t ON t.id=e.tour_id JOIN participants pt ON pt.id=py.participant_id ORDER BY py.paid_at,py.id")->fetchAll(PDO::FETCH_ASSOC);
        sendCsvDownload("payments_{$stamp}.csv", ['ID','Дата операции','Дата выезда','Маршрут','Турист','Телефон','Операция','Сумма','Способ','Комментарий','Добавил','Создано'], array_map(static function(array $row): array { $row['phone']=displayPhone($row['phone']); return $row; }, $rows));
    }
    if ($action === 'database') {
        $filename = "chokudatour_database_{$stamp}.sql";
        $path = createDatabaseBackup($pdo);
        saveLastBackup($pdo, $filename);
        sendTemporaryDownload($path, $filename, 'application/sql; charset=UTF-8');
    }
    if ($action === 'receipts') {
        [$path,$count] = createReceiptsArchive($pdo);
        sendTemporaryDownload($path, "receipts_{$stamp}_{$count}-files.zip", 'application/zip');
    }
    throw new InvalidArgumentException('Неизвестный тип выгрузки.');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Не удалось создать выгрузку: ' . $e->getMessage());
}
