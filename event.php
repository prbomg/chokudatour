<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'auth.php';
require_once __DIR__ . '/homepage_helpers.php';
require_once __DIR__ . '/request_helpers.php';
require_once __DIR__ . '/participant_seats.php';

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

function eventStatuses(): array
{
    return ['Бронь', 'Предоплата', 'Оплачено', 'Оплата на месте', 'Отмена'];
}

function eventParticipantInput(PDO $pdo, array $input): array
{
    $name = trim((string)($input['client_name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $seatsRaw = (string)($input['seats'] ?? '');
    $priceRaw = (string)($input['price'] ?? '0');
    $source = trim((string)($input['source'] ?? 'CRM'));
    $status = trim((string)($input['status'] ?? 'Бронь'));
    if ($name === '') throw new InvalidArgumentException('Укажите имя туриста.');
    if (strlen(preg_replace('/\D+/', '', $phone)) < 5) throw new InvalidArgumentException('Укажите корректный телефон.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный e-mail.');
    if (!ctype_digit($seatsRaw) || (int)$seatsRaw < 1) throw new InvalidArgumentException('Количество мест должно быть целым положительным числом.');
    if (!preg_match('/^\d+$/D', $priceRaw)) throw new InvalidArgumentException('Сумма бронирования должна быть целым неотрицательным числом.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM booking_sources WHERE name=?');
    $stmt->execute([$source]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Выберите источник из списка.');
    if (!in_array($status, eventStatuses(), true)) throw new InvalidArgumentException('Выберите статус из списка.');
    return ['name'=>$name, 'phone'=>$phone, 'email'=>$email, 'seats'=>(int)$seatsRaw, 'price'=>(int)$priceRaw, 'source'=>$source, 'status'=>$status, 'notes'=>trim((string)($input['notes'] ?? ''))];
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
            eventRedirect($event_id, $return_suffix, 'event_updated');
        } elseif (isset($_POST['add_participant']) || isset($_POST['update_participant'])) {
            $data = eventParticipantInput($pdo, $_POST);
            $seat_binding = participantSeatBinding($pdo, $data['seats']);
            $name_col = in_array('client_name', $part_cols, true) ? 'client_name' : 'name';
            if (isset($_POST['add_participant'])) {
                $token_sql = in_array('ticket_token', $part_cols, true) ? ', ticket_token' : '';
                $params = array_merge([$event_id, $data['name'], $data['phone'], $data['email']], $seat_binding['values'], [$data['price'], $data['source'], $data['status'], $data['notes']]);
                if ($token_sql) $params[] = bin2hex(random_bytes(16));
                $pdo->prepare("INSERT INTO participants (event_id, {$name_col}, phone, email, {$seat_binding['columns']}, price, source, status, notes{$token_sql}) VALUES (?, ?, ?, ?, {$seat_binding['placeholders']}, ?, ?, ?, ?" . ($token_sql ? ', ?' : '') . ')')->execute($params);
                eventRedirect($event_id, $return_suffix, 'participant_added');
            }
            $participantId = (int)($_POST['participant_id'] ?? 0);
            $exists = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE id=? AND event_id=?');
            $exists->execute([$participantId, $event_id]);
            if ($participantId < 1 || !$exists->fetchColumn()) throw new InvalidArgumentException('Бронирование не найдено.');
            $pdo->prepare("UPDATE participants SET {$name_col}=?, phone=?, email=?, {$seat_binding['assignments']}, price=?, source=?, status=?, notes=? WHERE id=? AND event_id=?")
                ->execute(array_merge([$data['name'], $data['phone'], $data['email']], $seat_binding['values'], [$data['price'], $data['source'], $data['status'], $data['notes'], $participantId, $event_id]));
            eventRedirect($event_id, $return_suffix, 'participant_updated');
        } elseif (isset($_POST['del_participant']) && $current_user_role === 'admin') {
            $pdo->prepare('DELETE FROM participants WHERE id=? AND event_id=?')->execute([(int)$_POST['del_participant'], $event_id]);
            eventRedirect($event_id, $return_suffix, 'participant_deleted');
        } elseif (isset($_POST['add_expense'])) {
            $amountRaw = str_replace(',', '.', trim((string)($_POST['amount'] ?? '')));
            $category = trim((string)($_POST['category'] ?? 'Прочее'));
            $description = trim((string)($_POST['description'] ?? ''));
            if (!is_numeric($amountRaw) || (float)$amountRaw <= 0 || (float)$amountRaw > 99999999.99) throw new InvalidArgumentException('Укажите положительную сумму расхода.');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE name=?');
            $stmt->execute([$category]);
            if ($category !== 'Прочее' && !$stmt->fetchColumn()) throw new InvalidArgumentException('Выберите категорию расхода из списка.');
            $receipt_path = '';
            if (isset($_FILES['receipt']) && ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['receipt']['error'] !== UPLOAD_ERR_OK || ($_FILES['receipt']['size'] ?? 0) > 8 * 1024 * 1024) throw new InvalidArgumentException('Не удалось загрузить чек или файл больше 8 МБ.');
                $ext = strtolower(pathinfo((string)$_FILES['receipt']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) throw new InvalidArgumentException('Чек должен быть изображением JPG, PNG или WebP.');
                if (!is_dir('uploads') && !mkdir('uploads', 0755, true)) throw new RuntimeException('Не удалось подготовить папку для чеков.');
                $receipt_path = 'uploads/rec_' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $receipt_path)) throw new RuntimeException('Не удалось сохранить чек.');
            }
            $pdo->prepare('INSERT INTO expenses (event_id, amount, category, description, receipt_path) VALUES (?, ?, ?, ?, ?)')
                ->execute([$event_id, number_format((float)$amountRaw, 2, '.', ''), $category, $description, $receipt_path]);
            eventRedirect($event_id, $return_suffix, 'expense_added');
        } elseif (isset($_POST['del_expense'])) {
            $pdo->prepare('DELETE FROM expenses WHERE id=? AND event_id=?')->execute([(int)$_POST['del_expense'], $event_id]);
            eventRedirect($event_id, $return_suffix, 'expense_deleted');
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

$total_seats = 0; $total_income = 0; $active_bookings = 0; $cancelled_bookings = 0;
foreach ($participants as $participant) {
    if (($participant['status'] ?? '') === 'Отмена') { $cancelled_bookings++; continue; }
    $active_bookings++;
    $total_seats += participantSeats($participant);
    $total_income += (int)($participant['price'] ?? 0);
}
$total_expenses = array_reduce($expenses, fn($sum, $expense) => $sum + (float)($expense['amount'] ?? 0), 0.0);
$profit = $total_income - $total_expenses;
$months_ru = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$ts = strtotime($event[$date_col]);
$date_formatted = date('j', $ts) . ' ' . $months_ru[(int)date('n', $ts)] . ' ' . date('Y', $ts);
$event_return_url = 'event.php?id=' . $event_id . $return_suffix;

require __DIR__ . '/event_view.php';
