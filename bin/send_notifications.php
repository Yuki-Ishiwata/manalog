<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Tokyo');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/notifications.php';
require dirname(__DIR__) . '/src/learning_records.php';
require dirname(__DIR__) . '/src/weekly_reviews.php';
require_once dirname(__DIR__) . '/src/reviews.php';

try {
    $pdo = db();
    $pdo->exec("SET time_zone = '+09:00'");
    $counts = send_due_notifications($pdo, $config, new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')));
    $counts['weekly_review'] = send_due_weekly_review_notifications($pdo, $config, new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')));
    $counts['onboarding_reminder'] = send_due_onboarding_reminders($pdo, $config, new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')));
    $learningCounts = process_learning_extraction_jobs($pdo, $config, 2);
    $counts['learning_extraction'] = $learningCounts;
    $counts['telegram_learning_summary'] = process_telegram_learning_summary_jobs($pdo, $config, 10);
    echo json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    error_log('ManaLog notification cron: ' . $e->getMessage());
    fwrite(STDERR, '通知送信処理に失敗しました。' . PHP_EOL);
    exit(1);
}
