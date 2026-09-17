<?php

function selectedGuide(PDO $pdo, array $input): array
{
    $rawId = $input['guide_id'] ?? null;
    if ($rawId !== null && (string)$rawId !== '' && (int)$rawId > 0) {
        $stmt = $pdo->prepare('SELECT id,name FROM guides WHERE id=?');
        $stmt->execute([(int)$rawId]);
        if ($guide = $stmt->fetch(PDO::FETCH_ASSOC)) return ['id'=>(int)$guide['id'],'name'=>(string)$guide['name']];
        throw new InvalidArgumentException('Выберите гида из списка.');
    }
    $legacyName = trim((string)($input['guide'] ?? ''));
    if ($legacyName === '' || $legacyName === 'Не назначен') return ['id'=>null,'name'=>'Не назначен'];
    $stmt = $pdo->prepare('SELECT id,name FROM guides WHERE name=? ORDER BY id LIMIT 1');
    $stmt->execute([$legacyName]);
    if ($guide = $stmt->fetch(PDO::FETCH_ASSOC)) return ['id'=>(int)$guide['id'],'name'=>(string)$guide['name']];
    throw new InvalidArgumentException('Выберите гида из списка.');
}

function eventGuideName(array $event): string
{
    return (string)($event['guide_name'] ?? $event['guide'] ?? '');
}

