<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Tokyo');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/notifications.php';

try {
    $pdo = db();
    $pdo->exec("SET time_zone = '+09:00'");
    $counts = send_due_notifications($pdo, $config, new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')));
    echo json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    error_log('ManaLog notification cron: ' . $e->getMessage());
    fwrite(STDERR, '通知送信処理に失敗しました。' . PHP_EOL);
    exit(1);
}
