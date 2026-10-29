<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/learning_records.php';
require dirname(__DIR__) . '/src/weekly_reviews.php';

function phase3_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

[$start, $end] = weekly_review_range(new DateTimeImmutable('2026-10-24 23:30:00', new DateTimeZone('Asia/Tokyo')));
phase3_assert($start === '2026-10-19' && $end === '2026-10-25', 'Saturday should map to its Monday-Sunday week.');
$fallback = weekly_review_fallback_recommendation([
    'not_understood' => '傾きの意味',
    'questions' => [['status' => 'unanswered']],
    'problems' => [['result' => 'incorrect', 'source' => 'student_mark']],
]);
phase3_assert(str_contains($fallback['reason'], '傾きの意味'), 'Fallback reason should use saved uncertainty.');
phase3_assert(str_contains($fallback['reason'], '写真の生徒の印'), 'Fallback reason should distinguish a student mark.');
phase3_assert($fallback['estimated_minutes'] >= 5 && $fallback['estimated_minutes'] <= 30, 'Review duration should stay within its range.');
$empty = weekly_review_fallback_recommendation(['problems' => [['result' => 'ungraded', 'source' => 'ai_estimate']]]);
phase3_assert(!str_contains($empty['reason'], '×') && !str_contains($empty['reason'], '△'), 'Ungraded work must not be described as wrong.');
echo "Phase 3 weekly review unit checks passed.\n";
