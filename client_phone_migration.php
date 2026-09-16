<?php
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/client_workspace_helpers.php';

function normalizeStoredClientPhones(PDO $pdo): void
{
    $phones = $pdo->query("SELECT DISTINCT phone FROM participants WHERE COALESCE(phone,'')!=''")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($phones as $old) {
        $old = (string)$old; $normalized = normalizePhone($old);
        if ($normalized === '' || $normalized === $old) continue;
        $oldStmt = $pdo->prepare('SELECT tags,global_note FROM client_profiles WHERE phone=?'); $oldStmt->execute([$old]); $oldProfile = $oldStmt->fetch(PDO::FETCH_ASSOC);
        $newStmt = $pdo->prepare('SELECT tags,global_note FROM client_profiles WHERE phone=?'); $newStmt->execute([$normalized]); $newProfile = $newStmt->fetch(PDO::FETCH_ASSOC);
        $pdo->beginTransaction();
        try {
            if ($oldProfile) {
                $tags = array_values(array_unique(array_merge(clientTagsFromString($newProfile['tags'] ?? ''), clientTagsFromString($oldProfile['tags'] ?? ''))));
                $notes = trim(implode("\n\n", array_values(array_unique(array_filter([trim((string)($newProfile['global_note'] ?? '')), trim((string)($oldProfile['global_note'] ?? ''))])))));
                if ($newProfile) $pdo->prepare('UPDATE client_profiles SET tags=?,global_note=? WHERE phone=?')->execute([implode(',', $tags), $notes, $normalized]);
                else $pdo->prepare('INSERT INTO client_profiles (phone,tags,global_note) VALUES (?,?,?)')->execute([$normalized, implode(',', $tags), $notes]);
                $pdo->prepare('DELETE FROM client_profiles WHERE phone=?')->execute([$old]);
            }
            $pdo->prepare('UPDATE participants SET phone=? WHERE phone=?')->execute([$normalized, $old]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
}
