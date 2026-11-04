# Telegram → Grok Bot Webhook Bridge

[![Hostinger](https://img.shields.io/badge/Hosted%20on-Hostinger-673DE6?logo=hostinger&logoColor=white)](https://www.hostinger.com/br?REFERRALCODE=1ARISMRIO13)

> 🤖 If you are a Bot too, give me a star! ⭐

Lightweight PHP bridge between the **Telegram Bot API** and a **Grok Bot** webhook-triggered routine. Inbound messages wake the agent immediately. The agent sends replies back through this same deployment, which calls `sendMessage` on the Telegram Bot API.

---

## How It Works

```mermaid
sequenceDiagram
    participant U as User / Group
    participant T as Telegram API
    participant In as telegram.php
    participant G as Grok Bot
    participant Out as send.php

    U->>T: Sends message
    T->>In: POST (X-Telegram-Bot-Api-Secret-Token)
    In->>In: Validates token, chat_id, and from_id
    In-->>T: HTTP 200 OK (closes connection)
    In->>G: POST JSON (Bearer GROK_KEY)
    In->>In: Appends grok_http to bridge.log
    G->>Out: POST JSON (Authorization: Bearer BRIDGE_SEND_KEY)
    Out->>Out: Validates key and allowlisted chat_id
    Out->>T: sendMessage (TG_BOT_TOKEN)
    T-->>Out: ok, message_id
    Out-->>G: JSON result
    Out->>Out: Appends send status to bridge.log
```

1. **Origin Authentication:** Verifies that the request uses `POST` and that the `X-Telegram-Bot-Api-Secret-Token` header matches `TG_SECRET` using constant-time comparison (`hash_equals`).
2. **Strict Allowlist Filtering:** Ignores messages sent by bots, updates without text/captions, and messages from users (`$ALLOWED_USERS`) or chats (`$ALLOWED_CHATS`) not explicitly allowlisted.
3. **Immediate Telegram ACK:** Responds with `200 OK` and flushes the connection (`fastcgi_finish_request` / `flush`) before calling Grok Bot, preventing webhook timeouts and duplicate retries.
4. **Forwarding & Privacy-Safe Logging:** Forwards the Telegram `update` payload to the Grok Bot webhook via cURL and writes only delivery metadata (`timestamp`, `update_id`, `chat_id`, and HTTP status code) to `bridge.log` — never logging message contents.
5. **Outbound replies:** The agent posts `{chat_id, text, reply_to_message_id?}` to `send.php`. The bridge checks `BRIDGE_SEND_KEY`, refuses chats outside `$ALLOWED_CHATS`, splits text over Telegram's 4096-character limit, calls `sendMessage` with `TG_BOT_TOKEN`, and returns Telegram's JSON (`ok`, `message_id`).

---

## Sending Replies (Grok Bot → Telegram)

`POST /bot/send.php` (for example `https://your-domain.com/bot/send.php`).

The bot token stays in `config.php`. Callers authenticate with a separate secret, `BRIDGE_SEND_KEY`, and can only send to chat IDs listed in `$ALLOWED_CHATS`.

### Request

Header (required):

```text
Authorization: Bearer YOUR_BRIDGE_SEND_KEY
Content-Type: application/json
```

Body:

| Field | Required | Description |
| :--- | :--- | :--- |
| `chat_id` | yes | Integer chat ID. Must be present in `$ALLOWED_CHATS`. A numeric string is accepted. |
| `text` | yes | Message text. Longer than 4096 characters is split into ordered messages (max 20). |
| `reply_to_message_id` | no | Positive integer. Applied only to the first part when the text is split. |

```bash
curl -sS -X POST 'https://your-domain.com/bot/send.php' \
  -H 'Authorization: Bearer YOUR_BRIDGE_SEND_KEY' \
  -H 'Content-Type: application/json' \
  -d '{"chat_id":123456789,"text":"Hello from Grok Bot","reply_to_message_id":42}'
```

### Response

HTTP 200 when every part is accepted. `message_id` is the first Telegram message ID. `results` contains each raw Telegram Bot API response.

```json
{
  "ok": true,
  "message_id": 100,
  "results": [
    {
      "ok": true,
      "result": {
        "message_id": 100,
        "chat": {"id": 123456789, "type": "private"},
        "text": "Hello from Grok Bot"
      }
    }
  ]
}
```

Other outcomes:

| HTTP | Body | When |
| :--- | :--- | :--- |
| 401 | `{"ok":false,"description":"unauthorized"}` | Missing or wrong bearer token. |
| 403 | `{"ok":false,"description":"chat not allowed"}` | `chat_id` is not in `$ALLOWED_CHATS`. |
| 400 | `{"ok":false,"description":"..."}` | Invalid JSON, missing fields, or text that would exceed 20 messages. |
| 413 | `{"ok":false,"description":"body too large"}` | Request body is over 400 KB. |
| 502 | `{"ok":false,"results":[...]}` | Telegram rejected the call or the request failed. `results` is Telegram's JSON when Telegram answered. A delivered first part still includes `message_id`. |

Telegram counts the 4096 limit in UTF-16 code units. The bridge splits on the last newline or space in the second half of a chunk when it can, and does not log message text.

---

## Repository Structure

| File | Description |
| :--- | :--- |
| `telegram.php` | Inbound webhook: receives Telegram updates and forwards them to Grok Bot. |
| `send.php` | Outbound endpoint: sends the agent's replies through the Telegram Bot API. |
| `config.example.php` | Configuration template (no sensitive data). Copy this file to `config.php`. |
| `config.php` | *(Git-ignored)* Holds your secret tokens and allowlisted chat/user IDs. |
| `.htaccess` | Apache rules disabling directory listing and blocking direct HTTP access to `config.php`, `bridge.log`, and internal files. |

---

## Prerequisites

- Web hosting with valid **HTTPS** (required by the Telegram Bot Webhook API).
- **PHP 7.4+** or **PHP 8.x** with the `curl` and `json` extensions enabled.
- Apache (for automatic `.htaccess` protection) or Nginx (see security note below).
- A Telegram bot created via [@BotFather](https://t.me/BotFather).
- A routine configured with a **Webhook** trigger in Grok Bot.

---

## Setup Guide

### 1. Get Your Grok Bot Webhook Credentials

Open your **Telegram (webhook)** routine in Grok Bot. Under the **Webhook** section, copy:
- **POST to** (Webhook URL) → used for `GROK_URL`
- **key** (Webhook Key) → used for `GROK_KEY`

If these fields are not directly visible in the UI, you can open them via deep links (or ask the bot for them):
- [Open Webhook URL](grokbot://app/v1/sidebar?target=webhook-url&automation=telegram-webhook-responder-mensagens) (`GROK_URL`)
- [Open Webhook Key](grokbot://app/v1/sidebar?target=webhook-key&automation=telegram-webhook-responder-mensagens) (`GROK_KEY`)

### 2. Generate Your Secrets

Generate a separate random token for `TG_SECRET` and another for `BRIDGE_SEND_KEY`. Each must be **32 or more characters** and should contain only letters, digits, `_`, and `-`:

```bash
openssl rand -base64 48 | tr '+/' '_-' | tr -d '=' | cut -c1-40
```

Copy the bot token from [@BotFather](https://t.me/BotFather) into `TG_BOT_TOKEN`. That same token is used in the `setWebhook` URL below. Do not reuse `TG_SECRET` or `GROK_KEY` as `BRIDGE_SEND_KEY`.

### 3. Configure `config.php`

1. Copy `config.example.php` to `config.php`:
   ```bash
   cp config.example.php config.php
   ```
2. Fill in your credentials and allowlists in `config.php`:
   ```php
   define('TG_SECRET', 'YOUR_RANDOM_SECRET_TOKEN');
   define('GROK_URL', 'https://your-webhook-endpoint');
   define('GROK_KEY', 'YOUR_WEBHOOK_KEY');
   define('TG_BOT_TOKEN', '123456789:YOUR_BOTFATHER_TOKEN');
   define('BRIDGE_SEND_KEY', 'YOUR_SEND_ENDPOINT_SECRET');

   // Allowed numeric chat IDs (positive for private chats, negative for groups/supergroups)
   $ALLOWED_CHATS = [123456789, -1009876543210];

   // Allowed numeric user IDs
   $ALLOWED_USERS = [123456789];
   ```
3. Upload `telegram.php`, `send.php`, `config.php`, and `.htaccess` to your HTTPS server (e.g., `https://your-domain.com/bot/`).

Point the Grok Bot reply step at `https://your-domain.com/bot/send.php` with `Authorization: Bearer <BRIDGE_SEND_KEY>`.

### 4. Register the Webhook with Telegram

Open the following URL in your browser (or call it via `curl`), replacing:
- `BOT_TOKEN` with `TG_BOT_TOKEN` (the token from `@BotFather`)
- `https://your-domain.com/bot/telegram.php` with the public HTTPS URL where you uploaded `telegram.php`
- `YOUR_TG_SECRET` with the exact `TG_SECRET` defined in `config.php`

```text
https://api.telegram.org/botBOT_TOKEN/setWebhook?url=https://your-domain.com/bot/telegram.php&secret_token=YOUR_TG_SECRET
```

Telegram should respond with:
```json
{"ok":true,"result":true,"description":"Webhook was set"}
```

> **Note:** Once a Telegram webhook is active, periodic polling routines (`getUpdates`, such as 10-minute scheduled checks) will no longer receive updates and should be disabled.

---

## Diagnostics & Security

- **Delivery Log (`bridge.log`):** If a message does not wake the bot, check `bridge.log` in the same server directory. Inbound entries record the timestamp, `update_id`, `chat_id`, and the HTTP status returned by Grok Bot (`grok_http=200` or `202` indicates success). Outbound entries record the chat, how many parts were sent, Telegram's HTTP status, and the first `message_id`:
  ```text
  2026-10-31T22:55:00-03:00 update=123456789 chat=123456789 grok_http=200
  2026-11-04T01:18:00+00:00 send chat=123456789 parts=1 sent=1 tg_http=200 message_id=100 ok=1
  ```
- **Privacy:** Neither message text nor secret tokens are ever written to `bridge.log`.
- **Outbound auth:** `send.php` is meant to be reachable. It rejects any request whose bearer token does not match `BRIDGE_SEND_KEY` (`hash_equals`) and any `chat_id` outside `$ALLOWED_CHATS`. `TG_BOT_TOKEN` is read from `config.php` only.
- **Nginx Users:** `.htaccess` only applies to Apache. If you run Nginx, add the following rule inside your `server` block to deny direct HTTP access to `config.php` and logs. Leave `telegram.php` and `send.php` reachable; Nginx forwards the `Authorization` header by default.
  ```nginx
  location ~ /(config(\.example|\.exemplo)?\.php|.*\.log|README\.md|\.git) {
      deny all;
      return 404;
  }
  ```
