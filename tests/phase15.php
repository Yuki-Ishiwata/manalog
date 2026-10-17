<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/notifications.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

check(notification_due_at('2026-10-19', '25:00:00')->format('Y-m-d H:i') === '2026-10-20 01:00', '25:00 must mean next morning.');
check(notification_due_at('2026-10-19', '01:00:00')->format('Y-m-d H:i') === '2026-10-19 01:00', '01:00 must mean the same morning.');
$vapid = Minishlink\WebPush\VAPID::createVapidKeys();
check(is_callable(push_sender(['vapid_public_key' => $vapid['publicKey'], 'vapid_private_key' => $vapid['privateKey'], 'vapid_subject' => 'https://example.com', 'app_base_url' => 'https://example.com'])), 'Web Push sender could not be created.');

$pdo = db();
$pdo->exec("SET time_zone = '+09:00'");
$studentId = null;
try {
    $login = 'phase15_test_' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("INSERT INTO users (login_id, password_hash, role) VALUES (?, ?, 'student')");
    $stmt->execute([$login, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT)]);
    $studentId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO student_profiles (user_id, display_name) VALUES (?, ?)')->execute([$studentId, 'Phase 1.5 test']);
    $pdo->prepare('INSERT INTO notification_preferences (student_id, enabled) VALUES (?, 1)')->execute([$studentId]);
    $pdo->prepare('INSERT INTO notification_schedule (student_id, day_of_week, after_school_time, night_time) VALUES (?, 1, ?, ?)')->execute([$studentId, '16:00:00', '25:00:00']);
    $pdo->prepare('INSERT INTO timetables (student_id, title, is_active) VALUES (?, ?, 1)')->execute([$studentId, 'test']);
    $timetableId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO timetable_entries (timetable_id, day_of_week, period_number, subject) VALUES (?, 1, 1, ?)')->execute([$timetableId, '数学']);
    $endpoint = 'https://fcm.googleapis.com/fcm/send/test-' . bin2hex(random_bytes(12));
    $pdo->prepare('INSERT INTO push_subscriptions (student_id, endpoint_hash, endpoint, p256dh, auth) VALUES (?, ?, ?, ?, ?)')->execute([$studentId, hash('sha256', $endpoint), $endpoint, str_repeat('A', 87), str_repeat('B', 22)]);

    $schoolId = daily_review_session($pdo, $studentId, '2026-10-19', 'after_school');
    check($schoolId !== null && $schoolId === daily_review_session($pdo, $studentId, '2026-10-19', 'after_school'), 'School review must be reused.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM chat_session_subjects WHERE session_id = ?');
    $stmt->execute([$schoolId]);
    check((int)$stmt->fetchColumn() === 1, 'School subject snapshot is missing.');

    $pdo->prepare("INSERT INTO chat_sessions (student_id, session_date, session_type) VALUES (?, '2026-10-18', 'general')")->execute([$studentId]);
    $legacyId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)")->execute([$legacyId, '今日は学校で何を勉強した？']);
    $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)")->execute([$legacyId, '英語を勉強した']);
    check(daily_review_session($pdo, $studentId, '2026-10-18', 'after_school') === $legacyId, 'A Phase 1 school conversation must be reused.');

    // Answered school reviews are not pushed again.
    $pdo->prepare('UPDATE chat_sessions SET ended_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$schoolId]);
    $sent = 0;
    $fakeSender = static function (array $subscription, string $payload) use (&$sent): array {
        $sent++;
        check(str_contains($payload, '?session='), 'Push payload must link to the chat.');
        return ['success' => true, 'expired' => false, 'retryable' => false, 'error' => null];
    };
    $config = ['app_base_url' => 'http://localhost/manalog/public'];
    $schoolResult = send_due_notifications($pdo, $config, new DateTimeImmutable('2026-10-19 16:10:00', new DateTimeZone('Asia/Tokyo')), $fakeSender);
    check($schoolResult['skipped'] === 1 && $sent === 0, 'Completed school review must not send.');

    $nightNow = new DateTimeImmutable('2026-10-20 01:10:00', new DateTimeZone('Asia/Tokyo'));
    $first = send_due_notifications($pdo, $config, $nightNow, $fakeSender);
    check($first['sent'] === 1 && $sent === 1, 'Night push was not sent once.');
    $second = send_due_notifications($pdo, $config, $nightNow, $fakeSender);
    check($second['sent'] === 0 && $sent === 1, 'A duplicate night push was sent.');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_deliveries WHERE student_id = ? AND review_date = '2026-10-19' AND notification_type = 'night'");
    $stmt->execute([$studentId]);
    check((int)$stmt->fetchColumn() === 1, 'Delivery uniqueness failed.');

    $pdo->prepare('INSERT INTO notification_schedule (student_id, day_of_week, night_time, night_enabled) VALUES (?, 2, ?, 0)')->execute([$studentId, '25:00:00']);
    $off = queue_due_notifications($pdo, new DateTimeImmutable('2026-10-21 01:10:00', new DateTimeZone('Asia/Tokyo')));
    check($off === 0, 'Weekday OFF must prevent scheduling.');
    $pdo->prepare('UPDATE notification_schedule SET night_enabled = 1 WHERE student_id = ? AND day_of_week = 2')->execute([$studentId]);
    $pdo->prepare('UPDATE notification_preferences SET paused = 1 WHERE student_id = ?')->execute([$studentId]);
    $paused = queue_due_notifications($pdo, new DateTimeImmutable('2026-10-21 01:10:00', new DateTimeZone('Asia/Tokyo')));
    check($paused === 0, 'Pause must prevent scheduling.');
    $pdo->prepare('UPDATE notification_preferences SET paused = 0 WHERE student_id = ?')->execute([$studentId]);
    $attempts = 0;
    $retrySender = static function (array $subscription, string $payload) use (&$attempts): array {
        $attempts++;
        return $attempts === 1
            ? ['success' => false, 'expired' => false, 'retryable' => true, 'error' => 'push service returned 503']
            : ['success' => true, 'expired' => false, 'retryable' => false, 'error' => null];
    };
    $retryNow = new DateTimeImmutable('2026-10-21 01:10:00', new DateTimeZone('Asia/Tokyo'));
    check(send_due_notifications($pdo, $config, $retryNow, $retrySender)['failed'] === 1, 'Push failure must be recorded.');
    check(send_due_notifications($pdo, $config, $retryNow, $retrySender)['sent'] === 1, 'Definite push failure must be retried.');
    send_due_notifications($pdo, $config, $retryNow, $retrySender);
    check($attempts === 2, 'Successfully retried push must not be sent again.');

    session_name('manalog_session');
    session_start();
    $_SESSION['user_id'] = $studentId;
    $_SESSION['role'] = 'student';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $_POST = [];
    ob_start();
    require dirname(__DIR__) . '/public/index.php';
    $html = (string)ob_get_clean();
    check(str_contains($html, 'id="push-panel"') && str_contains($html, '夜の振り返りを開く'), 'Student PWA settings did not render.');
    session_destroy();
    echo "Phase 1.5 checks passed.\n";
} finally {
    if ($studentId !== null) $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$studentId]);
}
