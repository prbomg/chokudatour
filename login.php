<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Подключаем базу данных
if (file_exists('db.php')) {
    require_once 'db.php';
}

// Если пользователь уже авторизован, перенаправляем на главную
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    if (!empty($email) && !empty($password)) {
        if (isset($pdo)) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];

                if ($remember) {
                    // Сохраняем куки на 30 дней
                    setcookie('crm_remember', $user['id'], time() + (86400 * 30), "/");
                }

                header("Location: index.php");
                exit;
            } else {
                $error = 'Неверный E-mail или пароль';
            }
        } else {
            $error = 'Ошибка подключения к базе данных';
        }
    } else {
        $error = 'Заполните все поля';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Вход в CRM - Авторские туры</title>
    <style>
        *, *:before, *:after {
            box-sizing: border-box;
        }

        :root { 
            --primary: #4F46E5; 
            --primary-hover: #4338CA; 
            --bg: #F9FAFB; 
            --card-bg: #FFFFFF; 
            --border: #E5E7EB; 
            --text-main: #111827; 
            --text-muted: #6B7280; 
        }

        body { 
            font-family: 'Segoe UI', Roboto, -apple-system, sans-serif; 
            background: var(--bg); 
            color: var(--text-main); 
            margin: 0; 
            padding: 20px; 
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-wrapper {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }

        .login-card { 
            background: var(--card-bg); 
            padding: 35px 30px; 
            border-radius: 16px; 
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01); 
            border: 1px solid var(--border);
            text-align: center;
        }

        .logo-icon {
            width: 48px;
            height: 48px;
            background: #EEF2FF;
            color: var(--primary);
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }

        .login-card h2 { 
            margin: 0 0 8px 0; 
            font-size: 22px;
            font-weight: 700; 
            color: var(--text-main); 
        }

        .login-card p.subtitle {
            margin: 0 0 25px 0;
            font-size: 14px;
            color: var(--text-muted);
        }

        .error-msg { 
            color: #EF4444; 
            background: #FEF2F2; 
            border: 1px solid #FCA5A5;
            padding: 10px 14px; 
            border-radius: 8px; 
            margin-bottom: 20px; 
            font-size: 13px; 
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-group {
            text-align: left;
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 6px;
        }

        .form-group input[type="email"],
        .form-group input[type="password"] { 
            width: 100%; 
            padding: 11px 14px; 
            font-size: 14px; 
            border: 1px solid #D1D5DB; 
            border-radius: 8px; 
            outline: none; 
            font-family: inherit;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
        }

        .form-group input:focus { 
            border-color: var(--primary); 
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12); 
        }

        /* Выравнивание строки "Запомнить меня" и "Забыли пароль" */
        .options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            font-size: 13px;
            gap: 10px;
            flex-wrap: nowrap;
        }

        .remember-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
            color: var(--text-main);
            font-weight: 500;
            white-space: nowrap;
        }

        .remember-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
            margin: 0;
            border-radius: 4px;
        }

        .forgot-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            white-space: nowrap;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .btn-submit { 
            width: 100%; 
            padding: 12px; 
            font-size: 15px; 
            font-weight: 600;
            background-color: var(--primary); 
            color: white; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            transition: background-color 0.2s, transform 0.1s; 
        }

        .btn-submit:hover { 
            background-color: var(--primary-hover); 
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        /* Адаптивность под мобильные телефоны */
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            .login-card {
                padding: 25px 20px;
                border-radius: 12px;
            }
            .login-card h2 {
                font-size: 20px;
            }
            .options-row {
                font-size: 12px;
            }
            .forgot-link {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <div class="logo-icon">
            <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
        </div>
        
        <h2>Вход в CRM</h2>
        <p class="subtitle">Управление авторскими турами</p>

        <?php if (!empty($error)): ?>
            <div class="error-msg">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">E-mail (Логин)</label>
                <input type="email" id="email" name="email" placeholder="admin@example.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autofocus>
            </div>

            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password" id="password" name="password" placeholder="••••••••" required>
            </div>

            <div class="options-row">
                <label class="remember-label">
                    <input type="checkbox" name="remember" id="remember" <?= isset($_POST['remember']) ? 'checked' : '' ?>>
                    <span>Запомнить меня</span>
                </label>
                
                <a href="javascript:void(0);" onclick="alert('Для восстановления пароля обратитесь к главному администратору.')" class="forgot-link">Забыли пароль?</a>
            </div>

            <button type="submit" class="btn-submit">Войти в систему</button>
        </form>
    </div>
</div>

</body>
</html>