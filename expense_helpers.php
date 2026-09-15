<?php
require_once __DIR__ . '/file_storage.php';

function expenseInput(PDO $pdo, array $input): array
{
    $raw = str_replace(',', '.', trim((string)($input['amount'] ?? '')));
    $category = trim((string)($input['category'] ?? 'Прочее'));
    $description = trim((string)($input['description'] ?? ''));
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $raw) || (float)$raw <= 0 || (float)$raw > 99999999.99) throw new InvalidArgumentException('Укажите положительную сумму расхода, не более двух знаков после запятой.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM expense_categories WHERE name=?'); $stmt->execute([$category]);
    if ($category !== 'Прочее' && !$stmt->fetchColumn()) throw new InvalidArgumentException('Выберите категорию расхода из списка.');
    if (mb_strlen($description) > 2000) throw new InvalidArgumentException('Описание расхода не должно превышать 2000 символов.');
    return ['amount'=>number_format((float)$raw, 2, '.', ''), 'category'=>$category, 'description'=>$description];
}

function storeReceipt(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 8 * 1024 * 1024) throw new InvalidArgumentException('Не удалось загрузить чек или файл больше 8 МБ.');
    $image = @getimagesize((string)$file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
    $mime = (string)($image['mime'] ?? '');
    if (!isset($extensions[$mime])) throw new InvalidArgumentException('Чек должен быть изображением JPG, PNG или WebP.');
    if (($image[0] ?? 0) * ($image[1] ?? 0) > 40000000) throw new InvalidArgumentException('Разрешение изображения чека слишком большое.');
    if (!is_dir(__DIR__ . '/uploads') && !mkdir(__DIR__ . '/uploads', 0755, true)) throw new RuntimeException('Не удалось подготовить папку для чеков.');
    $path = 'uploads/rec_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string)$file['tmp_name'], __DIR__ . '/' . $path)) throw new RuntimeException('Не удалось сохранить чек.');
    return $path;
}

function addExpense(PDO $pdo, int $eventId, array $input, array $files): void
{
    $data = expenseInput($pdo, $input);
    $receipt = storeReceipt($files['receipt'] ?? []);
    try {
        $pdo->prepare('INSERT INTO expenses (event_id, amount, category, description, receipt_path) VALUES (?, ?, ?, ?, ?)')->execute([$eventId, $data['amount'], $data['category'], $data['description'], $receipt]);
    } catch (Throwable $e) { deleteUploadFile($receipt); throw $e; }
}

function deleteExpense(PDO $pdo, int $eventId, int $expenseId): void
{
    $stmt = $pdo->prepare('SELECT receipt_path FROM expenses WHERE id=? AND event_id=?'); $stmt->execute([$expenseId, $eventId]);
    $path = (string)($stmt->fetchColumn() ?: '');
    $pdo->prepare('DELETE FROM expenses WHERE id=? AND event_id=?')->execute([$expenseId, $eventId]);
    deleteUploadFile($path);
}

function deleteEventWithFiles(PDO $pdo, int $eventId): void
{
    $stmt = $pdo->prepare('SELECT receipt_path FROM expenses WHERE event_id=?'); $stmt->execute([$eventId]);
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM expenses WHERE event_id=?')->execute([$eventId]);
        $pdo->prepare('DELETE FROM participants WHERE event_id=?')->execute([$eventId]);
        $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$eventId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    foreach ($paths as $path) deleteUploadFile((string)$path);
}
