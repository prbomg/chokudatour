<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'auth.php';
require_once __DIR__ . '/homepage_helpers.php';
require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/participant_seats.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/expense_helpers.php';
require_once __DIR__ . '/payment_helpers.php';

$return_url = homeReturnUrl($_GET['return_to'] ?? 'index.php');
$return_suffix = '&return_to=' . rawurlencode($return_url);
$event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($event_id <= 0) { header('Location: ' . $return_url); exit; }
requireEventAccess($pdo, $event_id, $current_user_role, $current_user_name);
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireFormToken();

$events_cols = $pdo->query('SHOW COLUMNS FROM events')->fetchAll(PDO::FETCH_COLUMN);
$date_col = in_array('tour_date', $events_cols, true) ? 'tour_date' : (in_array('event_date', $events_cols, true) ? 'event_date' : 'date');
$guide_col = in_array('guide', $events_cols, true) ? 'guide' : 'guide_id';
$time_col = 'time';
$part_cols = $pdo->query('SHOW COLUMNS FROM participants')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('price', $part_cols, true)) {
    try { $pdo->exec('ALTER TABLE participants ADD COLUMN price INT DEFAULT 0'); } catch (PDOException $e) {}
}

$pdo->exec('CREATE TABLE IF NOT EXISTS booking_sources (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, sort_order INT DEFAULT 999)');
if ((int)$pdo->query('SELECT COUNT(*) FROM booking_sources')->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO booking_sources (name, sort_order) VALUES ('Прямые',1),('Трипстер',2),('Спутник 8',3),('CRM',4),('Сайт',5)");
}

function eventMoney($amount): string
{
    $value = (float)$amount;
    return number_format($value, abs($value - round($value)) < 0.00001 ? 0 : 2, ',', ' ') . ' ₽';
}

function eventCountPhrase(int $count, array $forms): string
{
    $mod100 = $count % 100;
    $mod10 = $count % 10;
    $form = ($mod100 >= 11 && $mod100 <= 14) ? $forms[2] : ($mod10 === 1 ? $forms[0] : (($mod10 >= 2 && $mod10 <= 4) ? $forms[1] : $forms[2]));
    return $count . ' ' . $form;
}

function eventRedirect(int $eventId, string $returnSuffix, string $message): void
{
    header('Location: event.php?id=' . $eventId . $returnSuffix . '&msg=' . rawurlencode($message));
    exit;
}

$page_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_event_details'])) {
            if ($current_user_role !== 'admin') throw new InvalidArgumentException('Доступ запрещён.');
            $details = homeEventDetails($pdo, $_POST);
            $pdo->prepare("UPDATE events SET {$date_col}=?, {$time_col}=?, tour_id=?, {$guide_col}=?, notes=? WHERE id=?")
                ->execute([$details['date'], $details['time'], $details['tour_id'], $details['guide'], $details['notes'], $event_id]);
            recordActivity($pdo, 'update', 'event', $event_id, 'Изменён выезд: ' . $details['tour_name'] . ', ' . $details['date']);
            eventRedirect($event_id, $return_suffix, 'event_updated');
        } elseif (isset($_POST['add_participant']) || isset($_POST['update_participant'])) {
            $data = eventParticipantInput($pdo, $_POST);
            $seat_binding = participantSeatBinding($pdo, $data['seats']);
            $name_col = in_array('client_name', $part_cols, true) ? 'client_name' : 'name';
            if (isset($_POST['add_participant'])) {
                assertEventCapacity($pdo, $event_id, $data['seats'], $data['status']);
                $token_sql = in_array('ticket_token', $part_cols, true) ? ', ticket_token' : '';
                $params = array_merge([$event_id, $data['name'], $data['phone'], $data['email']], $seat_binding['values'], [$data['price'], $data['source'], $data['status'], $data['notes']]);
                if ($token_sql) $params[] = bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO participants (event_id, {$name_col}, phone, email, {$seat_binding['columns']}, price, source, status, notes{$token_sql}) VALUES (?, ?, ?, ?, {$seat_binding['placeholders']}, ?, ?, ?, ?" . ($token_sql ? ', ?' : '') . ')')->execute($params);
                recordActivity($pdo, 'create', 'participant', (int)$pdo->lastInsertId(), 'Добавлено бронирование: ' . $data['name']);
                eventRedirect($event_id, $return_suffix, 'participant_added');
            }
            $participantId = (int)($_POST['participant_id'] ?? 0);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE id=? AND event_id=?');
            $exists->execute([$participantId, $event_id]);
            if ($participantId < 1 || !$exists->fetchColumn()) throw new InvalidArgumentException('Бронирование не найдено.');
            assertEventCapacity($pdo, $event_id, $data['seats'], $data['status'], $participantId);
            $pdo->prepare("UPDATE participants SET {$name_col}=?, phone=?, email=?, {$seat_binding['assignments']}, price=?, source=?, status=?, notes=? WHERE id=? AND event_id=?")
                ->execute(array_merge([$data['name'], $data['phone'], $data['email']], $seat_binding['values'], [$data['price'], $data['source'], $data['status'], $data['notes'], $participantId, $event_id]));
            recordActivity($pdo, 'update', 'participant', $participantId, 'Изменено бронирование: ' . $data['name']);
            eventRedirect($event_id, $return_suffix, 'participant_updated');
        } elseif (isset($_POST['del_participant']) && $current_user_role === 'admin') {
            $participantId = (int)$_POST['del_participant'];
            $row = activityRow($pdo, 'participants', $participantId);
            if ($row && (int)$row['event_id'] === $event_id) {
                $paymentStmt = $pdo->prepare('SELECT * FROM payments WHERE participant_id=?'); $paymentStmt->execute([$participantId]);
                $participantPayments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
                recordActivity($pdo, 'delete', 'participant', $participantId, 'Удалено бронирование: ' . ($row['client_name'] ?? ''), ['participant'=>$row,'payments'=>$participantPayments]);
                $pdo->prepare('DELETE FROM payments WHERE participant_id=?')->execute([$participantId]);
                $pdo->prepare('DELETE FROM participants WHERE id=? AND event_id=?')->execute([$participantId, $event_id]);
            }
            eventRedirect($event_id, $return_suffix, 'participant_deleted');
        } elseif (isset($_POST['add_expense'])) {
            addExpense($pdo, $event_id, $_POST, $_FILES);
            eventRedirect($event_id, $return_suffix, 'expense_added');
        } elseif (isset($_POST['del_expense'])) {
            deleteExpense($pdo, $event_id, (int)$_POST['del_expense']);
            eventRedirect($event_id, $return_suffix, 'expense_deleted');
        } elseif (isset($_POST['add_payment']) && $current_user_role === 'admin') {
            addPayment($pdo, $event_id, $_POST);
            eventRedirect($event_id, $return_suffix, 'payment_added');
        } elseif (isset($_POST['delete_payment']) && $current_user_role === 'admin') {
            deletePayment($pdo, $event_id, (int)$_POST['delete_payment']);
            eventRedirect($event_id, $return_suffix, 'payment_deleted');
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        $page_error = $e->getMessage();
    } catch (Throwable $e) {
        http_response_code(500);
        $page_error = 'Не удалось сохранить изменения. Повторите попытку.';
    }
}

$guides = $pdo->query('SELECT name FROM guides ORDER BY sort_order ASC, name ASC')->fetchAll(PDO::FETCH_COLUMN);
$expense_cats = $pdo->query('SELECT name FROM expense_categories ORDER BY sort_order ASC, name ASC')->fetchAll(PDO::FETCH_COLUMN);
$sources_list = $pdo->query('SELECT name FROM booking_sources ORDER BY sort_order ASC, name ASC')->fetchAll(PDO::FETCH_COLUMN);
$tours_list = $pdo->query('SELECT id, name, public_name FROM tours_catalog WHERE COALESCE(is_archived,0)=0 ORDER BY sort_order ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT e.*, t.name AS tour_name, t.public_name, t.duration, t.coordinates FROM events e JOIN tours_catalog t ON e.tour_id=t.id WHERE e.id=?");
$stmt->execute([$event_id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); exit('Выезд не найден.'); }

$stmt = $pdo->prepare('SELECT * FROM participants WHERE event_id=? ORDER BY id DESC');
$stmt->execute([$event_id]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT * FROM expenses WHERE event_id=? ORDER BY id DESC');
$stmt->execute([$event_id]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT p.*,pt.client_name FROM payments p JOIN participants pt ON pt.id=p.participant_id WHERE p.event_id=? ORDER BY p.paid_at DESC,p.id DESC');
$stmt->execute([$event_id]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$participant_payments = [];
foreach ($payments as $payment) {
    if (!empty($payment['voided_at'])) continue;
    $sign = ($payment['operation'] ?? 'payment') === 'refund' ? -1 : 1;
    $participant_payments[(int)$payment['participant_id']] = ($participant_payments[(int)$payment['participant_id']] ?? 0) + $sign * (float)$payment['amount'];
}

$total_seats = 0; $total_income = 0; $active_bookings = 0; $cancelled_bookings = 0; $outstanding = 0.0;
foreach ($participants as $participant) {
    if (($participant['status'] ?? '') === 'Отмена') { $cancelled_bookings++; continue; }
    $active_bookings++;
    $total_seats += participantSeats($participant);
    $total_income += (int)($participant['price'] ?? 0);
    $outstanding += max(0, (float)($participant['price'] ?? 0) - (float)($participant_payments[(int)$participant['id']] ?? 0));
}
$total_expenses = array_reduce($expenses, fn($sum, $expense) => $sum + (float)($expense['amount'] ?? 0), 0.0);
$profit = $total_income - $total_expenses;
$total_received = array_sum($participant_payments);
$months_ru = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$ts = strtotime($event[$date_col]);
$date_formatted = date('j', $ts) . ' ' . $months_ru[(int)date('n', $ts)] . ' ' . date('Y', $ts);
$event_return_url = 'event.php?id=' . $event_id . $return_suffix;

require __DIR__ . '/event_view.php';
