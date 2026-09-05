<?php
function formToken(): string
{
    if (empty($_SESSION['form_token'])) $_SESSION['form_token'] = bin2hex(random_bytes(32));
    return $_SESSION['form_token'];
}

function formTokenInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(formToken(), ENT_QUOTES) . '">';
}

function requireFormToken(): void
{
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals(formToken(), $_POST['csrf_token'])) {
        http_response_code(403);
        if (isset($_POST['ajax_add_event']) || isset($_POST['update_event'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Сессия формы устарела. Обновите страницу.']);
        } else echo 'Сессия формы устарела. Обновите страницу и повторите действие.';
        exit;
    }
}

function requireEventAccess(PDO $pdo, int $eventId, string $role, string $name): void
{
    $stmt = $pdo->prepare('SELECT guide FROM events WHERE id = ?');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event || ($role !== 'admin' && ($event['guide'] ?? '') !== $name)) {
        http_response_code(403);
        exit('Доступ к экскурсии запрещён.');
    }
}

function deleteControl(string $action, string $field, int $id, string $message): string
{
    return '<form method="POST" action="' . htmlspecialchars($action, ENT_QUOTES) . '" style="display:inline-flex; margin:0;" onsubmit="return confirm(' . htmlspecialchars(json_encode($message, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . ')">' . formTokenInput()
        . '<button type="submit" name="' . htmlspecialchars($field, ENT_QUOTES) . '" value="' . $id . '" class="btn-icon btn-del" title="Удалить"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg></button></form>';
}
