<?php

declare(strict_types=1);

/**
 * Grok Bot -> Telegram sendMessage bridge.
 *
 * Workflow:
 * 1. Accepts an authenticated POST JSON body from the Grok Bot agent.
 * 2. Compares `Authorization: Bearer <BRIDGE_SEND_KEY>` in constant time.
 * 3. Sends only to chat IDs listed in `$ALLOWED_CHATS`.
 * 4. Splits text that exceeds Telegram's 4096-character limit and calls sendMessage.
 * 5. Returns Telegram's JSON result (`ok`, `message_id`) and logs metadata only.
 */

const BRIDGE_TEXT_LIMIT = 4096;
const BRIDGE_MAX_PARTS = 20;
const BRIDGE_MAX_BODY_BYTES = 400000;

/**
 * @param mixed $value
 */
function bridge_normalize_int($value): ?int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
        $asInt = (int) $value;
        if ((string) $asInt === $value) {
            return $asInt;
        }
    }

    return null;
}

function bridge_request_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    $redirectKey = 'REDIRECT_' . $key;
    if (isset($_SERVER[$redirectKey]) && is_string($_SERVER[$redirectKey]) && $_SERVER[$redirectKey] !== '') {
        return $_SERVER[$redirectKey];
    }

    $sources = [];
    if (function_exists('apache_request_headers')) {
        $sources[] = apache_request_headers();
    }
    if (function_exists('getallheaders')) {
        $sources[] = getallheaders();
    }

    foreach ($sources as $headers) {
        if (!is_array($headers)) {
            continue;
        }
        foreach ($headers as $headerName => $headerValue) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                return (string) $headerValue;
            }
        }
    }

    return '';
}

function bridge_bearer_token(string $header): string
{
    if (strlen($header) < 7 || strncasecmp($header, 'Bearer ', 7) !== 0) {
        return '';
    }

    return substr($header, 7);
}

/**
 * Telegram counts the sendMessage text limit in UTF-16 code units.
 * In valid UTF-8, only 4-byte sequences are non-BMP characters (2 units).
 */
function bridge_utf16_units(string $char): int
{
    return strlen($char) >= 4 ? 2 : 1;
}

/**
 * Split $text into chunks of at most $limit UTF-16 code units.
 * Prefers the last newline, then the last space, once half a chunk is full.
 * The single separator at a soft break is not repeated in the next chunk.
 *
 * @return list<string>
 */
function bridge_split_text(string $text, int $limit = BRIDGE_TEXT_LIMIT): array
{
    if ($text === '' || preg_match('//u', $text) !== 1) {
        return [];
    }

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || $chars === []) {
        return [];
    }

    $parts = [];
    $count = count($chars);
    $index = 0;

    while ($index < $count) {
        $units = 0;
        $cursor = $index;
        $breakNl = -1;
        $breakSp = -1;

        while ($cursor < $count) {
            $needed = bridge_utf16_units($chars[$cursor]);
            if ($units + $needed > $limit) {
                break;
            }
            $units += $needed;
            if ($chars[$cursor] === "\n") {
                $breakNl = $cursor;
            } elseif ($chars[$cursor] === ' ') {
                $breakSp = $cursor;
            }
            $cursor++;
        }

        if ($cursor === $index) {
            return [];
        }

        $next = $cursor;
        $end = $cursor;

        if ($cursor < $count) {
            $minBreak = $index + (int) (($cursor - $index) * 0.5);
            if ($breakNl >= $minBreak) {
                $end = $breakNl;
                $next = $breakNl + 1;
                if ($end > $index && $chars[$end - 1] === "\r") {
                    $end--;
                }
            } elseif ($breakSp >= $minBreak) {
                $end = $breakSp;
                $next = $breakSp + 1;
            }
        }

        if ($end <= $index) {
            $end = $cursor;
            $next = $cursor;
        }

        $chunk = implode('', array_slice($chars, $index, $end - $index));
        if ($chunk !== '') {
            $parts[] = $chunk;
        }

        if ($next <= $index) {
            return [];
        }
        $index = $next;
    }

    return $parts;
}

/**
 * @param array<string, mixed> $payload
 */
function bridge_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bridge_fail(int $status, string $description): void
{
    if ($status === 401) {
        header('WWW-Authenticate: Bearer');
    }
    bridge_json($status, [
        'ok' => false,
        'description' => $description,
    ]);
}

function bridge_log_sanitize(string $value): string
{
    $value = str_replace(["\r", "\n", '"'], ' ', $value);
    $redacted = preg_replace('/\d{5,}:[A-Za-z0-9_-]{10,}/', '[redacted]', $value);

    return is_string($redacted) ? $redacted : $value;
}

function bridge_log(string $line): void
{
    @file_put_contents(__DIR__ . '/bridge.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * @return array{chat_id:int, text:string, reply_to_message_id?:int}
 */
function bridge_send_payload(int $chatId, string $text, ?int $replyTo): array
{
    $payload = [
        'chat_id' => $chatId,
        'text'    => $text,
    ];
    if ($replyTo !== null) {
        $payload['reply_to_message_id'] = $replyTo;
    }

    return $payload;
}

/**
 * @return array{http:int, json:array<string,mixed>|null, curl_error:string}
 */
function bridge_telegram_send(int $chatId, string $text, ?int $replyTo): array
{
    $endpoint = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $payload = bridge_send_payload($chatId, $text, $replyTo);

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['http' => 0, 'json' => null, 'curl_error' => 'json_encode failed'];
    }

    $ch = curl_init($endpoint);
    $options = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if (defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $options);

    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($ch) ? curl_error($ch) : '';

    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    } else {
        unset($ch);
    }

    $json = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'http'       => $http,
        'json'       => $json,
        'curl_error' => $curlError,
    ];
}

if (defined('BRIDGE_SEND_LIBRARY')) {
    return;
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    bridge_fail(500, 'misconfigured');
}

require $configFile;

if (
    !defined('TG_BOT_TOKEN') || !is_string(TG_BOT_TOKEN) || TG_BOT_TOKEN === ''
    || preg_match('/^\d+:[A-Za-z0-9_-]+$/', TG_BOT_TOKEN) !== 1
    || !defined('BRIDGE_SEND_KEY') || !is_string(BRIDGE_SEND_KEY) || BRIDGE_SEND_KEY === ''
    || !isset($ALLOWED_CHATS) || !is_array($ALLOWED_CHATS)
) {
    bridge_fail(500, 'misconfigured');
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    header('Allow: POST');
    bridge_fail(405, 'method not allowed');
}

$presented = bridge_bearer_token(bridge_request_header('Authorization'));
if ($presented === '' || !hash_equals(BRIDGE_SEND_KEY, $presented)) {
    bridge_fail(401, 'unauthorized');
}

$raw = file_get_contents('php://input');
if (!is_string($raw)) {
    bridge_log(sprintf("%s send rejected=body\n", date('c')));
    bridge_fail(400, 'invalid json');
}
if (strlen($raw) > BRIDGE_MAX_BODY_BYTES) {
    bridge_log(sprintf("%s send rejected=body\n", date('c')));
    bridge_fail(413, 'body too large');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    bridge_log(sprintf("%s send rejected=invalid_json\n", date('c')));
    bridge_fail(400, 'invalid json');
}

$chatId = bridge_normalize_int($payload['chat_id'] ?? null);
if ($chatId === null || $chatId === 0) {
    bridge_log(sprintf("%s send rejected=chat_id\n", date('c')));
    bridge_fail(400, 'chat_id is required');
}

if (!in_array($chatId, $ALLOWED_CHATS, true)) {
    bridge_log(sprintf("%s send chat=%s rejected=allowlist\n", date('c'), (string) $chatId));
    bridge_fail(403, 'chat not allowed');
}

$text = $payload['text'] ?? null;
if (!is_string($text) || trim($text) === '') {
    bridge_log(sprintf("%s send chat=%s rejected=text\n", date('c'), (string) $chatId));
    bridge_fail(400, 'text is required');
}

if (preg_match('//u', $text) !== 1) {
    bridge_log(sprintf("%s send chat=%s rejected=utf8\n", date('c'), (string) $chatId));
    bridge_fail(400, 'text must be valid UTF-8');
}

$replyTo = null;
if (array_key_exists('reply_to_message_id', $payload) && $payload['reply_to_message_id'] !== null) {
    $replyTo = bridge_normalize_int($payload['reply_to_message_id']);
    if ($replyTo === null || $replyTo <= 0) {
        bridge_log(sprintf("%s send chat=%s rejected=reply_to\n", date('c'), (string) $chatId));
        bridge_fail(400, 'reply_to_message_id must be a positive integer');
    }
}

$parts = bridge_split_text($text, BRIDGE_TEXT_LIMIT);
$parts = array_values(array_filter($parts, static function (string $part): bool {
    return trim($part) !== '';
}));
if ($parts === []) {
    bridge_log(sprintf("%s send chat=%s rejected=split\n", date('c'), (string) $chatId));
    bridge_fail(400, 'text is required');
}

if (count($parts) > BRIDGE_MAX_PARTS) {
    bridge_log(sprintf("%s send chat=%s rejected=too_long parts=%d\n", date('c'), (string) $chatId, count($parts)));
    bridge_fail(400, 'text too long');
}

$results = [];
$firstMessageId = null;
$allOk = true;
$sent = 0;
$httpForLog = 0;
$curlError = '';

foreach ($parts as $index => $part) {
    $replyForPart = $index === 0 ? $replyTo : null;
    $response = bridge_telegram_send($chatId, $part, $replyForPart);
    $httpForLog = $response['http'];

    if ($response['curl_error'] !== '') {
        $curlError = $response['curl_error'];
        $allOk = false;
        $results[] = [
            'ok'          => false,
            'description' => 'telegram request failed',
        ];
        break;
    }

    $telegram = $response['json'];
    if (!is_array($telegram)) {
        $allOk = false;
        $results[] = [
            'ok'          => false,
            'description' => 'telegram request failed',
        ];
        break;
    }

    $results[] = $telegram;
    $partOk = ($telegram['ok'] ?? false) === true;
    if (!$partOk) {
        $allOk = false;
        break;
    }

    $sent++;
    $messageId = $telegram['result']['message_id'] ?? null;
    if ($firstMessageId === null && is_int($messageId)) {
        $firstMessageId = $messageId;
    }
}

$errSuffix = $curlError !== ''
    ? ' curl_error="' . bridge_log_sanitize($curlError) . '"'
    : '';
bridge_log(sprintf(
    "%s send chat=%s parts=%d sent=%d tg_http=%d message_id=%s ok=%d%s\n",
    date('c'),
    (string) $chatId,
    count($parts),
    $sent,
    $httpForLog,
    $firstMessageId === null ? '-' : (string) $firstMessageId,
    $allOk ? 1 : 0,
    $errSuffix
));

// Bridge auth failures are HTTP 401. A rejected Telegram call stays 502 so a bad
// bot token is not mistaken for a bad BRIDGE_SEND_KEY. Telegram's JSON is in results.
$status = $allOk ? 200 : 502;

$out = [
    'ok'      => $allOk,
    'results' => $results,
];
if ($firstMessageId !== null) {
    $out = [
        'ok'         => $allOk,
        'message_id' => $firstMessageId,
        'results'    => $results,
    ];
}

bridge_json($status, $out);
