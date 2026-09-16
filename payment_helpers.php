<?php
require_once __DIR__ . '/activity_log.php';

function ensurePaymentsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        participant_id INT NOT NULL,
        operation VARCHAR(20) NOT NULL DEFAULT 'payment',
        amount DECIMAL(10,2) NOT NULL,
        method VARCHAR(30) NOT NULL DEFAULT 'cash',
        paid_at DATE NOT NULL,
        note VARCHAR(500) DEFAULT '',
        created_by VARCHAR(100) DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        voided_at DATETIME DEFAULT NULL,
        voided_by VARCHAR(100) DEFAULT NULL
    )");
}

function paymentMethods(): array
{
    return ['cash'=>'Наличные','card'=>'Карта / перевод','online'=>'Онлайн-оплата','other'=>'Другое'];
}

function paymentInput(PDO $pdo, int $eventId, array $input): array
{
    $participantId = (int)($input['participant_id'] ?? 0);
    $operation = (string)($input['operation'] ?? 'payment');
    $rawAmount = str_replace(',', '.', trim((string)($input['amount'] ?? '')));
    $method = (string)($input['method'] ?? 'cash');
    $paidAt = trim((string)($input['paid_at'] ?? ''));
    $note = trim((string)($input['note'] ?? ''));
    $stmt = $pdo->prepare('SELECT client_name FROM participants WHERE id=? AND event_id=?');
    $stmt->execute([$participantId, $eventId]);
    $participantName = $stmt->fetchColumn();
    if ($participantName === false) throw new InvalidArgumentException('Бронирование не найдено.');
    if (!in_array($operation, ['payment','refund'], true)) throw new InvalidArgumentException('Выберите тип операции.');
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $rawAmount) || (float)$rawAmount <= 0 || (float)$rawAmount > 99999999.99) throw new InvalidArgumentException('Укажите положительную сумму, не более двух знаков после запятой.');
    if (!array_key_exists($method, paymentMethods())) throw new InvalidArgumentException('Выберите способ оплаты.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paidAt);
    if (!$date || $date->format('Y-m-d') !== $paidAt) throw new InvalidArgumentException('Укажите корректную дату платежа.');
    if (mb_strlen($note) > 500) throw new InvalidArgumentException('Комментарий не должен превышать 500 символов.');
    return ['participant_id'=>$participantId,'participant_name'=>(string)$participantName,'operation'=>$operation,'amount'=>number_format((float)$rawAmount,2,'.',''),'method'=>$method,'paid_at'=>$paidAt,'note'=>$note];
}

function addPayment(PDO $pdo, int $eventId, array $input): int
{
    $data = paymentInput($pdo, $eventId, $input);
    $actor = (string)($GLOBALS['current_user_name'] ?? $_SESSION['user_name'] ?? '');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO payments (event_id,participant_id,operation,amount,method,paid_at,note,created_by) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$eventId,$data['participant_id'],$data['operation'],$data['amount'],$data['method'],$data['paid_at'],$data['note'],$actor]);
        $id = (int)$pdo->lastInsertId();
        $verb = $data['operation'] === 'refund' ? 'Возврат' : 'Оплата';
        recordActivity($pdo, 'create', 'payment', $id, $verb . ' ' . $data['amount'] . ' ₽: ' . $data['participant_name']);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function voidPayment(PDO $pdo, int $eventId, int $paymentId): void
{
    $stmt = $pdo->prepare('SELECT p.*,pt.client_name FROM payments p JOIN participants pt ON pt.id=p.participant_id WHERE p.id=? AND p.event_id=?');
    $stmt->execute([$paymentId, $eventId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) throw new InvalidArgumentException('Платёж не найден.');
    if (!empty($payment['voided_at'])) throw new InvalidArgumentException('Операция уже аннулирована.');
    $actor = (string)($GLOBALS['current_user_name'] ?? $_SESSION['user_name'] ?? '');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE payments SET voided_at=CURRENT_TIMESTAMP,voided_by=? WHERE id=? AND event_id=? AND voided_at IS NULL')->execute([$actor,$paymentId,$eventId]);
        recordActivity($pdo, 'update', 'payment', $paymentId, 'Аннулирована операция ' . $payment['amount'] . ' ₽: ' . $payment['client_name']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
