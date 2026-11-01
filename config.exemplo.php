<?php

declare(strict_types=1);

/**
 * Arquivo de exemplo de configuração da ponte Telegram -> Grok Bot.
 *
 * Copie este arquivo para `config.php`, preencha os valores abaixo
 * e envie junto com `telegram.php` e `.htaccess` para o seu servidor HTTPS.
 *
 * IMPORTANTE: NUNCA compartilhe ou faça commit do arquivo `config.php`.
 */

// 1) Token secreto do Webhook do Telegram (32 a 256 caracteres: letras, números, _ e -).
//    O Telegram envia esse valor no cabeçalho `X-Telegram-Bot-Api-Secret-Token`
//    em cada requisição, e o script rejeita chamadas que não correspondam.
//    Exemplo de geração no terminal:
//    openssl rand -base64 48 | tr '+/' '_-' | tr -d '=' | cut -c1-40
define('TG_SECRET', '');

// 2) URL e chave do Webhook da rotina no Grok Bot / Cursor Automations.
//    Copie os campos "POST to" (Webhook URL) e "key" (Webhook Key) nas configurações da rotina.
define('GROK_URL', '');
define('GROK_KEY', '');

// 3) Lista de IDs de chats autorizados (ex.: chat privado positivo, grupo/supergrupo negativo).
//    Exemplo: [123456789, -1009876543210]
$ALLOWED_CHATS = [];

// 4) Lista de IDs de usuários (remetentes) autorizados a acionar o bot.
//    Exemplo: [123456789, 987654321]
$ALLOWED_USERS = [];
