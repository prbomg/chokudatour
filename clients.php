<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';
require_once __DIR__ . '/participant_seats.php';
require_once __DIR__ . '/client_workspace_helpers.php';
require_once __DIR__ . '/client_phone_migration.php';
if ($current_user_role !== 'admin') { http_response_code(403); exit('Доступ закрыт.'); }
ensureClientWorkspace($pdo);

$part_cols = $pdo->query('SHOW COLUMNS FROM participants')->fetchAll(PDO::FETCH_COLUMN);
$name_col = in_array('client_name', $part_cols, true) ? 'client_name' : 'name';
$seat_sql = participantSeatsSql($pdo, 'p');
$tour_filter = max(0, (int)($_GET['tour_id'] ?? 0));
$search = trim((string)($_GET['search'] ?? ''));
$tag_filter = trim((string)($_GET['tag'] ?? ''));
$clients_url = clientListUrl($_GET);

$having = []; $params = [];
if ($tour_filter > 0) {
    $having[] = "SUM(CASE WHEN e.tour_id=? AND p.status!='Отмена' THEN 1 ELSE 0 END)>0";
    $params[] = $tour_filter;
}
if ($search !== '') {
    $having[] = "(MAX(p.{$name_col}) LIKE ? OR p.phone LIKE ? OR MAX(p.email) LIKE ?)";
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
if ($tag_filter !== '') {
    $having[] = "CONCAT(',', COALESCE(cp.tags,''), ',') LIKE ?";
    $params[] = '%,' . $tag_filter . ',%';
}
$having_sql = $having ? 'HAVING ' . implode(' AND ', $having) : '';

$sql = "SELECT p.phone, MAX(p.{$name_col}) client_name, MAX(p.email) email,
               COUNT(DISTINCT CASE WHEN p.status!='Отмена' THEN p.event_id END) active_trips,
               SUM(CASE WHEN p.status!='Отмена' THEN {$seat_sql} ELSE 0 END) total_seats,
               SUM(CASE WHEN p.status!='Отмена' THEN p.price ELSE 0 END) booking_value,
               MAX(CASE WHEN p.status!='Отмена' THEN e.tour_date ELSE NULL END) last_trip_date,
               cp.tags, cp.global_note
        FROM participants p
        LEFT JOIN events e ON p.event_id=e.id
        LEFT JOIN client_profiles cp ON p.phone=cp.phone
        GROUP BY p.phone, cp.tags, cp.global_note
        {$having_sql}
        ORDER BY booking_value DESC, active_trips DESC, client_name ASC
        LIMIT 500";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$duplicates_sql = "SELECT p.phone, MAX(p.{$name_col}) client_name, MAX(p.email) email,
                          COUNT(DISTINCT p.event_id) active_trips,
                          SUM(CASE WHEN p.status!='Отмена' THEN {$seat_sql} ELSE 0 END) total_seats
                   FROM participants p
                   WHERE COALESCE(p.phone,'')!=''
                   GROUP BY p.phone
                   ORDER BY client_name ASC
                   LIMIT 500";
$potential_duplicates = clientPotentialDuplicates($pdo->query($duplicates_sql)->fetchAll(PDO::FETCH_ASSOC));

$all_available_tags = clientTagsFromString($pdo->query("SELECT setting_value FROM global_settings WHERE setting_key='client_tags'")->fetchColumn());
$tours = $pdo->query('SELECT id,name,public_name FROM tours_catalog ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$total_clients = count($clients);
$total_trips = array_sum(array_map(fn($row)=>(int)$row['active_trips'], $clients));
$total_value = array_sum(array_map(fn($row)=>(float)$row['booking_value'], $clients));

require __DIR__ . '/clients_view.php';
