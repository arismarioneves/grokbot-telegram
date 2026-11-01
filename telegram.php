<?php

declare(strict_types=1);

/**
 * Ponte (Webhook Bridge) entre a API do Telegram e uma rotina Webhook do Grok Bot.
 *
 * Fluxo:
 * 1. Valida o método HTTP (POST) e o token secreto enviado pelo Telegram.
 * 2. Filtra apenas mensagens de texto/legenda de usuários e chats autorizados (ignora bots).
 * 3. Encerra a conexão HTTP com o Telegram imediatamente (HTTP 200) para evitar reenvios.
 * 4. Encaminha o payload completo via POST autenticado para o Webhook do Grok Bot.
 * 5. Registra apenas metadados de diagnóstico (sem conteúdo das mensagens) em `bridge.log`.
 */

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('misconfigured');
}

require $configFile;

// Garante que as constantes essenciais foram preenchidas antes de aceitar requisições.
if (
    !defined('TG_SECRET') || TG_SECRET === ''
    || !defined('GROK_URL') || GROK_URL === ''
    || !defined('GROK_KEY') || GROK_KEY === ''
) {
    http_response_code(500);
    exit('misconfigured');
}

// Só aceita POST com a senha secreta que o Telegram envia no cabeçalho.
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($method !== 'POST' || !hash_equals((string) TG_SECRET, $secret)) {
    http_response_code(403);
    exit('forbidden');
}

$raw = file_get_contents('php://input');
$update = is_string($raw) ? json_decode($raw, true) : null;

// Responde 200 ao Telegram de qualquer jeito para evitar fila de reenvios em updates ignorados.
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

// Mantém a execução viva mesmo após fechar a conexão com o Telegram.
ignore_user_abort(true);

// Libera a resposta para o Telegram antes de chamar o Grok Bot.
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

// Registra apenas metadados curtos (sem texto das mensagens) para diagnóstico.
$updateId = $update['update_id'] ?? '?';
$errSuffix = $err !== '' ? " curl_error=\"" . str_replace(["\r", "\n", '"'], ' ', $err) . "\"" : '';
$line = sprintf("%s update=%s chat=%s grok_http=%d%s\n", date('c'), $updateId, (string) $chatId, $code, $errSuffix);
@file_put_contents(__DIR__ . '/bridge.log', $line, FILE_APPEND | LOCK_EX);
