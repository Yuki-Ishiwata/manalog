<?php
declare(strict_types=1);

function telegram_learning_summary_configured(array $config): bool {
    return trim((string)($config['telegram_bot_token'] ?? '')) !== ''
        && trim((string)($config['telegram_chat_id'] ?? '')) !== '';
}

function telegram_queue_learning_summary(PDO $pdo, array $config, int $studentId, int $sessionId): void {
    if (!telegram_learning_summary_configured($config)) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO telegram_learning_summary_jobs (student_id, session_id) SELECT ?, s.id FROM chat_sessions s JOIN users u ON u.id = s.student_id WHERE s.id = ? AND s.student_id = ? AND s.ended_at IS NOT NULL AND s.session_type IN ('after_school','night') AND u.role = 'student'");
    $stmt->execute([$studentId, $sessionId, $studentId]);
}

function telegram_learning_summary_text(PDO $pdo, int $studentId, int $sessionId): string {
    $stmt = $pdo->prepare("SELECT s.session_date, s.session_type, p.display_name, u.login_id, o.name AS organization_name FROM chat_sessions s JOIN users u ON u.id = s.student_id JOIN organizations o ON o.id = u.organization_id LEFT JOIN student_profiles p ON p.user_id = u.id WHERE s.id = ? AND s.student_id = ? AND s.ended_at IS NOT NULL AND s.session_type IN ('after_school','night') AND u.role = 'student' AND u.status = 'active' AND o.status = 'active'");
    $stmt->execute([$sessionId, $studentId]);
    $session = $stmt->fetch();
    if (!$session) throw new RuntimeException('Telegram通知の対象を確認できません。');

    $recordsStmt = $pdo->prepare('SELECT subject, unit_name, summary, study_minutes, not_understood, next_check FROM learning_records WHERE student_id = ? AND source_session_id = ? ORDER BY id LIMIT 20');
    $recordsStmt->execute([$studentId, $sessionId]);
    $records = $recordsStmt->fetchAll();
    $typeLabel = $session['session_type'] === 'night' ? '夜の振り返り' : '帰宅後の振り返り';
    $lines = [
        'まなログ 学習サマリー',
        '生徒: ' . mb_substr((string)($session['display_name'] ?: $session['login_id']), 0, 80),
        '塾: ' . mb_substr((string)$session['organization_name'], 0, 100),
        '日付: ' . (string)$session['session_date'] . ' · ' . $typeLabel,
    ];
    if (!$records) {
        $lines[] = '学習記録: 今回は抽出された記録がありません。';
    } else {
        foreach ($records as $index => $record) {
            $title = trim(implode(' · ', array_filter([(string)($record['subject'] ?? ''), (string)($record['unit_name'] ?? '')]))) ?: '科目・単元未特定';
            $lines[] = '';
            $lines[] = ($index + 1) . '. ' . mb_substr($title, 0, 160);
            if (trim((string)($record['summary'] ?? '')) !== '') $lines[] = '学習内容: ' . mb_substr(trim((string)$record['summary']), 0, 700);
            if ($record['study_minutes'] !== null) $lines[] = '学習時間: ' . (int)$record['study_minutes'] . '分';
            if (trim((string)($record['not_understood'] ?? '')) !== '') $lines[] = 'まだ分からない: ' . mb_substr(trim((string)$record['not_understood']), 0, 300);
            if (trim((string)($record['next_check'] ?? '')) !== '') $lines[] = '次に確認: ' . mb_substr(trim((string)$record['next_check']), 0, 300);
        }
    }
    return mb_substr(implode("\n", $lines), 0, 3900);
}

function process_telegram_learning_summary_jobs(PDO $pdo, array $config, int $limit = 10): array {
    $counts = ['sent' => 0, 'failed' => 0, 'disabled' => 0];
    if (!telegram_learning_summary_configured($config)) {
        $counts['disabled'] = 1;
        return $counts;
    }
    if (!function_exists('curl_init')) throw new RuntimeException('cURL is required for Telegram delivery.');

    for ($i = 0; $i < max(1, min(20, $limit)); $i++) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query("SELECT id, student_id, session_id, attempts FROM telegram_learning_summary_jobs WHERE attempts < 5 AND (status = 'pending' OR (status = 'failed' AND next_attempt_at <= NOW()) OR (status = 'sending' AND lease_until < NOW())) ORDER BY id LIMIT 1 FOR UPDATE");
            $job = $stmt->fetch();
            if (!$job) { $pdo->commit(); break; }
            $claim = $pdo->prepare("UPDATE telegram_learning_summary_jobs SET status = 'sending', attempts = attempts + 1, lease_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE), next_attempt_at = NULL, last_error = NULL WHERE id = ?");
            $claim->execute([(int)$job['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            $text = telegram_learning_summary_text($pdo, (int)$job['student_id'], (int)$job['session_id']);
            $payload = json_encode(['chat_id' => (string)$config['telegram_chat_id'], 'text' => $text, 'disable_web_page_preview' => true], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $ch = curl_init('https://api.telegram.org/bot' . trim((string)$config['telegram_bot_token']) . '/sendMessage');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
            $raw = curl_exec($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $response = is_string($raw) ? json_decode($raw, true) : null;
            if ($raw === false || $httpStatus < 200 || $httpStatus >= 300 || !is_array($response) || empty($response['ok'])) {
                throw new RuntimeException('Telegram API returned an unsuccessful response (HTTP ' . $httpStatus . ').');
            }
            $done = $pdo->prepare("UPDATE telegram_learning_summary_jobs SET status = 'sent', sent_at = NOW(), lease_until = NULL, next_attempt_at = NULL, last_error = NULL WHERE id = ? AND status = 'sending'");
            $done->execute([(int)$job['id']]);
            $counts['sent']++;
        } catch (Throwable $e) {
            $attempt = (int)$job['attempts'] + 1;
            $delay = min(60, 5 * $attempt);
            $failed = $pdo->prepare("UPDATE telegram_learning_summary_jobs SET status = 'failed', lease_until = NULL, next_attempt_at = IF(attempts < 5, DATE_ADD(NOW(), INTERVAL {$delay} MINUTE), NULL), last_error = ? WHERE id = ? AND status = 'sending'");
            $failed->execute([mb_substr($e->getMessage(), 0, 500), (int)$job['id']]);
            $counts['failed']++;
            error_log('ManaLog Telegram summary delivery failed: job=' . (int)$job['id'] . ' error=' . get_class($e));
        }
    }
    return $counts;
}
