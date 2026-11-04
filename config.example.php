<?php

declare(strict_types=1);

/**
 * Configuration template for the Telegram -> Grok Bot webhook bridge.
 *
 * Copy this file to `config.php`, fill in the values below,
 * and upload it alongside `telegram.php`, `send.php`, and `.htaccess`.
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

// 3) Telegram bot token from @BotFather. send.php uses it to call sendMessage.
//    This is the same token you pass to setWebhook. Never commit the real value.
define('TG_BOT_TOKEN', '');

// 4) Secret for the outbound send endpoint (send.php).
//    The Grok Bot agent must send it as `Authorization: Bearer <this value>`.
//    Keep it different from TG_SECRET and GROK_KEY.
//    Generate one in your terminal:
//    openssl rand -base64 48 | tr '+/' '_-' | tr -d '=' | cut -c1-40
define('BRIDGE_SEND_KEY', '');

// 5) Allowed Telegram chat IDs (positive for private chats, negative for groups/supergroups).
//    send.php refuses any chat_id that is not in this list.
//    Example: [123456789, -1009876543210]
$ALLOWED_CHATS = [];

// 6) Allowed Telegram user IDs (senders) permitted to trigger the bot.
//    Example: [123456789, 987654321]
$ALLOWED_USERS = [];
