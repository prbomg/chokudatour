<?php
// Функция для отправки сообщений в Telegram
function sendTelegramMessage($message) {
    // ВСТАВЬ СЮДА СВОИ ДАННЫЕ ИЗ ШАГА 1 (внутри кавычек!):
    $botToken = '8823328971:AAGNqAip0lVXLsDHzwiPq3hckNyE3Icf8Aw'; 
    $chatId = '31983488';

    // Защита от случайной отправки, если ключи не заданы
    if (empty($botToken) || $botToken === '8823328971:AAGNqAip0lVXLsDHzwiPq3hckNyE3Icf8Aw') return false;

    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML' 
    ];

    // Надежная отправка через cURL (идеально для хостингов)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Ограничение по времени 3 секунды
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Отключаем жесткую проверку SSL
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}
?>