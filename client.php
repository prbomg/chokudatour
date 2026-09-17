<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'auth.php';
require_once __DIR__ . '/homepage_helpers.php';
require_once __DIR__ . '/participant_seats.php';
require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/client_workspace_helpers.php';
require_once __DIR__ . '/client_phone_migration.php';
require_once __DIR__ . '/activity_log.php';
if ($current_user_role !== 'admin') { http_response_code(403); exit('Доступ закрыт.'); }

$phone = trim((string)($_GET['phone'] ?? ''));
if (normalizePhone($phone) === '') { header('Location: clients.php'); exit; }
$return_url = clientReturnUrl($_GET['return_to'] ?? 'clients.php');
$return_suffix = isset($_GET['return_to']) ? '&return_to=' . rawurlencode($return_url) : '';
$return_is_event = str_starts_with($return_url, 'event.php?');
$profile_url = 'client.php?phone=' . rawurlencode($phone) . $return_suffix;
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireFormToken();

$part_cols = $pdo->query('SHOW COLUMNS FROM participants')->fetchAll(PDO::FETCH_COLUMN);
$name_col = in_array('client_name', $part_cols, true) ? 'client_name' : 'name';
$page_error = '';

function redirectClientProfile(string $url, string $message): void
{
    header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . 'msg=' . rawurlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $global_tags = clientTagsFromString($pdo->query("SELECT setting_value FROM global_settings WHERE setting_key='client_tags'")->fetchColumn());
        if (isset($_POST['merge_client'])) {
            mergeClientProfiles($pdo, $phone, (string)($_POST['source_phone'] ?? ''));
            redirectClientProfile($profile_url, 'client_merged');
        }
        if (isset($_POST['rename_tag'])) {
            $old = validateClientTag($_POST['old_tag'] ?? '');
            $new = validateClientTag($_POST['new_tag'] ?? '');
            if (!in_array($old, $global_tags, true)) throw new InvalidArgumentException('Исходный тег не найден.');
            $renamed = array_values(array_unique(array_map(fn($tag) => $tag === $old ? $new : $tag, $global_tags)));
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE global_settings SET setting_value=? WHERE setting_key='client_tags'")->execute([implode(',', $renamed)]);
            foreach ($pdo->query('SELECT phone,tags FROM client_profiles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tags = clientTagsFromString($row['tags']);
                if (!in_array($old, $tags, true)) continue;
                $tags = array_values(array_unique(array_map(fn($tag) => $tag === $old ? $new : $tag, $tags)));
                $pdo->prepare('UPDATE client_profiles SET tags=? WHERE phone=?')->execute([implode(',', $tags), $row['phone']]);
            }
            $pdo->commit();
            redirectClientProfile($profile_url, 'tag_renamed');
        }
        if (isset($_POST['delete_tag'])) {
            $deleted = validateClientTag($_POST['delete_tag_name'] ?? '');
            if (!in_array($deleted, $global_tags, true)) throw new InvalidArgumentException('Тег не найден.');
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE global_settings SET setting_value=? WHERE setting_key='client_tags'")->execute([implode(',', array_values(array_filter($global_tags, fn($tag) => $tag !== $deleted)))]);
            foreach ($pdo->query('SELECT phone,tags FROM client_profiles')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $tags = array_values(array_filter(clientTagsFromString($row['tags']), fn($tag) => $tag !== $deleted));
                $pdo->prepare('UPDATE client_profiles SET tags=? WHERE phone=?')->execute([implode(',', $tags), $row['phone']]);
            }
            $pdo->commit();
            redirectClientProfile($profile_url, 'tag_deleted');
        }
        if (isset($_POST['update_profile'])) {
            $posted = is_array($_POST['tags'] ?? null) ? $_POST['tags'] : [];
            $selected = [];
            foreach ($posted as $tag) {
                $tag = validateClientTag($tag);
                if (in_array($tag, $global_tags, true)) $selected[] = $tag;
            }
            $custom = trim((string)($_POST['custom_tag'] ?? ''));
            if ($custom !== '') {
                $custom = validateClientTag($custom);
                $selected[] = $custom;
                if (!in_array($custom, $global_tags, true)) {
                    $global_tags[] = $custom;
                    $pdo->prepare("UPDATE global_settings SET setting_value=? WHERE setting_key='client_tags'")->execute([implode(',', array_unique($global_tags))]);
                }
            }
            $selected = array_values(array_unique($selected));
            if (count($selected) > 20) throw new InvalidArgumentException('Для одного клиента можно выбрать не более 20 тегов.');
            $note = trim((string)($_POST['global_note'] ?? ''));
            if (mb_strlen($note) > 5000) throw new InvalidArgumentException('Заметка не должна превышать 5000 символов.');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM client_profiles WHERE phone=?'); $stmt->execute([$phone]);
            if ($stmt->fetchColumn()) $pdo->prepare('UPDATE client_profiles SET tags=?,global_note=? WHERE phone=?')->execute([implode(',', $selected), $note, $phone]);
            else $pdo->prepare('INSERT INTO client_profiles (phone,tags,global_note) VALUES (?,?,?)')->execute([$phone, implode(',', $selected), $note]);
            redirectClientProfile($profile_url, 'saved');
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(422); $page_error = $e->getMessage();
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        http_response_code(500); $page_error = 'Не удалось сохранить изменения. Повторите попытку.';
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

$stmt = $pdo->prepare('SELECT * FROM client_profiles WHERE phone=?'); $stmt->execute([$phone]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['tags'=>'','global_note'=>''];
$current_tags = clientTagsFromString($profile['tags']);
$all_existing_tags = clientTagsFromString($pdo->query("SELECT setting_value FROM global_settings WHERE setting_key='client_tags'")->fetchColumn());
$duplicate_stmt = $pdo->prepare("SELECT p.phone,MAX(p.{$name_col}) client_name,COUNT(*) bookings FROM participants p WHERE p.phone<>? AND p.phone<>'' GROUP BY p.phone ORDER BY client_name ASC LIMIT 500");
$duplicate_stmt->execute([$phone]);
$merge_candidates = $duplicate_stmt->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT p.*,e.id event_id,e.tour_date,e.time,t.name tour_name,t.public_name
        FROM participants p JOIN events e ON p.event_id=e.id JOIN tours_catalog t ON e.tour_id=t.id
        WHERE p.phone=? ORDER BY e.tour_date DESC,p.id DESC";
$stmt = $pdo->prepare($sql); $stmt->execute([$phone]); $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$history) { http_response_code(404); exit('Клиент не найден.'); }

$client_name = $history[0][$name_col] ?: 'Без имени';
$client_email = ''; $active_event_ids = []; $total_seats = 0; $booking_value = 0;
foreach ($history as $trip) {
    if ($client_email === '' && !empty($trip['email'])) $client_email = $trip['email'];
    if (($trip['status'] ?? '') === 'Отмена') continue;
    $active_event_ids[(int)$trip['event_id']] = true;
    $total_seats += participantSeats($trip);
    $booking_value += moneyValue($trip['price'] ?? 0);
}
$active_trips = count($active_event_ids);
$clean_phone = preg_replace('/[^0-9]/', '', $phone);
if (str_starts_with($clean_phone, '8') && strlen($clean_phone) === 11) $clean_phone = '7' . substr($clean_phone, 1);

require __DIR__ . '/client_view.php';
