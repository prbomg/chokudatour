<?php
require_once __DIR__ . '/homepage_helpers.php';

function createPublicBooking(PDO $pdo, array $input, int $sourceId): array
{
    $token = (string)($input['booking_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Обновите форму и повторите отправку.');
    $date = (string)($input['booking_date'] ?? '');
    $name = trim((string)($input['client_name'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $seats = filter_var($input['seats'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if (!$seats || !validTourDate($date) || $name === '' || !preg_match('/^[0-9]{7,15}$/D', preg_replace('/\D/', '', $phone)) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Проверьте дату, имя, телефон, e-mail и количество человек.');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS booking_requests (token VARCHAR(64) PRIMARY KEY, participant_id INT NOT NULL)');
    $pdo->beginTransaction();
    try {
        // Serialize public bookings through the small guide roster. Two requests
        // for different routes cannot simultaneously assign the same free guide.
        $guides = $pdo->query('SELECT name, allowed_tours FROM guides ORDER BY name FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $duplicate = $pdo->prepare('SELECT participant_id FROM booking_requests WHERE token = ?');
        $duplicate->execute([$token]);
        if ($duplicate->fetchColumn()) { $pdo->commit(); return ['duplicate' => true]; }
        if ($date <= $pdo->query('SELECT CURDATE()')->fetchColumn()) throw new InvalidArgumentException('Выберите доступную будущую дату.');

        $tourId = (int)($input['tour_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM tours_catalog WHERE id = ? AND is_archived = 0');
        $stmt->execute([$tourId]);
        $tour = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tour) throw new InvalidArgumentException('Выбранный тур недоступен.');
        $group = ($tour['tour_type'] ?? '') === 'Групповая';
        if (!$group && $seats > 4) throw new InvalidArgumentException('Для индивидуальной экскурсии можно указать до 4 человек.');

        $days = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'working_days'")->fetchColumn();
        $available = in_array(date('N', strtotime($date)), explode(',', $days ?: ''), true);
        $ruleStmt = $pdo->prepare('SELECT action_type, tours FROM blocked_dates WHERE block_date = ? ORDER BY id DESC LIMIT 1');
        $ruleStmt->execute([$date]);
        $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC);
        if ($rule && ($rule['tours'] === 'all' || in_array((string)$tourId, explode(',', $rule['tours']), true))) $available = $rule['action_type'] === 'open';
        if (!$available) throw new InvalidArgumentException('Эта дата закрыта для выбранного тура.');

        $eventsStmt = $pdo->prepare('SELECT id, guide, tour_id FROM events WHERE tour_date = ? ORDER BY id');
        $eventsStmt->execute([$date]);
        $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
        $offStmt = $pdo->prepare('SELECT guide_name FROM guide_timeoffs WHERE date_off = ?');
        $offStmt->execute([$date]);
        $off = $offStmt->fetchAll(PDO::FETCH_COLUMN);
        $eligible = [];
        foreach ($guides as $g) {
            if (($g['allowed_tours'] === 'all' || in_array((string)$tourId, explode(',', $g['allowed_tours']), true)) && !in_array($g['name'], $off, true)) $eligible[] = $g['name'];
        }
        $eventId = null; $assigned = null;
        $sameTour = array_values(array_filter($events, fn($e) => (int)$e['tour_id'] === $tourId));
        if ($group && $sameTour) {
            foreach ($sameTour as $event) {
                $busy = array_filter($events, fn($other) => $other['guide'] === $event['guide'] && $other['id'] !== $event['id']);
                if (in_array($event['guide'], $eligible, true) && !$busy) { $eventId = $event['id']; $assigned = $event['guide']; break; }
            }
        } else {
            $busy = array_column($events, 'guide');
            foreach ($eligible as $guide) if (!in_array($guide, $busy, true)) { $assigned = $guide; break; }
        }
        if ($assigned === null) throw new InvalidArgumentException('На эту дату нет доступного гида. Выберите другую дату.');
        $prices = json_decode($tour['prices'] ?? '', true) ?: [];
        $price = (int)($prices[$sourceId] ?? $prices[-1] ?? 0) * ($group ? $seats : 1);
        if ($price < 0 || $price > 2147483647) throw new InvalidArgumentException('Проверьте количество человек и стоимость экскурсии.');
        if ($eventId === null) {
            $time = $tour['default_start_time'] ?: '10:00';
            $pdo->prepare("INSERT INTO events (tour_date, time, tour_id, guide, notes) VALUES (?, ?, ?, ?, 'Заявка с сайта')")->execute([$date, $time, $tourId, $assigned]);
            $eventId = (int)$pdo->lastInsertId();
        }
        $binding = participantSeatBinding($pdo, $seats);
        $pdo->prepare("INSERT INTO participants (event_id, client_name, {$binding['columns']}, price, phone, email, source, status, notes) VALUES (?, ?, {$binding['placeholders']}, ?, ?, ?, 'Сайт', 'Бронь', ?)")
            ->execute(array_merge([$eventId, $name], $binding['values'], [$price, $phone, $email, trim((string)($input['notes'] ?? ''))]));
        $participantId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO booking_requests (token, participant_id) VALUES (?, ?)')->execute([$token, $participantId]);
        $pdo->commit();
        return ['duplicate' => false, 'date' => $date, 'tour' => $tour['public_name'] ?: $tour['name'], 'name' => $name, 'phone' => $phone, 'seats' => $seats, 'price' => $price, 'guide' => $assigned];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
