<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$subject = $argv[1] ?? '';
if (!preg_match('#^(https://[^\s]+|mailto:[^\s@]+@[^\s@]+)$#', $subject)) {
    fwrite(STDERR, "Usage: php bin/generate_vapid.php https://your-domain.example/\n");
    exit(2);
}
$envPath = dirname(__DIR__) . '/.env';
if (!is_file($envPath) || !is_writable($envPath)) {
    fwrite(STDERR, "A writable private .env file is required.\n");
    exit(1);
}
$contents = (string)file_get_contents($envPath);
if (preg_match('/^VAPID_PRIVATE_KEY=\S+/m', $contents)) {
    fwrite(STDERR, "A VAPID private key is already configured. Existing subscriptions depend on it.\n");
    exit(1);
}
require dirname(__DIR__) . '/vendor/autoload.php';
$keys = Minishlink\WebPush\VAPID::createVapidKeys();
$contents = rtrim($contents) . "\nVAPID_PUBLIC_KEY=" . $keys['publicKey'] . "\nVAPID_PRIVATE_KEY=" . $keys['privateKey'] . "\nVAPID_SUBJECT=" . $subject . "\n";
if (file_put_contents($envPath, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Could not update .env.\n");
    exit(1);
}
echo "VAPID keys were written to the private .env file.\n";
