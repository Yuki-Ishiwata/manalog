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
    $stmt = $pdo->query("SELECT s.student_id, s.day_of_week, s.after_school_time, s.after_school_enabled, s.night_time, s.night_enabled FROM notification_schedule s JOIN notification_preferences p ON p.student_id = s.student_id JOIN users u ON u.id = s.student_id WHERE u.status = 'active' AND p.paused = 0 AND (EXISTS (SELECT 1 FROM push_subscriptions sub WHERE sub.student_id = s.student_id AND sub.revoked_at IS NULL) OR (u.auth_source = 'school' AND EXISTS (SELECT 1 FROM student_school_links l WHERE l.user_id = u.id)))");
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
    if ($send === null) {
        $send = static function (array $row, string $payload) use ($config): array {
            static $sender = null;
            $sender ??= push_sender($config);
            return $sender($row, $payload);
        };
    }
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
    $stmt = $pdo->prepare("SELECT id FROM notification_deliveries WHERE delivery_channel = 'push' AND status = 'sent' AND resend_status IN ('pending','sending') AND resend_due_at <= ? AND (resend_lease_until IS NULL OR resend_lease_until < ?) ORDER BY resend_due_at, id LIMIT 100");
    $stmt->execute([$nowText, $nowText]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $counts = ['resent' => 0, 'resend_skipped' => 0, 'resend_failed' => 0];
    $claim = $pdo->prepare("UPDATE notification_deliveries SET resend_status = 'sending', resend_lease_until = ? WHERE id = ? AND delivery_channel = 'push' AND status = 'sent' AND resend_status IN ('pending','sending') AND resend_due_at <= ? AND (resend_lease_until IS NULL OR resend_lease_until < ?)");
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

function notification_school_contacts(PDO $pdo, array $config, int $studentId): ?array {
    $link = $pdo->prepare('SELECT school_student_id FROM student_school_links WHERE user_id = ? AND school_provider = ? LIMIT 1');
    $link->execute([$studentId, auth_provider_id($config)]);
    $schoolStudentId = $link->fetchColumn();
    if (!$schoolStudentId) return null;

    $school = school_db();
    $member = [];
    $emailLookupError = null;
    try {
        $memberStmt = $school->prepare('SELECT email, email_oshirase_flg FROM kaiin WHERE id = ? AND delete_flg = 0 LIMIT 1');
        $memberStmt->execute([(int)$schoolStudentId]);
        $member = $memberStmt->fetch() ?: [];
    } catch (Throwable $e) {
        $emailLookupError = '塾DBのメール連絡先を参照できません。';
    }
    $lineIds = [];
    $lineLookupError = null;
    try {
        $lineStmt = $school->prepare('SELECT DISTINCT line_user_id FROM line_user_id WHERE kaiin_id = ? AND line_user_id <> ?');
        $lineStmt->execute([(int)$schoolStudentId, '']);
        $lineIds = array_values(array_unique(array_filter(array_map('strval', $lineStmt->fetchAll(PDO::FETCH_COLUMN)))));
    } catch (Throwable $e) {
        $lineLookupError = '塾DBのLINE連携先を参照できません。';
    }
    $email = trim((string)($member['email'] ?? ''));
    return [
        'line_ids' => $lineIds,
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'email_allowed' => (int)($member['email_oshirase_flg'] ?? 0) === 1,
        'email_lookup_error' => $emailLookupError,
        'line_lookup_error' => $lineLookupError,
    ];
}

function notification_external_retry_key(int $deliveryId, string $recipientHash): string {
    $hex = substr(hash('sha256', 'manalog-line-' . $deliveryId . '-' . $recipientHash), 0, 32);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
}

/** Claims one external recipient and records its result without storing its address or LINE ID. */
function notification_send_external_recipient(PDO $pdo, int $deliveryId, string $channel, string $recipientHash, DateTimeImmutable $now, callable $send): array {
    $pdo->prepare('INSERT IGNORE INTO notification_delivery_external (delivery_id, channel, recipient_hash) VALUES (?, ?, ?)')->execute([$deliveryId, $channel, $recipientHash]);
    $stmt = $pdo->prepare('SELECT id, status, attempts, lease_until FROM notification_delivery_external WHERE delivery_id = ? AND channel = ? AND recipient_hash = ?');
    $stmt->execute([$deliveryId, $channel, $recipientHash]);
    $row = $stmt->fetch();
    if (!$row || in_array($row['status'], ['sent', 'invalid'], true) || (int)$row['attempts'] >= 3) return $row ?: ['status' => 'invalid', 'attempts' => 3];
    if ($row['status'] === 'sending' && $row['lease_until'] !== null && $row['lease_until'] >= $now->format('Y-m-d H:i:s')) return $row;

    $claim = $pdo->prepare("UPDATE notification_delivery_external SET status = 'sending', attempts = attempts + 1, lease_until = ? WHERE id = ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?))");
    $claim->execute([$now->modify('+5 minutes')->format('Y-m-d H:i:s'), (int)$row['id'], $now->format('Y-m-d H:i:s')]);
    if ($claim->rowCount() !== 1) {
        $stmt->execute([$deliveryId, $channel, $recipientHash]);
        return $stmt->fetch() ?: ['status' => 'invalid', 'attempts' => 3];
    }

    try {
        $result = $send();
    } catch (Throwable $e) {
        $result = ['success' => false, 'retryable' => true, 'error' => $e->getMessage()];
    }
    $attempt = (int)$row['attempts'] + 1;
    $status = !empty($result['success']) ? 'sent' : (!empty($result['retryable']) ? 'failed' : 'invalid');
    $update = $pdo->prepare('UPDATE notification_delivery_external SET status = ?, lease_until = NULL, sent_at = ?, last_error = ? WHERE id = ?');
    $update->execute([$status, $status === 'sent' ? $now->format('Y-m-d H:i:s') : null, $status === 'sent' ? null : mb_substr((string)($result['error'] ?? '外部通知に失敗しました。'), 0, 1000), (int)$row['id']]);
    return ['status' => $status, 'attempts' => $attempt, 'last_error' => $status === 'sent' ? null : (string)($result['error'] ?? '外部通知に失敗しました。')];
}

function notification_send_line_message(array $config, string $lineUserId, string $message, int $deliveryId, string $recipientHash): array {
    $token = trim((string)($config['line_channel_access_token'] ?? ''));
    if ($token === '') return ['success' => false, 'retryable' => false, 'error' => 'LINE_CHANNEL_ACCESS_TOKENが設定されていません。'];
    if (!function_exists('curl_init')) return ['success' => false, 'retryable' => false, 'error' => 'cURLが利用できません。'];
    $payload = json_encode(['to' => $lineUserId, 'messages' => [['type' => 'text', 'text' => $message]]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=utf-8',
            'X-Line-Retry-Key: ' . notification_external_retry_key($deliveryId, $recipientHash),
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status === 409) return ['success' => true]; // LINE accepted an earlier request with this retry key.
    if ($response !== false && $status >= 200 && $status < 300) return ['success' => true];
    return [
        'success' => false,
        'retryable' => $response === false || $status === 0 || $status === 429 || $status >= 500,
        'error' => $response === false ? ($error ?: 'LINE APIへの接続に失敗しました。') : 'LINE API HTTP ' . $status,
    ];
}

function notification_send_email_message(array $config, string $email, string $message): array {
    if (!function_exists('mb_send_mail')) return ['success' => false, 'retryable' => false, 'error' => 'mb_send_mailが利用できません。'];
    $from = trim((string)($config['manalog_mail_from'] ?? 'info@ishiwata-juku.com'));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || str_contains($from, "\r") || str_contains($from, "\n")) return ['success' => false, 'retryable' => false, 'error' => 'MANALOG_MAIL_FROMの設定が正しくありません。'];
    mb_language('Japanese');
    mb_internal_encoding('UTF-8');
    $headers = 'From: ' . $from . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = mb_send_mail($email, 'まなログ：Push通知設定と今日の振り返り', $message, $headers, '-f' . $from);
    return $sent ? ['success' => true] : ['success' => false, 'retryable' => true, 'error' => 'メール送信に失敗しました。'];
}

function notification_finish_external_delivery(PDO $pdo, int $deliveryId, string $status, ?string $channel, ?string $error, DateTimeImmutable $now): string {
    if (!in_array($status, ['sent', 'failed', 'skipped'], true)) throw new InvalidArgumentException('Invalid external notification status.');
    $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = ?, delivery_channel = COALESCE(?, delivery_channel), lease_until = NULL, sent_at = ?, last_error = ?, resend_due_at = NULL, resend_status = IF(resend_status IS NULL, 'skipped', resend_status) WHERE id = ?");
    $stmt->execute([$status, $channel, $status === 'sent' ? $now->format('Y-m-d H:i:s') : null, $error === null ? null : mb_substr($error, 0, 1000), $deliveryId]);
    return $status;
}

/** LINE is preferred; registered email is used when there is no usable LINE recipient. */
function send_school_notification_fallback(PDO $pdo, array $config, array $delivery, int $sessionId, DateTimeImmutable $now): string {
    $deliveryId = (int)$delivery['id'];
    $studentId = (int)$delivery['student_id'];
    $contacts = notification_school_contacts($pdo, $config, $studentId);
    if ($contacts === null) return notification_finish_external_delivery($pdo, $deliveryId, 'skipped', null, '塾DBと連携された通知先がありません。', $now);

    $baseUrl = rtrim((string)($config['app_base_url'] ?? ''), '/') . '/';
    if (!preg_match('#^https://#', $baseUrl) && !str_starts_with($baseUrl, 'http://localhost/')) throw new RuntimeException('APP_BASE_URL must use HTTPS.');
    $type = (string)$delivery['notification_type'];
    $label = $type === 'night' ? '夜の振り返り' : '帰宅後の振り返り';
    $settingsUrl = $baseUrl . '#notification-settings';
    $reviewPrompt = $type === 'night'
        ? '今日、家で勉強したことをまなログに登録してください。'
        : '今日、学校で習ったことをまなログに登録してください。';
    $message = "まなログからのお知らせ\n\n"
        . "次回から通知を受け取れるよう、まなログの「設定」→「通知希望時刻・Push通知」から、この端末のPush通知を有効にしてください。\n"
        . $settingsUrl . "\n\n"
        . $reviewPrompt . "\n"
        . $label . "はこちらから始められます。\n"
        . $baseUrl . '?session=' . $sessionId;
    $preferredChannel = in_array((string)($delivery['delivery_channel'] ?? ''), ['line', 'email'], true) ? (string)$delivery['delivery_channel'] : '';
    $lineTokenAvailable = trim((string)($config['line_channel_access_token'] ?? '')) !== '';
    $lineSent = 0;
    $linePending = false;
    $lineErrors = [];

    if ($preferredChannel !== 'email' && $lineTokenAvailable && $contacts['line_ids']) {
        foreach ($contacts['line_ids'] as $lineUserId) {
            $recipientHash = hash('sha256', 'line:' . $lineUserId);
            $result = notification_send_external_recipient($pdo, $deliveryId, 'line', $recipientHash, $now, static fn(): array => notification_send_line_message($config, $lineUserId, $message, $deliveryId, $recipientHash));
            if ($result['status'] === 'sent') $lineSent++;
            elseif ($result['status'] === 'failed' && (int)$result['attempts'] < 3) $linePending = true;
            elseif (!empty($result['last_error'])) $lineErrors[] = (string)$result['last_error'];
        }
    }

    $alreadySentLine = $pdo->prepare("SELECT COUNT(*) FROM notification_delivery_external WHERE delivery_id = ? AND channel = 'line' AND status = 'sent'");
    $alreadySentLine->execute([$deliveryId]);
    $lineSent = max($lineSent, (int)$alreadySentLine->fetchColumn());
    if ($linePending && $lineSent > 0 && (int)$delivery['attempts'] < 3) {
        return notification_finish_external_delivery($pdo, $deliveryId, 'failed', 'line', '一部のLINE連絡先への送信を再試行します。', $now);
    }
    if ($lineSent > 0) {
        $error = $lineErrors ? '一部のLINE連絡先への送信に失敗しました。' : null;
        return notification_finish_external_delivery($pdo, $deliveryId, 'sent', 'line', $error, $now);
    }

    if ($contacts['email_allowed'] && $contacts['email'] !== '') {
        $recipientHash = hash('sha256', 'email:' . mb_strtolower($contacts['email'], 'UTF-8'));
        $result = notification_send_external_recipient($pdo, $deliveryId, 'email', $recipientHash, $now, static fn(): array => notification_send_email_message($config, $contacts['email'], $message));
        if ($result['status'] === 'sent') return notification_finish_external_delivery($pdo, $deliveryId, 'sent', 'email', null, $now);
        if ($result['status'] === 'failed' && (int)$result['attempts'] < 3) {
            return notification_finish_external_delivery($pdo, $deliveryId, 'failed', 'email', (string)($result['last_error'] ?? 'メール通知を再試行します。'), $now);
        }
        return notification_finish_external_delivery($pdo, $deliveryId, 'failed', 'email', (string)($result['last_error'] ?? 'メール通知に失敗しました。'), $now);
    }

    if ($linePending) return notification_finish_external_delivery($pdo, $deliveryId, 'failed', 'line', 'LINE通知を再試行します。', $now);
    $reason = $lineErrors ? implode(' / ', $lineErrors) : ($contacts['line_lookup_error'] ?? $contacts['email_lookup_error'] ?? 'LINE送信先またはメール配信可能な連絡先がありません。');
    return notification_finish_external_delivery($pdo, $deliveryId, 'skipped', null, $reason, $now);
}

function send_one_delivery(PDO $pdo, array $config, int $deliveryId, callable $send, DateTimeImmutable $now): string {
    $stmt = $pdo->prepare("SELECT d.*, p.enabled, p.paused, u.status AS user_status, u.auth_source, s.after_school_enabled, s.night_enabled, s.after_school_time, s.night_time FROM notification_deliveries d JOIN notification_preferences p ON p.student_id = d.student_id JOIN users u ON u.id = d.student_id LEFT JOIN notification_schedule s ON s.student_id = d.student_id AND s.day_of_week = WEEKDAY(d.review_date) + 1 WHERE d.id = ?");
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();
    if (!$delivery) throw new RuntimeException('Delivery not found.');
    $type = $delivery['notification_type'];
    $field = $type . '_time';
    $enabledField = $type . '_enabled';
    if ($delivery['user_status'] !== 'active' || (int)$delivery['paused'] || !(int)$delivery[$enabledField] || $delivery[$field] === null) {
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
    if (in_array((string)$delivery['delivery_channel'], ['line', 'email'], true) || !(int)$delivery['enabled'] || !$subscriptions) return send_school_notification_fallback($pdo, $config, $delivery, $sessionId, $now);
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
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS count, SUM(attempts < 3) AS retryable_count FROM notification_delivery_devices WHERE delivery_id = ? GROUP BY status');
    $stmt->execute([$deliveryId]);
    $statuses = [];
    foreach ($stmt->fetchAll() as $deviceStatus) $statuses[$deviceStatus['status']] = $deviceStatus;
    $pending = (int)($statuses['pending']['count'] ?? 0) + (int)($statuses['failed']['retryable_count'] ?? 0);
    $sent = (int)($statuses['sent']['count'] ?? 0);
    if (!$pending && !$sent) return send_school_notification_fallback($pdo, $config, $delivery, $sessionId, $now);
    $status = $pending ? 'failed' : 'sent';
    $stmt = $pdo->prepare("UPDATE notification_deliveries SET status = ?, delivery_channel = 'push', lease_until = NULL, sent_at = ?, last_error = ?, resend_due_at = IF(? = 'sent' AND resend_status IS NULL, DATE_ADD(?, INTERVAL 60 MINUTE), resend_due_at), resend_status = IF(? = 'sent' AND resend_status IS NULL, 'pending', resend_status) WHERE id = ?");
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
