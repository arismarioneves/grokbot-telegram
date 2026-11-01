<?php

declare(strict_types=1);

/**
 * Configuration template for the Telegram -> Grok Bot webhook bridge.
 *
 * Copy this file to `config.php`, fill in the values below,
 * and upload it alongside `telegram.php` and `.htaccess` to your HTTPS server.
 *
 * IMPORTANT: NEVER share or commit `config.php`.
 */

// 1) Secret token for the Telegram Webhook (32–256 characters: letters, digits, _ and -).
//    Telegram sends this value in the `X-Telegram-Bot-Api-Secret-Token` header
//    with every request, and the bridge rejects any call that does not match.
//    Generate one in your terminal:
//    openssl rand -base64 48 | tr '+/' '_-' | tr -d '=' | cut -c1-40
define('TG_SECRET', '');

// 2) Webhook URL and key from your Grok Bot / Cursor Automations routine.
//    Copy the "POST to" (Webhook URL) and "key" (Webhook Key) fields from the routine's Webhook section.
define('GROK_URL', '');
define('GROK_KEY', '');

// 3) Allowed Telegram chat IDs (positive for private chats, negative for groups/supergroups).
//    Example: [123456789, -1009876543210]
$ALLOWED_CHATS = [];

// 4) Allowed Telegram user IDs (senders) permitted to trigger the bot.
//    Example: [123456789, 987654321]
$ALLOWED_USERS = [];
