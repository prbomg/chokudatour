<?php

function uploadPathIsSafe(string $path): bool
{
    return (bool)preg_match('~^uploads/[A-Za-z0-9._-]+$~D', $path);
}

function deleteUploadFile(string $path): void
{
    if ($path !== '' && uploadPathIsSafe($path) && is_file(__DIR__ . '/' . $path)) @unlink(__DIR__ . '/' . $path);
}

function copyUploadFile(string $path, string $prefix): string
{
    if ($path === '' || !uploadPathIsSafe($path) || !is_file(__DIR__ . '/' . $path)) return '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{2,5}$/D', $ext)) return '';
    if (!is_dir(__DIR__ . '/uploads') && !mkdir(__DIR__ . '/uploads', 0755, true)) throw new RuntimeException('Не удалось подготовить папку загрузок.');
    $target = 'uploads/' . preg_replace('/[^a-z0-9_-]+/i', '_', $prefix) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!copy(__DIR__ . '/' . $path, __DIR__ . '/' . $target)) throw new RuntimeException('Не удалось скопировать изображение.');
    return $target;
}

function duplicateTourImage(string $path, string $prefix): string
{
    if ($path === '') return '';
    if (!uploadPathIsSafe($path)) return $path;
    return copyUploadFile($path, $prefix);
}

function deleteTourImageIfUnused(PDO $pdo, string $path): void
{
    if ($path === '') return;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tours_catalog WHERE main_image=?'); $stmt->execute([$path]);
    if ($stmt->fetchColumn()) return;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM tour_modules WHERE image_path=?'); $stmt->execute([$path]);
    if ($stmt->fetchColumn()) return;
    foreach ($pdo->query("SELECT images FROM tours_catalog WHERE COALESCE(images,'')!=''")->fetchAll(PDO::FETCH_COLUMN) as $json) {
        if (in_array($path, json_decode((string)$json, true) ?: [], true)) return;
    }
    deleteUploadFile($path);
}
