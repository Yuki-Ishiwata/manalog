<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('manalog_session');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function ai_reply(array $config, array $context, array $history, string $message): string {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。.envのAI_API_KEYとAI_MODELを設定してください。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');
    $input = [];
    foreach ($history as $item) $input[] = ['role' => $item['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $item['content']];
    $input[] = ['role' => 'user', 'content' => $message];
    $instructions = 'あなたは日本の塾に通う生徒の学習記録を手伝う聞き役です。問題演習を主導せず、短く自然な日本語で、質問は原則ひとつずつにします。勉強していないことを責めず、不確かな内容を事実と断定しません。1回2〜3分程度で会話を進め、最後に必要なら記録する内容を短く確認します。生徒の学年: ' . ($context['grade'] ?: '未設定') . '。今日の時間割（予定）: ' . ($context['today'] ?: '登録なし') . '。時間割は予定であり、授業を受けたと断定しないでください。';
    $payload = json_encode(['model' => $config['ai_model'], 'instructions' => $instructions, 'input' => $input, 'max_output_tokens' => 300], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 35]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('AIに接続できませんでした。少し待ってから、もう一度お試しください。');
    $data = json_decode($raw, true);
    $text = '';
    foreach (($data['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
    if ($text === '') throw new RuntimeException('AIから応答を受け取れませんでした。もう一度お試しください。');
    return mb_substr($text, 0, 4000);
}
function ai_timetable_parse(array $config, string $imageData, string $mime): array {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。.envのAI_API_KEYとAI_MODELを設定してください。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');
    $prompt = 'この画像は学校の時間割です。月〜土を1〜6、1〜8限を数値で表し、読み取れた科目だけをJSONで返してください。説明文やMarkdownは不要です。形式: {"entries":[{"day_of_week":1,"period_number":1,"subject":"数学"}]}。読めない欄は出力しないでください。';
    $imageUrl = 'data:' . $mime . ';base64,' . base64_encode($imageData);
    $payload = json_encode(['model' => $config['ai_model'], 'instructions' => '画像内の記載だけを丁寧に読み取り、曜日と時限を推測で補わないでください。JSONだけを返してください。', 'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $prompt], ['type' => 'input_image', 'image_url' => $imageUrl]]]], 'max_output_tokens' => 1500], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 45]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('時間割を解析できませんでした。画像を確認して再度お試しください。');
    $data = json_decode($raw, true);
    $output = '';
    foreach (($data['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $output .= (string)($part['text'] ?? '');
    $decoded = json_decode(trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', $output)), true);
    if (!is_array($decoded) || !is_array($decoded['entries'] ?? null)) throw new RuntimeException('解析結果を読み取れませんでした。画像を鮮明にして再度お試しください。');
    $entries = [];
    foreach ($decoded['entries'] as $entry) {
        $day = filter_var($entry['day_of_week'] ?? null, FILTER_VALIDATE_INT);
        $period = filter_var($entry['period_number'] ?? null, FILTER_VALIDATE_INT);
        $subject = trim((string)($entry['subject'] ?? ''));
        if ($day >= 1 && $day <= 6 && $period >= 1 && $period <= 8 && $subject !== '' && mb_strlen($subject) <= 100) $entries[$day][$period] = $subject;
    }
    return $entries;
}

$error = '';
$notice = '';
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { /* Setup screen explains missing DB configuration. */ }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        $error = '画面を更新して、もう一度お試しください。';
    } else {
        $action = (string)($_POST['action'] ?? 'login');
        try {
            if ($action === 'setup') {
                if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) throw new RuntimeException('初回セットアップは完了しています。');
                $login = trim((string)($_POST['login_id'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($login === '' || strlen($password) < 12) throw new RuntimeException('ログインIDを入力し、パスワードは12文字以上にしてください。');
                $stmt = $pdo->prepare("INSERT INTO users (login_id, password_hash, role) VALUES (?, ?, 'admin')");
                $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                $_SESSION['role'] = 'admin';
                session_regenerate_id(true);
                header('Location: ./'); exit;
            } elseif ($action === 'login') {
                $stmt = $pdo->prepare("SELECT id, password_hash, role FROM users WHERE login_id = ? AND status = 'active' LIMIT 1");
                $stmt->execute([trim((string)($_POST['login_id'] ?? ''))]);
                $user = $stmt->fetch();
                if (!$user || !password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role'] = $user['role'];
                header('Location: ./'); exit;
            } elseif (($_SESSION['role'] ?? '') === 'admin' && $action === 'create_student') {
                $login = trim((string)($_POST['login_id'] ?? ''));
                $name = trim((string)($_POST['display_name'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($login === '' || $name === '' || strlen($password) < 12) throw new RuntimeException('ログインID・表示名を入力し、初期パスワードは12文字以上にしてください。');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (login_id, password_hash, role) VALUES (?, ?, 'student')");
                $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);
                $studentId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO student_profiles (user_id, display_name, school_name, grade) VALUES (?, ?, ?, ?)');
                $stmt->execute([$studentId, $name, trim((string)($_POST['school_name'] ?? '')) ?: null, trim((string)($_POST['grade'] ?? '')) ?: null]);
                $stmt = $pdo->prepare('INSERT INTO notification_preferences (student_id) VALUES (?)');
                $stmt->execute([$studentId]);
                $pdo->commit();
                $notice = '生徒アカウントを登録しました。';
            } elseif (($_SESSION['role'] ?? '') === 'admin' && $action === 'toggle_student') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) throw new RuntimeException('対象の生徒が指定されていません。');
                $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'disabled', 'active') WHERE id = ? AND role = 'student'");
                $stmt->execute([$id]);
                $notice = 'アカウント状態を更新しました。';
            } elseif (($_SESSION['role'] ?? '') === 'admin' && $action === 'reset_password') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $password = (string)($_POST['password'] ?? '');
                if (!$id || strlen($password) < 12) throw new RuntimeException('新しいパスワードは12文字以上で入力してください。');
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'student'");
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                $notice = 'パスワードを再設定しました。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'save_profile') {
                $name = trim((string)($_POST['display_name'] ?? ''));
                if ($name === '') throw new RuntimeException('表示名を入力してください。');
                $stmt = $pdo->prepare('UPDATE student_profiles SET display_name = ?, school_name = ?, grade = ? WHERE user_id = ?');
                $stmt->execute([$name, trim((string)($_POST['school_name'] ?? '')) ?: null, trim((string)($_POST['grade'] ?? '')) ?: null, (int)$_SESSION['user_id']]);
                $notice = 'プロフィールを保存しました。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'save_notifications') {
                $after = trim((string)($_POST['after_school_time'] ?? ''));
                $night = trim((string)($_POST['night_time'] ?? ''));
                foreach ([$after, $night] as $time) {
                    if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('時刻は24時間表記で入力してください。');
                }
                $stmt = $pdo->prepare('UPDATE notification_preferences SET after_school_time = ?, night_time = ? WHERE student_id = ?');
                $stmt->execute([$after !== '' ? $after . ':00' : null, $night !== '' ? $night . ':00' : null, (int)$_SESSION['user_id']]);
                $notice = '通知希望時刻を保存しました。Phase 1では通知は送信されません。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'save_timetable') {
                $subjects = $_POST['subjects'] ?? [];
                if (!is_array($subjects)) throw new RuntimeException('時間割の入力内容を確認してください。');
                $pdo->beginTransaction();
                $studentId = (int)$_SESSION['user_id'];
                $stmt = $pdo->prepare('SELECT id FROM timetables WHERE student_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
                $stmt->execute([$studentId]);
                $timetableId = (int)($stmt->fetchColumn() ?: 0);
                if (!$timetableId) {
                    $stmt = $pdo->prepare('INSERT INTO timetables (student_id, title, is_active) VALUES (?, ?, 1)');
                    $stmt->execute([$studentId, '時間割']);
                    $timetableId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM timetable_entries WHERE timetable_id = ?')->execute([$timetableId]);
                $insert = $pdo->prepare('INSERT INTO timetable_entries (timetable_id, day_of_week, period_number, subject) VALUES (?, ?, ?, ?)');
                for ($day = 1; $day <= 6; $day++) {
                    for ($period = 1; $period <= 8; $period++) {
                        $subject = trim((string)($subjects[$day][$period] ?? ''));
                        if ($subject === '') continue;
                        if (mb_strlen($subject) > 100) throw new RuntimeException('科目名は100文字以内で入力してください。');
                        $insert->execute([$timetableId, $day, $period, $subject]);
                    }
                }
                $pdo->commit();
                unset($_SESSION['timetable_draft']);
                $notice = '時間割を保存しました。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'parse_timetable') {
                $file = $_FILES['timetable_image'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('5MB以下の画像ファイルを選択してください。');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) throw new RuntimeException('JPEG・PNG・WebPの画像を選択してください。');
                $uploadDir = dirname(__DIR__) . '/storage/uploads';
                if (!is_dir($uploadDir) || !is_writable($uploadDir)) throw new RuntimeException('一時画像の保存先を利用できません。');
                $temporaryImage = $uploadDir . '/' . bin2hex(random_bytes(20)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($file['tmp_name'], $temporaryImage)) throw new RuntimeException('画像を一時保存できませんでした。');
                try {
                    $draft = ai_timetable_parse($config, (string)file_get_contents($temporaryImage), $mime);
                    if (!$draft) throw new RuntimeException('時間割を読み取れませんでした。空欄を手入力してください。');
                    $_SESSION['timetable_draft'] = $draft;
                    $notice = '解析結果を確認・修正してから保存してください。既存の時間割はまだ変更されていません。';
                } finally {
                    if (is_file($temporaryImage)) unlink($temporaryImage);
                }
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'start_chat') {
                $stmt = $pdo->prepare("INSERT INTO chat_sessions (student_id, session_date, session_type) VALUES (?, ?, 'general')");
                $stmt->execute([(int)$_SESSION['user_id'], (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')]);
                header('Location: ?session=' . (int)$pdo->lastInsertId()); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'send_message') {
                $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
                $message = trim((string)($_POST['message'] ?? ''));
                if (!$sessionId || $message === '' || mb_strlen($message) > 2000) throw new RuntimeException('メッセージを入力してください（2000文字以内）。');
                $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE id = ? AND student_id = ?');
                $stmt->execute([$sessionId, (int)$_SESSION['user_id']]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('この会話を開けません。');
                $stmt = $pdo->prepare('SELECT role, content FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 12');
                $stmt->execute([$sessionId]);
                $history = array_reverse($stmt->fetchAll());
                $stmt = $pdo->prepare('SELECT grade FROM student_profiles WHERE user_id = ?');
                $stmt->execute([(int)$_SESSION['user_id']]);
                $grade = (string)$stmt->fetchColumn();
                $today = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('N');
                $stmt = $pdo->prepare('SELECT e.subject FROM timetables t JOIN timetable_entries e ON e.timetable_id = t.id WHERE t.student_id = ? AND t.is_active = 1 AND e.day_of_week = ? ORDER BY e.period_number');
                $stmt->execute([(int)$_SESSION['user_id'], $today]);
                $reply = ai_reply($config, ['grade' => $grade, 'today' => implode('・', array_column($stmt->fetchAll(), 'subject'))], $history, $message);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)");
                $stmt->execute([$sessionId, $message]);
                $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
                $stmt->execute([$sessionId, $reply]);
                $pdo->commit();
                header('Location: ?session=' . $sessionId); exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getCode() === '23000' ? 'そのログインIDはすでに使われています。' : 'データを保存できませんでした。入力内容とデータベースを確認してください。';
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$isFirst = false;
if ($pdo) { try { $isFirst = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0; } catch (Throwable $e) { $pdo = null; } }
$students = [];
$profile = null;
$timetable = [];
$preferences = ['after_school_time' => '', 'night_time' => ''];
$chatSessions = [];
$chatMessages = [];
$activeChatId = filter_var($_GET['session'] ?? null, FILTER_VALIDATE_INT) ?: null;
$timetableDraft = $_SESSION['timetable_draft'] ?? [];
if ($pdo && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') {
    $students = $pdo->query("SELECT u.id, u.login_id, u.status, p.display_name, p.school_name, p.grade FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.role = 'student' ORDER BY u.created_at DESC")->fetchAll();
} elseif ($pdo && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student') {
    $stmt = $pdo->prepare('SELECT display_name, school_name, grade FROM student_profiles WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $profile = $stmt->fetch() ?: ['display_name' => '', 'school_name' => '', 'grade' => ''];
    $stmt = $pdo->prepare('SELECT id FROM timetables WHERE student_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $timetableId = (int)($stmt->fetchColumn() ?: 0);
    if ($timetableId) {
        $stmt = $pdo->prepare('SELECT day_of_week, period_number, subject FROM timetable_entries WHERE timetable_id = ?');
        $stmt->execute([$timetableId]);
        foreach ($stmt->fetchAll() as $entry) $timetable[(int)$entry['day_of_week']][(int)$entry['period_number']] = $entry['subject'];
    }
    $stmt = $pdo->prepare('SELECT after_school_time, night_time FROM notification_preferences WHERE student_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $preferences = $stmt->fetch() ?: $preferences;
    $stmt = $pdo->prepare('SELECT s.id, s.session_date, s.started_at, (SELECT content FROM chat_messages m WHERE m.session_id = s.id ORDER BY m.id DESC LIMIT 1) AS last_message FROM chat_sessions s WHERE s.student_id = ? ORDER BY s.started_at DESC LIMIT 10');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $chatSessions = $stmt->fetchAll();
    if ($activeChatId) {
        $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE id = ? AND student_id = ?');
        $stmt->execute([$activeChatId, (int)$_SESSION['user_id']]);
        if (!$stmt->fetchColumn()) $activeChatId = null;
        else {
            $stmt = $pdo->prepare('SELECT role, content, created_at FROM chat_messages WHERE session_id = ? ORDER BY id');
            $stmt->execute([$activeChatId]);
            $chatMessages = $stmt->fetchAll();
        }
    }
}
?><!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f5f7f4"><title>まなログ</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/chat.css"></head>
<body><main class="shell">
  <header class="brand"><span class="brand-mark">学</span><div><h1>まなログ</h1><p>今日の学びを、少しずつ。</p></div></header>
  <?php if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin'): ?>
    <section class="card"><p class="eyebrow">管理者</p><h2>生徒アカウント</h2>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
      <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_student">
        <h3>生徒を登録</h3><label>ログインID<input name="login_id" required maxlength="100"></label><label>初期パスワード（12文字以上）<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><label>表示名<input name="display_name" required maxlength="100"></label><label>学校名<input name="school_name" maxlength="150"></label><label>学年<input name="grade" maxlength="40" placeholder="例：中学2年"></label><button class="button" type="submit">生徒を登録</button>
      </form>
      <h3 class="section-title">登録済み（<?= count($students) ?>名）</h3>
      <?php if (!$students): ?><p class="muted">生徒はまだ登録されていません。</p><?php endif; ?>
      <?php foreach ($students as $student): ?><article class="student-row"><div><strong><?= h($student['display_name'] ?: $student['login_id']) ?></strong><p><?= h($student['login_id']) ?> · <?= h($student['school_name'] ?: '学校未設定') ?> · <?= h($student['grade'] ?: '学年未設定') ?></p><span class="status <?= $student['status'] === 'active' ? 'active' : '' ?>"><?= $student['status'] === 'active' ? '有効' : '無効' ?></span></div><div class="row-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_student"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button class="small-button" type="submit"><?= $student['status'] === 'active' ? '無効化' : '有効化' ?></button></form><details><summary>PW再発行</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><label>新パスワード<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button class="small-button" type="submit">再設定</button></form></details></div></article><?php endforeach; ?>
      <a class="button secondary" href="?logout=1">ログアウト</a>
    </section>
  <?php elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student'): ?>
    <section class="card"><p class="eyebrow">生徒ホーム · <?= h((new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y年n月j日')) ?></p><h2><?= h($profile['display_name'] ?? '') ?>さん、こんにちは</h2><?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
      <?php $today = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('N'); $dayNames = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土']; ?>
      <div class="today-box"><strong>今日の時間割（<?= $today <= 6 ? h($dayNames[$today]) . '曜日' : '日曜日' ?>）</strong><p><?php $todaySubjects = array_filter($timetable[$today] ?? []); echo $todaySubjects ? h(implode('・', array_values($todaySubjects))) : '時間割はまだ登録されていません。'; ?></p></div>
      <?php if ($activeChatId): ?><section class="chat-panel"><div class="chat-heading"><h3>今日の振り返り</h3><a href="./">ホーム</a></div><?php if (!$chatMessages): ?><p class="muted">学校で勉強したことや、気になったことを話してみよう。</p><?php endif; ?><div class="messages"><?php foreach ($chatMessages as $chatMessage): ?><div class="bubble <?= $chatMessage['role'] === 'user' ? 'mine' : 'theirs' ?>"><?= nl2br(h($chatMessage['content'])) ?></div><?php endforeach; ?></div><form method="post" class="chat-form"><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="session_id" value="<?= (int)$activeChatId ?>"><label class="sr-only" for="chat-message">メッセージ</label><textarea id="chat-message" name="message" maxlength="2000" rows="3" placeholder="今日の授業はどうだった？" required><?= h($_POST['message'] ?? '') ?></textarea><div class="chat-controls"><button class="small-button" id="voice-button" type="button" hidden>🎙 音声入力</button><span id="voice-status" class="muted" aria-live="polite"></span><button class="button" type="submit">確認して送信</button></div></form></section>
      <?php else: ?><form method="post" class="start-chat"><?= csrf_field() ?><input type="hidden" name="action" value="start_chat"><button class="button" type="submit">振り返りを始める</button></form><?php endif; ?>
      <?php if ($chatSessions): ?><details class="profile-edit"><summary>会話履歴</summary><?php foreach ($chatSessions as $chatSession): ?><a class="history-link" href="?session=<?= (int)$chatSession['id'] ?>"><strong><?= h($chatSession['session_date']) ?></strong><span><?= h($chatSession['last_message'] ?: '会話を開始') ?></span></a><?php endforeach; ?></details><?php endif; ?>
      <details class="profile-edit"><summary>時間割を登録・編集</summary><p class="muted">写真から解析するか、科目名を手入力できます。解析結果を確認して保存するまで、今の時間割は変更されません。個人名や学校名が写っている場合は、先に画像から切り取ってください。</p><form method="post" enctype="multipart/form-data" class="image-upload"><?= csrf_field() ?><input type="hidden" name="action" value="parse_timetable"><label>時間割の画像（5MBまで）<input type="file" name="timetable_image" accept="image/jpeg,image/png,image/webp" required></label><button class="small-button" type="submit">AIで読み取る</button></form><form method="post" class="timetable-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_timetable"><div class="table-scroll"><table class="timetable"><thead><tr><th>限</th><?php foreach ($dayNames as $name): ?><th><?= h($name) ?></th><?php endforeach; ?></tr></thead><tbody><?php for ($period = 1; $period <= 8; $period++): ?><tr><th><?= $period ?></th><?php foreach ($dayNames as $day => $name): ?><td><input aria-label="<?= h($name) ?>曜日 <?= $period ?>限" name="subjects[<?= $day ?>][<?= $period ?>]" value="<?= h($timetableDraft[$day][$period] ?? $timetable[$day][$period] ?? '') ?>" maxlength="100"></td><?php endforeach; ?></tr><?php endfor; ?></tbody></table></div><button class="button" type="submit">時間割を保存</button></form></details>
      <details class="profile-edit"><summary>通知希望時刻</summary><p class="muted">通知機能は将来追加予定です。ここで設定しても通知は送信されません。</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_notifications"><label>帰宅後<input type="time" name="after_school_time" value="<?= h(substr((string)$preferences['after_school_time'], 0, 5)) ?>"></label><label>夜<input type="time" name="night_time" value="<?= h(substr((string)$preferences['night_time'], 0, 5)) ?>"></label><button class="button" type="submit">通知時刻を保存</button></form></details>
      <details class="profile-edit"><summary>プロフィールを編集</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><label>表示名<input name="display_name" value="<?= h($profile['display_name'] ?? '') ?>" required maxlength="100"></label><label>学校名<input name="school_name" value="<?= h($profile['school_name'] ?? '') ?>" maxlength="150"></label><label>学年<input name="grade" value="<?= h($profile['grade'] ?? '') ?>" maxlength="40"></label><button class="button" type="submit">保存</button></form></details><a class="button secondary" href="?logout=1">ログアウト</a>
    </section>
  <?php elseif (!$pdo): ?>
    <section class="card"><p class="eyebrow">セットアップ</p><h2>データベースに接続できません</h2><p class="muted">`.env` の接続情報とMySQLの起動状態を確認してください。</p></section>
  <?php else: ?>
    <section class="card"><p class="eyebrow"><?= $isFirst ? '初回設定' : '生徒・管理者ログイン' ?></p><h2><?= $isFirst ? '管理者を作成' : 'おかえりなさい' ?></h2>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
      <form method="post" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isFirst ? 'setup' : 'login' ?>"><label>ログインID<input name="login_id" required autocomplete="username" maxlength="100"></label><label>パスワード<input type="password" name="password" required autocomplete="<?= $isFirst ? 'new-password' : 'current-password' ?>" minlength="<?= $isFirst ? '12' : '1' ?>"></label><button class="button" type="submit"><?= $isFirst ? '管理者を作成' : 'ログイン' ?></button></form>
    </section>
  <?php endif; ?>
  <footer>石渡塾 学習記録</footer>
</main><script src="assets/app.js" defer></script></body></html>
