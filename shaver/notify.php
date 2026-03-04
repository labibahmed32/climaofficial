<?php
/**
 * Clima Tracker — Notification Endpoint
 * Sends sale notifications via Email (PHP mail) and Telegram Bot API
 * POST JSON: {type, email_to, email_from, email_subject, email_body, tg_token, tg_chat_ids, tg_message}
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$emailSent = 0;
$tgSent = 0;
$errors = [];

// --- Email Notifications ---
$emailTo = trim($input['email_to'] ?? '');
if ($emailTo) {
    $from = trim($input['email_from'] ?? 'noreply@climaofficial.com');
    $subject = $input['email_subject'] ?? 'New Sale Notification';
    $body = $input['email_body'] ?? '';

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Clima Tracker <" . $from . ">\r\n";
    $headers .= "Reply-To: " . $from . "\r\n";
    $headers .= "X-Mailer: Clima-Tracker/1.0\r\n";

    $recipients = array_filter(array_map('trim', explode(',', $emailTo)));
    foreach ($recipients as $to) {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email: $to";
            continue;
        }
        if (@mail($to, $subject, $body, $headers)) {
            $emailSent++;
        } else {
            $errors[] = "mail() failed for: $to";
        }
    }
}

// --- Telegram Notifications ---
$tgToken = trim($input['tg_token'] ?? '');
$tgChatIds = $input['tg_chat_ids'] ?? [];
$tgMessage = $input['tg_message'] ?? '';

if ($tgToken && !empty($tgChatIds) && $tgMessage) {
    foreach ($tgChatIds as $chatId) {
        $chatId = trim($chatId);
        if (!$chatId) continue;
        $url = 'https://api.telegram.org/bot' . $tgToken . '/sendMessage';
        $postData = http_build_query([
            'chat_id' => $chatId,
            'text' => $tgMessage,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true
        ]);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postData,
                'timeout' => 10
            ]
        ]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result) {
            $resp = json_decode($result, true);
            if (!empty($resp['ok'])) {
                $tgSent++;
            } else {
                $errors[] = "TG chat $chatId: " . ($resp['description'] ?? 'Unknown error');
            }
        } else {
            $errors[] = "TG chat $chatId: Connection failed";
        }
    }
}

echo json_encode([
    'success' => true,
    'email_sent' => $emailSent,
    'tg_sent' => $tgSent,
    'errors' => $errors
]);
