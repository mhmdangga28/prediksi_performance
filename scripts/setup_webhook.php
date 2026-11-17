<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$url = envRequired('TELEGRAM_WEBHOOK_URL');
if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
    exit("TELEGRAM_WEBHOOK_URL harus HTTPS publik.\n");
}
$result = telegramApi('setWebhook', [
    'url' => $url,
    'secret_token' => envRequired('TELEGRAM_WEBHOOK_SECRET'),
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
]);
echo ($result['description'] ?? 'Webhook tersimpan') . PHP_EOL;
