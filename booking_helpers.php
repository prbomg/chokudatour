<?php

function bookingStatuses(): array
{
    return ['Бронь', 'Предоплата', 'Оплачено', 'Оплата на месте', 'Отмена'];
}

function normalizePhone($value): string
{
    $digits = preg_replace('/\D+/', '', trim((string)$value));
    if (strlen($digits) === 11 && $digits[0] === '8') $digits = '7' . substr($digits, 1);
    if (strlen($digits) === 10) $digits = '7' . $digits;
    return $digits;
}

function bookingParticipantInput(PDO $pdo, array $input, int $minimumPhoneDigits = 5): array
{
    $name = trim((string)($input['client_name'] ?? ''));
    $phone = normalizePhone($input['phone'] ?? '');
    $email = trim((string)($input['email'] ?? ''));
    $seatsRaw = trim((string)($input['seats'] ?? ''));
    $priceRaw = trim((string)($input['price'] ?? '0'));
    $source = trim((string)($input['source'] ?? 'CRM'));
    $status = trim((string)($input['status'] ?? 'Бронь'));
    $notes = trim((string)($input['notes'] ?? ''));
    if ($name === '' || mb_strlen($name) > 255) throw new InvalidArgumentException('Укажите имя туриста длиной до 255 символов.');
    if (strlen($phone) < $minimumPhoneDigits || strlen($phone) > 20) throw new InvalidArgumentException('Укажите корректный телефон.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Укажите корректный e-mail.');
    if (!ctype_digit($seatsRaw) || (int)$seatsRaw < 1 || (int)$seatsRaw > 999) throw new InvalidArgumentException('Количество мест должно быть целым числом от 1 до 999.');
    if (!preg_match('/^\d+$/D', $priceRaw) || (int)$priceRaw > 999999999) throw new InvalidArgumentException('Сумма бронирования должна быть целым неотрицательным числом.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM booking_sources WHERE name=?');
    $stmt->execute([$source]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Выберите источник из списка.');
    if (!in_array($status, bookingStatuses(), true)) throw new InvalidArgumentException('Выберите статус из списка.');
    if (mb_strlen($notes) > 5000) throw new InvalidArgumentException('Примечание не должно превышать 5000 символов.');
    return ['name'=>$name, 'phone'=>$phone, 'email'=>$email, 'seats'=>(int)$seatsRaw, 'price'=>(int)$priceRaw, 'source'=>$source, 'status'=>$status, 'notes'=>$notes];
}

function eventStatuses(): array { return bookingStatuses(); }
function eventParticipantInput(PDO $pdo, array $input): array { return bookingParticipantInput($pdo, $input); }
