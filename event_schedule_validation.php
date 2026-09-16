<?php

/**
 * Returns non-blocking warnings for a manually assigned departure.
 * The caller decides whether an administrator explicitly overrides them.
 */
function eventScheduleWarnings(PDO $pdo, string $date, string $time, int $tourId, string $guide, ?int $excludeEventId = null): array
{
    $warnings = [];

    $workingDays = (string)$pdo->query("SELECT setting_value FROM global_settings WHERE setting_key='working_days'")->fetchColumn();
    $workingDayList = $workingDays === '' ? [] : explode(',', $workingDays);
    $weekday = (string)(new DateTimeImmutable($date))->format('N');

    $ruleStmt = $pdo->prepare('SELECT action_type, tours, reason FROM blocked_dates WHERE block_date=? ORDER BY id DESC LIMIT 1');
    $ruleStmt->execute([$date]);
    $rule = $ruleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $ruleApplies = $rule && (($rule['tours'] ?? 'all') === 'all' || in_array((string)$tourId, array_filter(explode(',', (string)$rule['tours'])), true));
    $openOverride = $ruleApplies && ($rule['action_type'] ?? '') === 'open';

    if (!in_array($weekday, $workingDayList, true) && !$openOverride) {
        $warnings[] = 'Дата не входит в рабочий график.';
    }
    if ($ruleApplies && ($rule['action_type'] ?? '') === 'close') {
        $message = 'На выбранную дату бронирование закрыто.';
        if (trim((string)($rule['reason'] ?? '')) !== '') $message .= ' Причина: ' . trim((string)$rule['reason']);
        $warnings[] = $message;
    }

    if ($guide === '' || $guide === 'Не назначен') return $warnings;

    $guideStmt = $pdo->prepare('SELECT allowed_tours FROM guides WHERE name=?');
    $guideStmt->execute([$guide]);
    $allowedTours = $guideStmt->fetchColumn();
    if ($allowedTours !== false && $allowedTours !== 'all' && !in_array((string)$tourId, array_filter(explode(',', (string)$allowedTours)), true)) {
        $warnings[] = 'Гид «' . $guide . '» не назначен на этот маршрут.';
    }

    $offStmt = $pdo->prepare('SELECT reason FROM guide_timeoffs WHERE guide_name=? AND date_off=? ORDER BY id DESC LIMIT 1');
    $offStmt->execute([$guide, $date]);
    $timeoffReason = $offStmt->fetchColumn();
    if ($timeoffReason !== false) {
        $message = 'У гида «' . $guide . '» в этот день отгул.';
        if (trim((string)$timeoffReason) !== '') $message .= ' Причина: ' . trim((string)$timeoffReason);
        $warnings[] = $message;
    }

    $conflictSql = "SELECT e.id, t.name FROM events e JOIN tours_catalog t ON t.id=e.tour_id WHERE e.guide=? AND e.tour_date=? AND e.time=?";
    $params = [$guide, $date, $time];
    if ($excludeEventId) { $conflictSql .= ' AND e.id<>?'; $params[] = $excludeEventId; }
    $conflictSql .= ' ORDER BY e.id LIMIT 1';
    $conflictStmt = $pdo->prepare($conflictSql);
    $conflictStmt->execute($params);
    if ($conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC)) {
        $warnings[] = 'У гида «' . $guide . '» уже есть выезд «' . $conflict['name'] . '» в ' . substr($time, 0, 5) . '.';
    }

    return array_values(array_unique($warnings));
}

function eventScheduleOverrideRequested(array $input): bool
{
    return isset($input['schedule_override']) && hash_equals('1', (string)$input['schedule_override']);
}

