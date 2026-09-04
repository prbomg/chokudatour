<?php
function sendTelegramMessage($message) {
    global $pdo; 
    
    // Получаем актуальные настройки из базы данных
    $botToken = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'tg_bot'")->fetchColumn();
    $chatId = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'tg_chat'")->fetchColumn();

    if (empty($botToken) || empty($chatId)) return false;

    $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML' 
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Обязательный фикс для хостинга
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return $result;
}
?>