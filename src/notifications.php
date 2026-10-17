<?php
declare(strict_types=1);

require_once __DIR__ . '/reviews.php';

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
    $counts = ['queued' => $queued, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
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
    return $counts;
}

function send_one_delivery(PDO $pdo, array $config, int $deliveryId, callable $send, DateTimeImmutable $now): string {
    $stmt = $pdo->prepare("SELECT d.*, p.enabled, p.paused, u.status AS user_status, s.after_school_enabled, s.night_enabled, s.after_school_time, s.night_time FROM notification_deliveries d JOIN notification_preferences p ON p.student_id = d.student_id JOIN users u ON u.id = d.student_id LEFT JOIN notification_schedule s ON s.student_id = d.student_id AND s.day_of_week = WEEKDAY(d.review_date) + 1 WHERE d.id = ?");
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
        'title' => $type === 'night' ? 'まなログ・夜の振り返り' : 'まなログ・帰宅後の振り返り',
        'body' => $type === 'night' ? '家での勉強を、少しだけ振り返ろう。' : '今日の学校の勉強を、少しだけ振り返ろう。',
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
    $stmt = $pdo->prepare('UPDATE notification_deliveries SET status = ?, lease_until = NULL, sent_at = ?, last_error = ? WHERE id = ?');
    $stmt->execute([$status, $sent ? $now->format('Y-m-d H:i:s') : null, $status === 'failed' ? '一部の端末への送信に失敗しました。' : null, $deliveryId]);
    return $status;
}
