<?php
function rememberCookieOptions(int $expires): array
{
    return ['expires'=>$expires, 'path'=>'/', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly'=>true, 'samesite'=>'Lax'];
}

function issueRememberToken(PDO $pdo, int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE users SET remember_token=? WHERE id=?')->execute([hash('sha256', $token), $userId]);
    setcookie('remember_token', $token, rememberCookieOptions(time() + 30 * 86400));
}

function clearRememberToken(PDO $pdo): void
{
    $token = (string)($_COOKIE['remember_token'] ?? '');
    if ($token !== '') $pdo->prepare('UPDATE users SET remember_token=NULL WHERE remember_token=?')->execute([hash('sha256', $token)]);
    setcookie('remember_token', '', rememberCookieOptions(time() - 3600));
}
