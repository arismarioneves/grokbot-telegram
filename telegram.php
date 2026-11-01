<?php

declare(strict_types=1);

/**
 * Telegram -> Grok Bot Webhook Bridge.
 *
 * Workflow:
 * 1. Validates the HTTP method (POST) and the secret token sent by Telegram.
 * 2. Filters for text/caption messages from allowlisted users and chats (ignores bots).
 * 3. Closes the HTTP connection with Telegram immediately (HTTP 200) to prevent retries.
 * 4. Forwards the full update payload via authenticated POST to the Grok Bot webhook.
 * 5. Logs minimal diagnostic metadata (excluding message text) to `bridge.log`.
 */

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('misconfigured');
}

require $configFile;

// Ensure required configuration constants are populated before accepting requests.
if (
    !defined('TG_SECRET') || TG_SECRET === ''
    || !defined('GROK_URL') || GROK_URL === ''
    || !defined('GROK_KEY') || GROK_KEY === ''
) {
    http_response_code(500);
    exit('misconfigured');
}

// Only accept POST requests with the matching secret token in the Telegram header.
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($method !== 'POST' || !hash_equals((string) TG_SECRET, $secret)) {
    http_response_code(403);
    exit('forbidden');
}

$raw = file_get_contents('php://input');
$update = is_string($raw) ? json_decode($raw, true) : null;

// Always return 200 OK to Telegram so ignored updates are not queued for retry.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

if (!is_array($update)) {
    exit('ok');
}

$msg = $update['message'] ?? $update['edited_message'] ?? null;
if (!is_array($msg)) {
    exit('ok');
}

$chatId  = $msg['chat']['id'] ?? null;
$fromId  = $msg['from']['id'] ?? null;
$isBot   = (bool) ($msg['from']['is_bot'] ?? false);
$text    = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
$hasText = $text !== '';

$allowedChats = isset($ALLOWED_CHATS) && is_array($ALLOWED_CHATS) ? $ALLOWED_CHATS : [];
$allowedUsers = isset($ALLOWED_USERS) && is_array($ALLOWED_USERS) ? $ALLOWED_USERS : [];

if (
    $isBot
    || !$hasText
    || !in_array($chatId, $allowedChats, true)
    || !in_array($fromId, $allowedUsers, true)
) {
    exit('ok');
}

// Keep script execution alive after closing the connection with Telegram.
ignore_user_abort(true);

// Flush the response to Telegram before forwarding the request to Grok Bot.
if (function_exists('fastcgi_finish_request')) {
    echo 'ok';
    fastcgi_finish_request();
} else {
    header('Content-Length: 2');
    header('Connection: close');
    echo 'ok';
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

$body = json_encode(
    [
        'source' => 'telegram-bridge',
        'update' => $update,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

$ch = curl_init((string) GROK_URL);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . GROK_KEY,
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 20,
]);

curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_errno($ch) ? curl_error($ch) : '';

if (PHP_VERSION_ID < 80000) {
    curl_close($ch);
} else {
    unset($ch);
}

// Log minimal diagnostic metadata (never log message content or credentials).
$updateId = $update['update_id'] ?? '?';
$errSuffix = $err !== '' ? " curl_error=\"" . str_replace(["\r", "\n", '"'], ' ', $err) . "\"" : '';
$line = sprintf("%s update=%s chat=%s grok_http=%d%s\n", date('c'), $updateId, (string) $chatId, $code, $errSuffix);
@file_put_contents(__DIR__ . '/bridge.log', $line, FILE_APPEND | LOCK_EX);
