<?php

function recordActivity(PDO $pdo, string $action, string $entityType, ?int $entityId, string $summary, ?array $snapshot = null): int
{
    $stmt = $pdo->prepare('INSERT INTO activity_log (user_id,user_name,action,entity_type,entity_id,summary,snapshot) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        isset($GLOBALS['current_user_id']) ? (int)$GLOBALS['current_user_id'] : ($_SESSION['user_id'] ?? null),
        (string)($GLOBALS['current_user_name'] ?? $_SESSION['user_name'] ?? ''),
        $action,
        $entityType,
        $entityId,
        mb_substr($summary, 0, 255),
        $snapshot === null ? null : json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return (int)$pdo->lastInsertId();
}

function activityRow(PDO $pdo, string $table, int $id): ?array
{
    if (!in_array($table, ['events', 'participants', 'expenses', 'payments'], true)) throw new InvalidArgumentException('Недопустимый тип записи.');
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function insertSnapshotRow(PDO $pdo, string $table, array $row): void
{
    if (!in_array($table, ['events', 'participants', 'expenses', 'payments'], true) || !$row) throw new InvalidArgumentException('Некорректный снимок записи.');
    $columns = array_keys($row);
    foreach ($columns as $column) if (!preg_match('/^[a-z_]+$/D', $column)) throw new InvalidArgumentException('Некорректный снимок записи.');
    $quoted = implode(',', array_map(fn($column) => "`{$column}`", $columns));
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $pdo->prepare("INSERT INTO {$table} ({$quoted}) VALUES ({$placeholders})")->execute(array_values($row));
}

function restoreActivity(PDO $pdo, int $activityId): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM activity_log WHERE id=? AND action='delete' FOR UPDATE");
        $stmt->execute([$activityId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) throw new InvalidArgumentException('Удалённая запись не найдена.');
        if (!empty($log['restored_at'])) throw new InvalidArgumentException('Эта запись уже восстановлена.');
        $snapshot = json_decode((string)$log['snapshot'], true);
        if (!is_array($snapshot)) throw new InvalidArgumentException('Снимок записи повреждён.');

        $type = (string)$log['entity_type'];
        if ($type === 'event') {
            $event = $snapshot['event'] ?? [];
            if (activityRow($pdo, 'events', (int)($event['id'] ?? 0))) throw new InvalidArgumentException('Идентификатор выезда уже занят.');
            insertSnapshotRow($pdo, 'events', $event);
            foreach (($snapshot['participants'] ?? []) as $row) insertSnapshotRow($pdo, 'participants', $row);
            foreach (($snapshot['expenses'] ?? []) as $row) insertSnapshotRow($pdo, 'expenses', $row);
            foreach (($snapshot['payments'] ?? []) as $row) insertSnapshotRow($pdo, 'payments', $row);
        } elseif ($type === 'participant') {
            $row = $snapshot['participant'] ?? [];
            if (!activityRow($pdo, 'events', (int)($row['event_id'] ?? 0))) throw new InvalidArgumentException('Сначала восстановите связанный выезд.');
            if (activityRow($pdo, 'participants', (int)($row['id'] ?? 0))) throw new InvalidArgumentException('Идентификатор бронирования уже занят.');
            insertSnapshotRow($pdo, 'participants', $row);
            foreach (($snapshot['payments'] ?? []) as $payment) insertSnapshotRow($pdo, 'payments', $payment);
        } elseif ($type === 'expense') {
            $row = $snapshot['expense'] ?? [];
            if (!activityRow($pdo, 'events', (int)($row['event_id'] ?? 0))) throw new InvalidArgumentException('Сначала восстановите связанный выезд.');
            if (activityRow($pdo, 'expenses', (int)($row['id'] ?? 0))) throw new InvalidArgumentException('Идентификатор расхода уже занят.');
            insertSnapshotRow($pdo, 'expenses', $row);
        } elseif ($type === 'payment') {
            $row = $snapshot['payment'] ?? [];
            if (!activityRow($pdo, 'events', (int)($row['event_id'] ?? 0)) || !activityRow($pdo, 'participants', (int)($row['participant_id'] ?? 0))) throw new InvalidArgumentException('Сначала восстановите связанный выезд и бронирование.');
            if (activityRow($pdo, 'payments', (int)($row['id'] ?? 0))) throw new InvalidArgumentException('Идентификатор платежа уже занят.');
            insertSnapshotRow($pdo, 'payments', $row);
        } else {
            throw new InvalidArgumentException('Этот тип записи нельзя восстановить.');
        }

        $actor = (string)($GLOBALS['current_user_name'] ?? $_SESSION['user_name'] ?? '');
        $pdo->prepare('UPDATE activity_log SET restored_at=CURRENT_TIMESTAMP, restored_by=? WHERE id=?')->execute([$actor, $activityId]);
        $pdo->commit();
        recordActivity($pdo, 'restore', $type, isset($log['entity_id']) ? (int)$log['entity_id'] : null, 'Восстановлено: ' . $log['summary']);
        return $type;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
