<?php

function clientTagsFromString($value): array
{
    return array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$value)))));
}

function validateClientTag($value): string
{
    $tag = trim((string)$value);
    if ($tag === '' || mb_strlen($tag) > 50 || str_contains($tag, ',')) throw new InvalidArgumentException('Название тега должно содержать от 1 до 50 символов без запятых.');
    return $tag;
}

function clientTagStyle(string $tag): string
{
    $hue = abs(crc32($tag)) % 360;
    return "--tag-bg:hsl({$hue},65%,94%);--tag-color:hsl({$hue},55%,29%)";
}

function clientMoney($amount): string
{
    return number_format((float)$amount, 0, ',', ' ') . ' ₽';
}

function clientCountPhrase(int $count, array $forms): string
{
    $mod100 = $count % 100; $mod10 = $count % 10;
    $form = ($mod100 >= 11 && $mod100 <= 14) ? $forms[2] : ($mod10 === 1 ? $forms[0] : (($mod10 >= 2 && $mod10 <= 4) ? $forms[1] : $forms[2]));
    return $count . ' ' . $form;
}

function clientListUrl(array $input): string
{
    $filters = [];
    $search = trim((string)($input['search'] ?? ''));
    $tag = trim((string)($input['tag'] ?? ''));
    $tourId = (int)($input['tour_id'] ?? 0);
    if ($search !== '') $filters['search'] = $search;
    if ($tourId > 0) $filters['tour_id'] = $tourId;
    if ($tag !== '') $filters['tag'] = $tag;
    $query = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
    return 'clients.php' . ($query === '' ? '' : '?' . $query);
}

function clientPotentialDuplicates(array $clients, int $limit = 50): array
{
    $result = [];
    $count = count($clients);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $left = $clients[$i]; $right = $clients[$j];
            $leftPhone = normalizePhone($left['phone'] ?? '');
            $rightPhone = normalizePhone($right['phone'] ?? '');
            $leftEmail = mb_strtolower(trim((string)($left['email'] ?? '')));
            $rightEmail = mb_strtolower(trim((string)($right['email'] ?? '')));
            $reasons = []; $score = 0;

            if ($leftPhone !== '' && $leftPhone === $rightPhone && (string)$left['phone'] !== (string)$right['phone']) {
                $reasons[] = 'Один номер в разных форматах'; $score = max($score, 100);
            }
            if ($leftEmail !== '' && $leftEmail === $rightEmail) {
                $reasons[] = 'Одинаковый e-mail'; $score = max($score, 90);
            }
            if (!$reasons) continue;
            $leftCanonical = (string)$left['phone'] === $leftPhone;
            $rightCanonical = (string)$right['phone'] === $rightPhone;
            if ((!$leftCanonical && $rightCanonical) || ($leftCanonical === $rightCanonical && (int)($right['active_trips'] ?? 0) > (int)($left['active_trips'] ?? 0))) {
                [$left, $right] = [$right, $left];
            }
            $result[] = ['primary'=>$left, 'candidate'=>$right, 'reasons'=>$reasons, 'score'=>$score];
        }
    }
    usort($result, fn($a,$b) => ($b['score'] <=> $a['score']) ?: strcmp((string)$a['primary']['client_name'], (string)$b['primary']['client_name']));
    return array_slice($result, 0, $limit);
}

function mergeClientProfiles(PDO $pdo, string $targetPhone, string $sourcePhone): int
{
    $targetPhone = trim($targetPhone);
    $sourcePhone = trim($sourcePhone);
    if (normalizePhone($targetPhone) === '' || normalizePhone($sourcePhone) === '' || $targetPhone === $sourcePhone) throw new InvalidArgumentException('Выберите другую карточку клиента.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE phone=?'); $stmt->execute([$targetPhone]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Основная карточка клиента не найдена.');
    $stmt->execute([$sourcePhone]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Карточка-дубль не найдена.');

    $profileStmt = $pdo->prepare('SELECT * FROM client_profiles WHERE phone=?');
    $profileStmt->execute([$targetPhone]); $target = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: ['tags'=>'','global_note'=>''];
    $profileStmt->execute([$sourcePhone]); $source = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: ['tags'=>'','global_note'=>''];
    $tags = array_values(array_unique(array_merge(clientTagsFromString($target['tags']), clientTagsFromString($source['tags']))));
    $targetNote = trim((string)$target['global_note']); $sourceNote = trim((string)$source['global_note']);
    $note = $targetNote;
    if ($sourceNote !== '' && $sourceNote !== $targetNote) $note .= ($note === '' ? '' : "\n\n") . 'Из объединённой карточки ' . $sourcePhone . ":\n" . $sourceNote;
    if (mb_strlen($note) > 5000) throw new InvalidArgumentException('Общие заметки после объединения превышают 5000 символов. Сократите одну из заметок.');

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE participants SET phone=? WHERE phone=?');
        $update->execute([$targetPhone, $sourcePhone]);
        $moved = $update->rowCount();
        $exists = $pdo->prepare('SELECT COUNT(*) FROM client_profiles WHERE phone=?'); $exists->execute([$targetPhone]);
        if ($exists->fetchColumn()) $pdo->prepare('UPDATE client_profiles SET tags=?,global_note=? WHERE phone=?')->execute([implode(',', $tags),$note,$targetPhone]);
        else $pdo->prepare('INSERT INTO client_profiles (phone,tags,global_note) VALUES (?,?,?)')->execute([$targetPhone,implode(',', $tags),$note]);
        $pdo->prepare('DELETE FROM client_profiles WHERE phone=?')->execute([$sourcePhone]);
        recordActivity($pdo, 'update', 'client', null, 'Объединены карточки ' . $sourcePhone . ' → ' . $targetPhone);
        $pdo->commit();
        return $moved;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
