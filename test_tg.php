<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// === ТВОИ ДАННЫЕ ===
$botToken = '8823328971:AAGNqAip0lVXLsDHzwiPq3hckNyE3Icf8Aw'; 
$chatId = '31983488';
// ===================

echo "<h3>Тестирование отправки в Telegram</h3>";

$url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
$data = [
    'chat_id' => $chatId,
    'text' => '🚀 Тестовое сообщение из CRM! Принудительный IPv4 сработал.'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// === ВОТ ЭТА СПАСИТЕЛЬНАЯ СТРОЧКА ===
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 

$result = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "<b style='color:red;'>Ошибка сервера (cURL):</b> " . $error;
    echo "<br><br><i>Если ошибка не исчезла, значит Timeweb полностью заблокировал исходящие запросы к Telegram на твоем тарифе. В этом случае нужно написать в техподдержку Timeweb фразу: «Здравствуйте! Разблокируйте, пожалуйста, исходящие запросы к api.telegram.org для моего сайта».</i>";
} else {
    echo "<b>Ответ от серверов Telegram:</b><br>";
    echo "<pre style='background:#f4f4f4; padding:10px; border-radius:5px;'>" . print_r(json_decode($result, true), true) . "</pre>";
    
    $json = json_decode($result, true);
    if (isset($json['ok']) && $json['ok'] == 1) {
        echo "<h3 style='color:green;'>УРА! Сообщение успешно отправлено!</h3>";
    } else {
        echo "<h3 style='color:red;'>ОШИБКА ОТПРАВКИ!</h3>";
    }
}
?>