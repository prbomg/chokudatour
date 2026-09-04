<?php

// Preserve the event card's historical precedence. Conflicting legacy values
// must be checked by a person; reading a booking must never rewrite its count.
function participantSeats(array $participant): int
{
    return (int)($participant['places'] ?? $participant['seats'] ?? 1);
}

function participantSeatsConflict(array $participant): bool
{
    return isset($participant['places'], $participant['seats'])
        && (int)$participant['places'] !== (int)$participant['seats'];
}

function participantSeatColumns(PDO $pdo): array
{
    $columns = $pdo->query('SHOW COLUMNS FROM participants')->fetchAll(PDO::FETCH_COLUMN);
    $seatColumns = array_values(array_intersect(['places', 'seats'], $columns));
    if (!$seatColumns) {
        throw new RuntimeException('В таблице participants нет поля количества мест.');
    }
    return $seatColumns;
}

// Use the same fallback in aggregates as in individual bookings. Supports
// installations with only the original seats column, without schema changes.
function participantSeatsSql(PDO $pdo, string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $alias)) {
        throw new InvalidArgumentException('Invalid table alias');
    }
    $prefix = $alias === '' ? '' : $alias . '.';
    $columns = array_map(fn($column) => $prefix . $column, participantSeatColumns($pdo));
    return 'COALESCE(' . implode(', ', $columns) . ', 1)';
}

// Every booking form writes the same submitted count to all existing count
// columns in a single INSERT/UPDATE, so old integrations remain compatible.
function participantSeatBinding(PDO $pdo, int $seats): array
{
    $columns = participantSeatColumns($pdo);
    return [
        'columns' => implode(', ', $columns),
        'placeholders' => implode(', ', array_fill(0, count($columns), '?')),
        'assignments' => implode(', ', array_map(fn($column) => $column . ' = ?', $columns)),
        'values' => array_fill(0, count($columns), max(1, $seats)),
    ];
}
