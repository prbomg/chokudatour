<?php
require_once __DIR__ . '/participant_seats.php';

function validTourDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function homeFilters(array $input): array
{
    $filters = [];
    foreach (['date_from', 'date_to', 'tour_filter', 'guide_filter', 'sort', 'dir'] as $key) {
        if (isset($input[$key]) && is_scalar($input[$key]) && (string)$input[$key] !== '') $filters[$key] = (string)$input[$key];
    }
    foreach (['date_from', 'date_to'] as $key) {
        if (isset($filters[$key]) && !validTourDate($filters[$key])) throw new InvalidArgumentException('Укажите корректные даты фильтра.');
    }
    if (isset($filters['date_from'], $filters['date_to']) && $filters['date_from'] > $filters['date_to']) throw new InvalidArgumentException('Дата начала должна быть не позже даты окончания.');
    if (isset($filters['tour_filter']) && (!ctype_digit($filters['tour_filter']) || (int)$filters['tour_filter'] < 1)) throw new InvalidArgumentException('Выберите тур из списка.');
    if (!in_array($filters['sort'] ?? '', ['tour_date', 'tour_name', 'guide'], true)) unset($filters['sort']);
    if (!in_array($filters['dir'] ?? '', ['asc', 'desc'], true)) unset($filters['dir']);
    return $filters;
}

function homeUrl(array $filters = [], array $changes = []): string
{
    $query = http_build_query(homeFilters(array_replace($filters, $changes)), '', '&', PHP_QUERY_RFC3986);
    return 'index.php' . ($query === '' ? '' : '?' . $query);
}

// Only a local homepage with known filter keys can be used as a return target.
function homeReturnUrl($value): string
{
    if (!is_string($value) || !preg_match('~^index\.php(?:\?[^#\r\n]*)?$~D', $value)) return 'index.php';
    parse_str(parse_url($value, PHP_URL_QUERY) ?? '', $query);
    try { return homeUrl($query); } catch (InvalidArgumentException $e) { return 'index.php'; }
}

function homeFilterWhere(array $filters, array &$params, bool $past = false): string
{
    $sql = $past ? 'e.tour_date < CURDATE()' : 'e.tour_date >= ' . (isset($filters['date_from']) ? '?' : 'CURDATE()');
    if (!$past && isset($filters['date_from'])) $params[] = $filters['date_from'];
    if (isset($filters['date_to'])) { $sql .= ' AND e.tour_date <= ?'; $params[] = $filters['date_to']; }
    if ($past && isset($filters['date_from'])) { $sql .= ' AND e.tour_date >= ?'; $params[] = $filters['date_from']; }
    if (isset($filters['tour_filter'])) { $sql .= ' AND e.tour_id = ?'; $params[] = $filters['tour_filter']; }
    if (isset($filters['guide_filter'])) {
        if ($filters['guide_filter'] === 'Не назначен') {
            $sql .= " AND (e.guide IS NULL OR e.guide = '' OR e.guide LIKE 'Не назначен%')";
        } else { $sql .= ' AND e.guide = ?'; $params[] = $filters['guide_filter']; }
    }
    return $sql;
}

function homeParticipants(PDO $pdo, array $events): array
{
    if (!$events) return [];
    $ids = array_column($events, 'id');
    $stmt = $pdo->prepare("SELECT * FROM participants WHERE event_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND status != 'Отмена' ORDER BY id");
    $stmt->execute($ids);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[$row['event_id']][] = $row;
    return $result;
}

function homeClientsHtml(array $participants, int $eventId, string $returnUrl): string
{
    $context = '&return_to=' . rawurlencode($returnUrl);
    if (!$participants) return '<a class="btn-add-tourist" href="event.php?id=' . $eventId . htmlspecialchars($context, ENT_QUOTES) . '">+ Добавить</a>';
    $html = '';
    foreach ($participants as $p) {
        $url = 'client.php?phone=' . rawurlencode($p['phone'] ?? '') . $context;
        $html .= '<div class="tourist-chip"><a class="client-link" href="' . htmlspecialchars($url, ENT_QUOTES) . '">👤 ' . htmlspecialchars($p['client_name'] ?? '', ENT_QUOTES) . '</a> <span class="seats-count">' . participantSeats($p) . ' чел.</span></div>';
    }
    return $html;
}

function homeEventDetails(PDO $pdo, array $input): array
{
    $date = trim((string)($input['tour_date'] ?? ''));
    $time = trim((string)($input['time'] ?? ''));
    $tourId = (int)($input['tour_id'] ?? 0);
    $guide = trim((string)($input['guide'] ?? ''));
    if (!validTourDate($date)) throw new InvalidArgumentException('Укажите корректную дату экскурсии.');
    $stmt = $pdo->prepare('SELECT name, default_start_time FROM tours_catalog WHERE id = ?');
    $stmt->execute([$tourId]);
    $tour = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tour) throw new InvalidArgumentException('Выберите существующий тур.');
    if ($time === '') $time = $tour['default_start_time'] ?: '10:00';
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) throw new InvalidArgumentException('Укажите время в формате ЧЧ:ММ.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM guides WHERE name = ?');
    $stmt->execute([$guide]);
    if ($guide !== 'Не назначен' && !$stmt->fetchColumn()) throw new InvalidArgumentException('Выберите гида из списка.');
    return ['date' => $date, 'time' => $time, 'tour_id' => $tourId, 'tour_name' => $tour['name'], 'guide' => $guide, 'notes' => trim((string)($input['notes'] ?? ''))];
}
