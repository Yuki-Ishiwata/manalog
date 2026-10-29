<?php
declare(strict_types=1);

require_once __DIR__ . '/reviews.php';
require_once __DIR__ . '/auth.php';

function notification_due_at(string $reviewDate, string $time): DateTimeImmutable {
    $day = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewDate, new DateTimeZone('Asia/Tokyo'));
    if (!$day || $day->format('Y-m-d') !== $reviewDate || !preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $matches)) {
        throw new InvalidArgumentException('Invalid notification schedule.');
    }
    return $day->modify('+' . (int)$matches[1] . ' hours')->modify('+' . (int)$matches[2] . ' minutes');
}

/** Queue due work for the service day, including night hours after midnight. */
function queue_due_notifications(PDO $pdo, DateTimeImmutable $now): int {
    $stmt = $pdo->query("SELECT s.student_id, s.day_of_week, s.after_school_time, s.after_school_enabled, s.night_time, s.night_enabled FROM notification_schedule s JOIN notification_preferences p ON p.student_id = s.student_id JOIN users u ON u.id = s.student_id WHERE u.status = 'active' AND p.enabled = 1 AND p.paused = 0 AND EXISTS (SELECT 1 FROM push_subscriptions sub WHERE sub.student_id = s.student_id AND sub.revoked_at IS NULL)");
    $rows = $stmt->fetchAll();
    $insert = $pdo->prepare("INSERT IGNORE INTO notification_deliveries (student_id, review_date, notification_type, due_at) VALUES (?, ?, ?, ?)");
    $queued = 0;
    foreach ($rows as $row) {
        foreach ([$now->modify('-1 day'), $now] as $day) {
            if ((int)$day->format('N') !== (int)$row['day_of_week']) continue;
            foreach (['after_school' => 'after_school_time', 'night' => 'night_time'] as $type => $field) {
                if (!(int)$row[$type . '_enabled'] || $row[$field] === null) continue;
                $due = notification_due_at($day->format('Y-m-d'), (string)$row[$field]);
                if ($due > $now || $due < $now->modify('-12 hours')) continue;
                $insert->execute([(int)$row['student_id'], $day->format('Y-m-d'), $type, $due->format('Y-m-d H:i:s')]);
                $queued += $insert->rowCount();
            }
        }
    }
    return $queued;
}

function push_sender(array $config): callable {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Composer dependencies are not installed.');
    require_once $autoload;
    foreach (['vapid_public_key', 'vapid_private_key', 'app_base_url'] as $key) {
        if (empty($config[$key])) throw new RuntimeException('Missing ' . strtoupper($key) . ' in .env.');
    }
    $webPush = new Minishlink\WebPush\WebPush(['VAPID' => [
        'subject' => $config['vapid_subject'] ?? $config['app_base_url'],
        'publicKey' => $config['vapid_public_key'],
        'privateKey' => $config['vapid_private_key'],
    ]], ['TTL' => 24 * 3600, 'urgency' => 'normal']);
    return static function (array $row, string $payload) use ($webPush): array {
        $subscription = Minishlink\WebPush\Subscription::create([
            'endpoint' => $row['endpoint'],
            'publicKey' => $row['p256dh'],
            'authToken' => $row['auth'],
            'contentEncoding' => $row['content_encoding'],
        ]);
        $report = $webPush->sendOneNotification($subscription, $payload);
        return [
            'success' => $report->isSuccess(),
            'expired' => $report->isSubscriptionExpired(),
            'retryable' => $report->getResponse() !== null && !$report->isSubscriptionExpired(),
            'error' => $report->isSuccess() ? null : mb_substr($report->getReason(), 0, 1000),
        ];
    };
}

/** The database claim and endpoint ledger prevent normal cron overlaps from resending. */
function send_due_notifications(PDO $pdo, array $config, DateTimeImmutable $now, ?callable $send = null): array {
    $queued = queue_due_notifications($pdo, $now);
    $send ??= push_sender($config);
    $nowText = $now->format('Y-m-d H:i:s');
    $leaseText = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
    $oldest = $now->modify('-12 hours')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT id FROM notification_deliveries WHERE due_at BETWEEN ? AND ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?)) ORDER BY due_at, id LIMIT 100");
    $stmt->execute([$oldest, $nowText, $nowText]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts = ['queued' => $queued, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'resent' => 0, 'resend_skipped' => 0, 'resend_failed' => 0];
    $claim = $pdo->prepare("UPDATE notification_deliveries SET status = 'sending', attempts = attempts + 1, lease_until = ? WHERE id = ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?))");
    foreach ($ids as $id) {
        $claim->execute([$leaseText, $id, $nowText]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $result = send_one_delivery($pdo, $config, (int)$id, $send, $now);
            $counts[$result]++;
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = 'failed', lease_until = NULL, last_error = ? WHERE id = ?");
            $stmt->execute([mb_substr($e->getMessage(), 0, 1000), $id]);
            $counts['failed']++;
        }
    }
    $resends = send_due_notification_resends($pdo, $config, $now, $send);
    foreach ($resends as $key => $value) $counts[$key] = $value;
    return $counts;
}

/** Send a single reminder 60 minutes later, only while the student has not started the review. */
function send_due_notification_resends(PDO $pdo, array $config, DateTimeImmutable $now, callable $send): array {
    $nowText = $now->format('Y-m-d H:i:s');
    $leaseText = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT id FROM notification_deliveries WHERE status = 'sent' AND resend_status IN ('pending','sending') AND resend_due_at <= ? AND (resend_lease_until IS NULL OR resend_lease_until < ?) ORDER BY resend_due_at, id LIMIT 100");
    $stmt->execute([$nowText, $nowText]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts = ['resent' => 0, 'resend_skipped' => 0, 'resend_failed' => 0];
    $claim = $pdo->prepare("UPDATE notification_deliveries SET resend_status = 'sending', resend_lease_until = ? WHERE id = ? AND status = 'sent' AND resend_status IN ('pending','sending') AND resend_due_at <= ? AND (resend_lease_until IS NULL OR resend_lease_until < ?)");
    foreach ($ids as $id) {
        $claim->execute([$leaseText, $id, $nowText, $nowText]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $result = send_one_notification_resend($pdo, $config, (int)$id, $send, $now);
            $counts[$result]++;
        } catch (Throwable $e) {
            $stmt = $pdo->prepare("UPDATE notification_deliveries SET resend_status = 'failed', resend_lease_until = NULL, resend_error = ? WHERE id = ? AND resend_status = 'sending'");
            $stmt->execute([mb_substr($e->getMessage(), 0, 1000), $id]);
            $counts['resend_failed']++;
        }
    }
    return $counts;
}

function send_one_notification_resend(PDO $pdo, array $config, int $deliveryId, callable $send, DateTimeImmutable $now): string {
    $stmt = $pdo->prepare("SELECT d.*, p.enabled, p.paused, u.status AS user_status, u.auth_source, s.after_school_enabled, s.night_enabled, s.after_school_time, s.night_time FROM notification_deliveries d JOIN notification_preferences p ON p.student_id = d.student_id JOIN users u ON u.id = d.student_id LEFT JOIN notification_schedule s ON s.student_id = d.student_id AND s.day_of_week = WEEKDAY(d.review_date) + 1 WHERE d.id = ?");
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();
    if (!$delivery || !$delivery['session_id']) return finish_notification_resend($pdo, $deliveryId, 'skipped', '振り返りセッションがありません。');
    $type = (string)$delivery['notification_type'];
    if ($delivery['user_status'] !== 'active' || !(int)$delivery['enabled'] || (int)$delivery['paused'] || !(int)$delivery[$type . '_enabled'] || $delivery[$type . '_time'] === null) {
        return finish_notification_resend($pdo, $deliveryId, 'skipped', '通知設定が無効です。');
    }
    if ($delivery['auth_source'] === 'school' && !auth_revalidate_school_user($pdo, (int)$delivery['student_id'], $config)) {
        return finish_notification_resend($pdo, $deliveryId, 'skipped', '塾の在籍を確認できません。');
    }
    $stmt = $pdo->prepare('SELECT ended_at FROM chat_sessions WHERE id = ? AND student_id = ?');
    $stmt->execute([(int)$delivery['session_id'], (int)$delivery['student_id']]);
    $session = $stmt->fetch();
    if (!$session) return finish_notification_resend($pdo, $deliveryId, 'skipped', '振り返りセッションがありません。');
    $stmt = $pdo->prepare("SELECT 1 FROM chat_messages WHERE session_id = ? AND role = 'user' LIMIT 1");
    $stmt->execute([(int)$delivery['session_id']]);
    if ($session['ended_at'] !== null || $stmt->fetchColumn()) {
        return finish_notification_resend($pdo, $deliveryId, 'skipped', '振り返りを開始済みです。');
    }
    $stmt = $pdo->prepare('SELECT endpoint_hash, endpoint, p256dh, auth, content_encoding FROM push_subscriptions WHERE student_id = ? AND revoked_at IS NULL');
    $stmt->execute([(int)$delivery['student_id']]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) return finish_notification_resend($pdo, $deliveryId, 'skipped', '有効な購読端末がありません。');
    $baseUrl = rtrim((string)($config['app_base_url'] ?? ''), '/') . '/';
    if (!preg_match('#^https://#', $baseUrl) && !str_starts_with($baseUrl, 'http://localhost/')) throw new RuntimeException('APP_BASE_URL must use HTTPS.');
    $label = $type === 'night' ? '夜の振り返り' : '帰宅後の振り返り';
    $payload = json_encode([
        'title' => '⏰ ' . $label,
        'body' => 'まだ始めていないようです。今から話してみよう。',
        'url' => $baseUrl . '?session=' . (int)$delivery['session_id'],
        'tag' => 'manalog-' . $delivery['review_date'] . '-' . $type,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $sent = 0;
    $errors = [];
    foreach ($subscriptions as $subscription) {
        try {
            $report = $send($subscription, $payload);
            if ($report['success']) {
                $sent++;
            } else {
                $errors[] = (string)($report['error'] ?? 'Push送信に失敗しました。');
                if (!empty($report['expired'])) {
                    $pdo->prepare('UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE endpoint_hash = ?')->execute([$subscription['endpoint_hash']]);
                }
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    if ($sent > 0) return finish_notification_resend($pdo, $deliveryId, 'sent', null, $now);
    return finish_notification_resend($pdo, $deliveryId, 'failed', mb_substr(implode(' / ', $errors) ?: 'Push送信に失敗しました。', 0, 1000));
}

function finish_notification_resend(PDO $pdo, int $deliveryId, string $status, ?string $error = null, ?DateTimeImmutable $sentAt = null): string {
    $stmt = $pdo->prepare("UPDATE notification_deliveries SET resend_status = ?, resend_lease_until = NULL, resend_sent_at = ?, resend_error = ? WHERE id = ? AND resend_status = 'sending'");
    $stmt->execute([$status, $status === 'sent' ? ($sentAt ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s') : null, $error, $deliveryId]);
    return $status === 'sent' ? 'resent' : ($status === 'skipped' ? 'resend_skipped' : 'resend_failed');
}

function send_one_delivery(PDO $pdo, array $config, int $deliveryId, callable $send, DateTimeImmutable $now): string {
    $stmt = $pdo->prepare("SELECT d.*, p.enabled, p.paused, u.status AS user_status, u.auth_source, s.after_school_enabled, s.night_enabled, s.after_school_time, s.night_time FROM notification_deliveries d JOIN notification_preferences p ON p.student_id = d.student_id JOIN users u ON u.id = d.student_id LEFT JOIN notification_schedule s ON s.student_id = d.student_id AND s.day_of_week = WEEKDAY(d.review_date) + 1 WHERE d.id = ?");
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();
    if (!$delivery) throw new RuntimeException('Delivery not found.');
    $type = $delivery['notification_type'];
    $field = $type . '_time';
    $enabledField = $type . '_enabled';
    if ($delivery['user_status'] !== 'active' || !(int)$delivery['enabled'] || (int)$delivery['paused'] || !(int)$delivery[$enabledField] || $delivery[$field] === null) {
        $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = 'skipped', lease_until = NULL, last_error = '通知設定が無効です。' WHERE id = ?");
        $stmt->execute([$deliveryId]);
        return 'skipped';
    }
    if ($delivery['auth_source'] === 'school' && !auth_revalidate_school_user($pdo, (int)$delivery['student_id'], $config)) {
        $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = 'skipped', lease_until = NULL, last_error = '塾の在籍を確認できません。' WHERE id = ?");
        $stmt->execute([$deliveryId]);
        return 'skipped';
    }

    $sessionId = daily_review_session($pdo, (int)$delivery['student_id'], $delivery['review_date'], $type);
    if ($sessionId === null) {
        $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = 'skipped', lease_until = NULL, last_error = '質問対象の教科がありません。' WHERE id = ?");
        $stmt->execute([$deliveryId]);
        return 'skipped';
    }
    $stmt = $pdo->prepare('UPDATE notification_deliveries SET session_id = ? WHERE id = ?');
    $stmt->execute([$sessionId, $deliveryId]);
    $stmt = $pdo->prepare('SELECT ended_at, session_type FROM chat_sessions WHERE id = ?');
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    $alreadyAnswered = false;
    if (($session['session_type'] ?? '') === 'general') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ? AND role = 'user'");
        $stmt->execute([$sessionId]);
        $alreadyAnswered = (int)$stmt->fetchColumn() > 0;
    }
    if ($session['ended_at'] !== null || $alreadyAnswered) {
        $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = 'skipped', lease_until = NULL, last_error = '振り返りは回答済みです。' WHERE id = ?");
        $stmt->execute([$deliveryId]);
        return 'skipped';
    }

    $stmt = $pdo->prepare('SELECT endpoint_hash, endpoint, p256dh, auth, content_encoding FROM push_subscriptions WHERE student_id = ? AND revoked_at IS NULL');
    $stmt->execute([(int)$delivery['student_id']]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) {
        $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = 'skipped', lease_until = NULL, last_error = '有効な購読端末がありません。' WHERE id = ?");
        $stmt->execute([$deliveryId]);
        return 'skipped';
    }
    $baseUrl = rtrim((string)($config['app_base_url'] ?? ''), '/') . '/';
    if (!preg_match('#^https://#', $baseUrl) && !str_starts_with($baseUrl, 'http://localhost/')) throw new RuntimeException('APP_BASE_URL must use HTTPS.');
    $payload = json_encode([
        'title' => $type === 'night' ? '🌙 夜の振り返りの時間です' : '📚 帰宅後の振り返りの時間です',
        'body' => $type === 'night' ? '家で勉強したことを話そう。タップして始める' : '今日の授業で覚えていることを話そう。タップして始める',
        'url' => $baseUrl . '?session=' . $sessionId,
        'tag' => 'manalog-' . $delivery['review_date'] . '-' . $type,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $insertDevice = $pdo->prepare('INSERT IGNORE INTO notification_delivery_devices (delivery_id, endpoint_hash) VALUES (?, ?)');
    $selectDevice = $pdo->prepare('SELECT id, status, attempts FROM notification_delivery_devices WHERE delivery_id = ? AND endpoint_hash = ?');
    $claimDevice = $pdo->prepare("UPDATE notification_delivery_devices SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status IN ('pending','failed') AND attempts < 3");
    foreach ($subscriptions as $subscription) {
        $insertDevice->execute([$deliveryId, $subscription['endpoint_hash']]);
        $selectDevice->execute([$deliveryId, $subscription['endpoint_hash']]);
        $device = $selectDevice->fetch();
        if (!$device || in_array($device['status'], ['sent', 'invalid', 'sending'], true)) continue;
        $claimDevice->execute([$device['id']]);
        if ($claimDevice->rowCount() !== 1) continue;
        try {
            $report = $send($subscription, $payload);
            $deviceStatus = $report['success'] ? 'sent' : ($report['expired'] ? 'invalid' : ($report['retryable'] ? 'failed' : 'invalid'));
            $stmt = $pdo->prepare('UPDATE notification_delivery_devices SET status = ?, sent_at = ?, last_error = ? WHERE id = ?');
            $stmt->execute([$deviceStatus, $report['success'] ? $now->format('Y-m-d H:i:s') : null, $report['error'], $device['id']]);
            if ($report['expired']) {
                $stmt = $pdo->prepare('UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE endpoint_hash = ?');
                $stmt->execute([$subscription['endpoint_hash']]);
            }
        } catch (Throwable $e) {
            // An unknown transport result is not retried: the push service may have accepted it.
            $stmt = $pdo->prepare("UPDATE notification_delivery_devices SET status = 'invalid', last_error = ? WHERE id = ?");
            $stmt->execute([mb_substr($e->getMessage(), 0, 1000), $device['id']]);
        }
    }
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS count FROM notification_delivery_devices WHERE delivery_id = ? GROUP BY status');
    $stmt->execute([$deliveryId]);
    $statuses = array_column($stmt->fetchAll(), 'count', 'status');
    $pending = (int)($statuses['pending'] ?? 0) + (int)($statuses['failed'] ?? 0);
    $sent = (int)($statuses['sent'] ?? 0);
    $status = $pending ? 'failed' : ($sent ? 'sent' : 'failed');
    $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = ?, lease_until = NULL, sent_at = ?, last_error = ?, resend_due_at = IF(? = 'sent' AND resend_status IS NULL, DATE_ADD(?, INTERVAL 60 MINUTE), resend_due_at), resend_status = IF(? = 'sent' AND resend_status IS NULL, 'pending', resend_status) WHERE id = ?");
    $stmt->execute([$status, $sent ? $now->format('Y-m-d H:i:s') : null, $status === 'failed' ? '一部の端末への送信に失敗しました。' : null, $status, $now->format('Y-m-d H:i:s'), $status, $deliveryId]);
    return $status;
}

/** Queue one onboarding reminder per student per day at 19:00 JST, after Push is enabled. */
function queue_due_onboarding_reminders(PDO $pdo, DateTimeImmutable $now): int {
    $clock = (int)$now->format('G');
    if ($clock < 19 || $clock >= 21) return 0;
    $stmt = $pdo->query("SELECT p.user_id FROM student_profiles p JOIN users u ON u.id = p.user_id JOIN notification_preferences n ON n.student_id = p.user_id WHERE p.onboarding_completed_at IS NULL AND u.role = 'student' AND u.status = 'active' AND n.enabled = 1 AND n.paused = 0 AND EXISTS (SELECT 1 FROM push_subscriptions s WHERE s.student_id = p.user_id AND s.revoked_at IS NULL)");
    $insert = $pdo->prepare("INSERT IGNORE INTO onboarding_reminder_deliveries (student_id, reminder_date, due_at) VALUES (?, ?, ?)");
    $skipCollision = $pdo->prepare("INSERT IGNORE INTO onboarding_reminder_deliveries (student_id, reminder_date, due_at, status, last_error) VALUES (?, ?, ?, 'skipped', '通常の振り返り通知と2時間以内のため省略しました。')");
    $date = $now->format('Y-m-d');
    $dueAt = $date . ' 19:00:00';
    $queued = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
        $params = [(int)$studentId, $date, $dueAt];
        if (onboarding_reminder_schedule_collision($pdo, (int)$studentId, new DateTimeImmutable($dueAt, new DateTimeZone('Asia/Tokyo')))) {
            $skipCollision->execute($params);
        } else {
            $insert->execute($params);
            $queued += $insert->rowCount();
        }
    }
    return $queued;
}

function onboarding_reminder_schedule_collision(PDO $pdo, int $studentId, DateTimeImmutable $reminderDue): bool {
    $stmt = $pdo->prepare('SELECT day_of_week, after_school_time, after_school_enabled, night_time, night_enabled FROM notification_schedule WHERE student_id = ?');
    $stmt->execute([$studentId]);
    $schedules = $stmt->fetchAll();
    foreach ([$reminderDue->modify('-1 day'), $reminderDue, $reminderDue->modify('+1 day')] as $serviceDay) {
        foreach ($schedules as $schedule) {
            if ((int)$schedule['day_of_week'] !== (int)$serviceDay->format('N')) continue;
            foreach (['after_school' => 'after_school_time', 'night' => 'night_time'] as $type => $timeColumn) {
                if (!(int)$schedule[$type . '_enabled'] || $schedule[$timeColumn] === null) continue;
                $scheduledAt = notification_due_at($serviceDay->format('Y-m-d'), (string)$schedule[$timeColumn]);
                if (abs($scheduledAt->getTimestamp() - $reminderDue->getTimestamp()) <= 7200) return true;
            }
        }
    }
    return false;
}

/** Send incomplete setup reminders once a day, with per-device retries and a two-hour send window. */
function send_due_onboarding_reminders(PDO $pdo, array $config, DateTimeImmutable $now, ?callable $send = null): array {
    $queued = queue_due_onboarding_reminders($pdo, $now);
    $nowText = $now->format('Y-m-d H:i:s');
    $oldest = $now->modify('-2 hours')->format('Y-m-d H:i:s');
    $leaseText = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
    $expire = $pdo->prepare("UPDATE onboarding_reminder_deliveries SET status = 'skipped', last_error = '送信時間を過ぎたため省略しました。' WHERE status = 'pending' AND due_at < ?");
    $expire->execute([$oldest]);
    $stmt = $pdo->prepare("SELECT id FROM onboarding_reminder_deliveries WHERE due_at BETWEEN ? AND ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?)) ORDER BY due_at, id LIMIT 100");
    $stmt->execute([$oldest, $nowText, $nowText]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts = ['queued' => $queued, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $claim = $pdo->prepare("UPDATE onboarding_reminder_deliveries SET status = 'sending', attempts = attempts + 1, lease_until = ? WHERE id = ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?))");
    foreach ($ids as $id) {
        $claim->execute([$leaseText, $id, $nowText]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $send ??= push_sender($config);
            $result = send_one_onboarding_reminder($pdo, $config, (int)$id, $send, $now);
            $counts[$result]++;
        } catch (Throwable $e) {
            $update = $pdo->prepare("UPDATE onboarding_reminder_deliveries SET status = 'failed', lease_until = NULL, last_error = ? WHERE id = ?");
            $update->execute([mb_substr($e->getMessage(), 0, 1000), $id]);
            $counts['failed']++;
        }
    }
    return $counts;
}

function send_one_onboarding_reminder(PDO $pdo, array $config, int $deliveryId, callable $send, DateTimeImmutable $now): string {
    $stmt = $pdo->prepare('SELECT d.*, u.status AS user_status, u.auth_source, p.onboarding_step, p.onboarding_completed_at, n.enabled, n.paused FROM onboarding_reminder_deliveries d JOIN users u ON u.id = d.student_id JOIN student_profiles p ON p.user_id = d.student_id JOIN notification_preferences n ON n.student_id = d.student_id WHERE d.id = ?');
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();
    if (!$delivery) throw new RuntimeException('Onboarding reminder not found.');
    if ($delivery['onboarding_completed_at'] !== null) return finish_onboarding_reminder($pdo, $deliveryId, 'skipped', '初期設定が完了しています。');
    if ($delivery['user_status'] !== 'active' || !(int)$delivery['enabled'] || (int)$delivery['paused']) return finish_onboarding_reminder($pdo, $deliveryId, 'skipped', 'アカウントまたは通知設定が無効です。');
    if ($delivery['auth_source'] === 'school' && !auth_revalidate_school_user($pdo, (int)$delivery['student_id'], $config)) return finish_onboarding_reminder($pdo, $deliveryId, 'skipped', '塾の在籍を確認できません。');

    $stmt = $pdo->prepare('SELECT endpoint_hash, endpoint, p256dh, auth, content_encoding FROM push_subscriptions WHERE student_id = ? AND revoked_at IS NULL');
    $stmt->execute([(int)$delivery['student_id']]);
    $subscriptions = $stmt->fetchAll();
    if (!$subscriptions) return finish_onboarding_reminder($pdo, $deliveryId, 'skipped', '有効な購読端末がありません。');

    $baseUrl = rtrim((string)($config['app_base_url'] ?? ''), '/') . '/';
    if (!preg_match('#^https://#', $baseUrl) && !str_starts_with($baseUrl, 'http://localhost/')) throw new RuntimeException('APP_BASE_URL must use HTTPS.');
    $stepLabels = [1 => 'ホーム画面と通知', 2 => '時間割', 3 => '質問しない教科', 4 => '通知希望時刻'];
    $step = max(1, min(4, (int)$delivery['onboarding_step']));
    $payload = json_encode([
        'title' => 'まなログの初期設定',
        'body' => ($stepLabels[$step] ?? '初期設定') . 'の設定が途中です。タップして続きから設定しよう。',
        'url' => $baseUrl,
        'tag' => 'manalog-onboarding-reminder',
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $insertDevice = $pdo->prepare('INSERT IGNORE INTO onboarding_reminder_devices (delivery_id, endpoint_hash) VALUES (?, ?)');
    $selectDevice = $pdo->prepare('SELECT id, status, attempts, lease_until FROM onboarding_reminder_devices WHERE delivery_id = ? AND endpoint_hash = ?');
    $claimDevice = $pdo->prepare("UPDATE onboarding_reminder_devices SET status = 'sending', attempts = attempts + 1, lease_until = ? WHERE id = ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?))");
    $deviceLease = $now->modify('+5 minutes')->format('Y-m-d H:i:s');
    $nowText = $now->format('Y-m-d H:i:s');
    foreach ($subscriptions as $subscription) {
        $insertDevice->execute([$deliveryId, $subscription['endpoint_hash']]);
        $selectDevice->execute([$deliveryId, $subscription['endpoint_hash']]);
        $device = $selectDevice->fetch();
        if (!$device || in_array($device['status'], ['sent', 'invalid'], true)) continue;
        $claimDevice->execute([$deviceLease, $device['id'], $nowText]);
        if ($claimDevice->rowCount() !== 1) continue;
        try {
            $report = $send($subscription, $payload);
            $deviceStatus = $report['success'] ? 'sent' : ($report['expired'] ? 'invalid' : (!empty($report['retryable']) ? 'failed' : 'invalid'));
            $update = $pdo->prepare('UPDATE onboarding_reminder_devices SET status = ?, lease_until = NULL, sent_at = ?, last_error = ? WHERE id = ?');
            $update->execute([$deviceStatus, $report['success'] ? $now->format('Y-m-d H:i:s') : null, $report['error'] ?? null, $device['id']]);
            if (!empty($report['expired'])) $pdo->prepare('UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE endpoint_hash = ?')->execute([$subscription['endpoint_hash']]);
        } catch (Throwable $e) {
            $update = $pdo->prepare("UPDATE onboarding_reminder_devices SET status = 'invalid', lease_until = NULL, last_error = ? WHERE id = ?");
            $update->execute([mb_substr($e->getMessage(), 0, 1000), $device['id']]);
        }
    }
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS count FROM onboarding_reminder_devices WHERE delivery_id = ? GROUP BY status');
    $stmt->execute([$deliveryId]);
    $statuses = array_column($stmt->fetchAll(), 'count', 'status');
    $pending = (int)($statuses['pending'] ?? 0) + (int)($statuses['failed'] ?? 0);
    $sent = (int)($statuses['sent'] ?? 0);
    $status = $pending ? 'failed' : ($sent ? 'sent' : 'failed');
    $error = $status === 'failed' ? 'Push送信に失敗しました。' : null;
    $update = $pdo->prepare('UPDATE onboarding_reminder_deliveries SET status = ?, lease_until = NULL, sent_at = ?, last_error = ? WHERE id = ?');
    $update->execute([$status, $sent ? $now->format('Y-m-d H:i:s') : null, $error, $deliveryId]);
    return $status;
}

function finish_onboarding_reminder(PDO $pdo, int $deliveryId, string $status, ?string $error): string {
    $stmt = $pdo->prepare('UPDATE onboarding_reminder_deliveries SET status = ?, lease_until = NULL, last_error = ? WHERE id = ?');
    $stmt->execute([$status, $error, $deliveryId]);
    return $status;
}
