<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'POSTを使用してください。']); exit; }

$appRoot = dirname(__DIR__);
$appRootFile = __DIR__ . '/app_root.local.php';
if (is_file($appRootFile)) $appRoot = require $appRootFile;
require $appRoot . '/src/bootstrap.php';
require_once $appRoot . '/src/auth.php';

session_name('manalog_session');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();

function push_response(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'student' || empty($_SESSION['user_id'])) push_response(401, ['error' => 'ログインしてください。']);
if (!hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) push_response(403, ['error' => '画面を更新して、もう一度お試しください。']);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16000) push_response(413, ['error' => '購読情報が大きすぎます。']);
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) push_response(400, ['error' => '送信内容を確認してください。']);

try {
    $pdo = db();
    $studentId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT password_hash, auth_source FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
    $stmt->execute([$studentId]);
    $account = $stmt->fetch();
    if (!$account) push_response(403, ['error' => 'アカウントを利用できません。']);
    if ($account['auth_source'] === 'school' && !auth_revalidate_school_user($pdo, $studentId, $config)) push_response(403, ['error' => '塾の在籍を確認できません。']);
    if ($account['auth_source'] === 'local' && password_verify('0000', (string)$account['password_hash'])) push_response(403, ['error' => '先にパスワードを変更してください。']);

    $action = $data['action'] ?? '';
    $subscription = $data['subscription'] ?? null;
    if (!is_array($subscription)) push_response(400, ['error' => '購読情報を確認してください。']);
    $endpoint = $subscription['endpoint'] ?? null;
    if (!is_string($endpoint) || strlen($endpoint) > 2048 || !filter_var($endpoint, FILTER_VALIDATE_URL)) push_response(400, ['error' => '購読先を確認してください。']);
    $parts = parse_url($endpoint);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com' || $host === 'web.push.apple.com' || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
    if (($parts['scheme'] ?? '') !== 'https' || !$allowed || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) push_response(400, ['error' => '対応していない通知サービスです。']);
    $endpointHash = hash('sha256', $endpoint);

    if ($action === 'subscribe') {
        $keys = $subscription['keys'] ?? [];
        $p256dh = $keys['p256dh'] ?? null;
        $auth = $keys['auth'] ?? null;
        if (!is_string($p256dh) || !is_string($auth) || !preg_match('/^[A-Za-z0-9_-]{80,120}$/', $p256dh) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $auth)) push_response(400, ['error' => '通知用の鍵を確認してください。']);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO push_subscriptions (student_id, endpoint_hash, endpoint, p256dh, auth, content_encoding) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE student_id = VALUES(student_id), endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth), content_encoding = VALUES(content_encoding), revoked_at = NULL');
        $stmt->execute([$studentId, $endpointHash, $endpoint, $p256dh, $auth, 'aes128gcm']);
        $stmt = $pdo->prepare('UPDATE notification_preferences SET enabled = 1 WHERE student_id = ?');
        $stmt->execute([$studentId]);
        $pdo->commit();
        push_response(200, ['ok' => true, 'push_ready' => true]);
    }
    if ($action === 'unsubscribe') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE student_id = ? AND endpoint_hash = ? AND revoked_at IS NULL');
        $stmt->execute([$studentId, $endpointHash]);
        $stmt = $pdo->prepare('UPDATE notification_preferences SET enabled = 0 WHERE student_id = ? AND NOT EXISTS (SELECT 1 FROM push_subscriptions WHERE student_id = ? AND revoked_at IS NULL)');
        $stmt->execute([$studentId, $studentId]);
        $pdo->commit();
        $stmt = $pdo->prepare('SELECT p.enabled = 1 AND EXISTS (SELECT 1 FROM push_subscriptions s WHERE s.student_id = p.student_id AND s.revoked_at IS NULL) FROM notification_preferences p WHERE p.student_id = ?');
        $stmt->execute([$studentId]);
        push_response(200, ['ok' => true, 'push_ready' => (bool)$stmt->fetchColumn()]);
    }
    push_response(400, ['error' => '操作を確認してください。']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('ManaLog push API: ' . $e->getMessage());
    push_response(500, ['error' => '通知設定を保存できませんでした。少し待ってから再度お試しください。']);
}
