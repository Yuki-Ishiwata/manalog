<?php
declare(strict_types=1);

$appRoot = dirname(__DIR__);
$appRootFile = __DIR__ . '/app_root.local.php';
if (is_file($appRootFile)) {
    $appRoot = require $appRootFile;
}
require_once $appRoot . '/src/bootstrap.php';
require_once $appRoot . '/src/auth.php';
require_once $appRoot . '/src/reviews.php';
require_once $appRoot . '/src/learning_photos.php';
require_once $appRoot . '/src/learning_records.php';
require_once $appRoot . '/src/weekly_reviews.php';
require_once $appRoot . '/src/instructor_summaries.php';
require_once $appRoot . '/src/tenants.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('manalog_session');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function instructor_summary_admin_authorized(PDO $pdo): bool {
    if (!in_array(($_SESSION['role'] ?? ''), ['admin', 'teacher'], true) || empty($_SESSION['user_id'])) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? AND u.role IN ('admin','teacher') AND u.status = 'active' AND o.status = 'active' AND u.organization_id = ? AND (u.role = 'teacher' OR u.admin_scope = 'tenant')");
    $stmt->execute([(int)$_SESSION['user_id'], (int)($_SESSION['organization_id'] ?? 0)]);
    return (bool)$stmt->fetchColumn();
}
function tenant_admin_authorized(PDO $pdo): bool {
    if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? AND u.role = 'admin' AND u.admin_scope = 'tenant' AND u.status = 'active' AND o.status = 'active' AND u.organization_id = ?");
    $stmt->execute([(int)$_SESSION['user_id'], (int)($_SESSION['organization_id'] ?? 0)]);
    return (bool)$stmt->fetchColumn();
}
function student_initial_setup_status(PDO $pdo, int $studentId): array {
    $stmt = $pdo->prepare("SELECT
        (SELECT COALESCE(timetable_unavailable, 0) FROM student_profiles WHERE user_id = ?) AS timetable_unavailable,
        EXISTS(SELECT 1 FROM timetables t JOIN timetable_entries e ON e.timetable_id = t.id WHERE t.student_id = ? AND t.is_active = 1 AND TRIM(e.subject) <> '') AS timetable_ready,
        EXISTS(SELECT 1 FROM notification_schedule n WHERE n.student_id = ? AND ((n.after_school_enabled = 1 AND n.after_school_time IS NOT NULL) OR (n.night_enabled = 1 AND n.night_time IS NOT NULL))) AS notification_times_ready,
        EXISTS(SELECT 1 FROM notification_preferences p JOIN push_subscriptions s ON s.student_id = p.student_id WHERE p.student_id = ? AND p.enabled = 1 AND s.revoked_at IS NULL) AS push_ready");
    $stmt->execute([$studentId, $studentId, $studentId, $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $timetableUnavailable = (bool)($row['timetable_unavailable'] ?? false);
    $timetableReady = $timetableUnavailable || (bool)($row['timetable_ready'] ?? false);
    $notificationTimesReady = (bool)($row['notification_times_ready'] ?? false);
    $pushReady = (bool)($row['push_ready'] ?? false);
    return [
        'timetable' => $timetableReady,
        'timetable_unavailable' => $timetableUnavailable,
        'notification_times' => $notificationTimesReady,
        'push' => $pushReady,
        'notifications' => $notificationTimesReady && $pushReady,
    ];
}
function render_learning_list(PDO $pdo, int $studentId, bool $parentMode = false, ?int $parentChildId = null): void {
    $selectedDate = isset($_GET['learning_date']) ? (string)$_GET['learning_date'] : null;
    $selectedSubject = isset($_GET['learning_subject']) ? (string)$_GET['learning_subject'] : null;
    $selectedPage = filter_var($_GET['learning_page'] ?? 1, FILTER_VALIDATE_INT);
    $data = learning_list_for_student($pdo, $studentId, $selectedDate, $selectedSubject, $selectedPage && $selectedPage > 0 ? $selectedPage : 1);
    $activityNames = ['school' => '学校の授業', 'homework' => '家庭学習', 'juku' => '塾', 'other' => 'その他', 'unknown' => '学習'];
    $understandingNames = ['not_understood' => 'まだ分からない', 'needs_help' => '助けが必要', 'partial' => '一部わかる', 'mostly_understood' => 'だいたい分かる', 'confident' => '自信あり'];
    $resultNames = ['correct' => '○ 正解', 'partially_correct' => '△ 部分正解', 'incorrect' => '× 不正解', 'ungraded' => '未判定'];
    ?>
    <details id="learning-records" class="learning-details dashboard-disclosure" <?= isset($_GET['learning_open']) || isset($_GET['learning_date']) || isset($_GET['learning_subject']) || isset($_GET['learning_page']) ? 'open' : '' ?>><summary>学習の記録</summary><section class="learning-list" aria-labelledby="learning-list-title">
      <h3 id="learning-list-title"><?= $parentMode ? '学習状況' : 'これまでの学習' ?></h3>
      <?php if ($parentMode): ?><p class="muted">学習内容と記録の要約を表示しています。AIとの会話全文は表示しません。</p><?php endif; ?>
      <form method="get" action="./#learning-records" class="learning-filters">
        <input type="hidden" name="learning_open" value="1">
        <?php if ($parentChildId): ?><input type="hidden" name="child_id" value="<?= $parentChildId ?>"><?php endif; ?>
        <label>日付<input type="date" name="learning_date" value="<?= h($data['date'] ?? '') ?>"></label>
        <label>科目<select name="learning_subject"><option value="">すべて</option><?php foreach ($data['subjects'] as $itemSubject): ?><option value="<?= h($itemSubject) ?>" <?= $data['subject'] === $itemSubject ? 'selected' : '' ?>><?= h($itemSubject) ?></option><?php endforeach; ?></select></label>
        <button class="small-button" type="submit">絞り込む</button>
        <?php if ($data['date'] || $data['subject']): ?><a class="learning-clear" href="?learning_open=1<?= $parentChildId ? '&child_id=' . $parentChildId : '' ?>#learning-records">条件を解除</a><?php endif; ?>
      </form>
      <?php if (!$data['records']): ?><p class="muted learning-empty">まだ学習記録はありません。振り返りを完了すると、会話から抽出した記録が順次表示されます。</p><?php endif; ?>
      <?php $lastDate = null; foreach ($data['records'] as $record): $recordDate = (string)$record['learning_date']; ?>
        <?php if ($recordDate !== $lastDate): $lastDate = $recordDate; ?><h4 class="learning-date"><?= h((new DateTimeImmutable($recordDate, new DateTimeZone('Asia/Tokyo')))->format('Y年n月j日')) ?></h4><?php endif; ?>
        <article class="learning-record">
          <div class="learning-record-heading"><strong><?= h($record['subject'] ?: '科目未特定') ?><?= $record['unit_name'] ? ' · ' . h($record['unit_name']) : '' ?></strong><span class="learning-activity"><?= h($activityNames[$record['activity_type']] ?? '学習') ?></span></div>
          <?php if ($record['summary']): ?><p class="learning-summary"><?= h($record['summary']) ?></p><?php endif; ?>
          <?php if ($record['study_minutes'] !== null): ?><p class="learning-meta">学習時間 <?= (int)$record['study_minutes'] ?>分</p><?php endif; ?>
          <?php if ($record['self_understanding'] || $record['ai_understanding']): ?><div class="learning-understanding">
            <?php if ($record['self_understanding']): ?><span>本人の申告: <?= h($understandingNames[$record['self_understanding']] ?? $record['self_understanding']) ?></span><?php endif; ?>
            <?php if ($record['ai_understanding']): ?><span>AI推定: <?= h($understandingNames[$record['ai_understanding']] ?? $record['ai_understanding']) ?></span><?php endif; ?>
          </div><?php endif; ?>
          <?php if ($record['latest_understanding'] && $record['previous_understanding']): ?><p class="learning-meta">同じ単元の理解度記録: <?= h((new DateTimeImmutable((string)$record['previous_understanding_date'], new DateTimeZone('Asia/Tokyo')))->format('n/j')) ?> <?= h($understandingNames[$record['previous_understanding']] ?? $record['previous_understanding']) ?> → 今回 <?= h($understandingNames[$record['latest_understanding']] ?? $record['latest_understanding']) ?></p><?php endif; ?>
          <?php if ($record['not_understood']): ?><p class="learning-followup"><strong>まだ分からない:</strong> <?= h($record['not_understood']) ?></p><?php endif; ?>
          <?php if ($record['next_check']): ?><p class="learning-followup"><strong>次に確認:</strong> <?= h($record['next_check']) ?></p><?php endif; ?>
          <?php foreach ($data['problems'][(int)$record['id']] ?? [] as $problem): ?><div class="learning-problem"><strong><?= h($resultNames[$problem['answer_result']] ?? '未判定') ?></strong><span><?= $problem['problem_number'] ? h($problem['problem_number']) . '番' : '問題' ?><?= $problem['page_number'] ? ' · ' . h($problem['page_number']) . 'ページ' : '' ?><?= $problem['material_name'] ? ' · ' . h($problem['material_name']) : '' ?></span></div><?php endforeach; ?>
          <?php if (!empty($data['photos'][(int)$record['id']])): ?><div class="learning-photos"><?php foreach ($data['photos'][(int)$record['id']] as $photo): ?><?php $photoUrl = $parentChildId ? '?photo=' . (int)$photo['id'] . '&parent_child_id=' . $parentChildId : '?photo=' . (int)$photo['id']; ?><a href="<?= h($photoUrl) ?>" target="_blank" rel="noopener"><img src="<?= h($photoUrl) ?>" alt="学習写真" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if ($data['pages'] > 1): $filterQuery = array_filter(['learning_open' => '1', 'child_id' => $parentChildId, 'learning_date' => $data['date'], 'learning_subject' => $data['subject']], static fn($value) => $value !== null && $value !== ''); ?><nav class="learning-pagination" aria-label="学習記録のページ">
        <?php if ($data['page'] > 1): ?><a class="small-button" href="?<?= h(http_build_query($filterQuery + ['learning_page' => $data['page'] - 1])) ?>#learning-records">新しい記録</a><?php endif; ?>
        <span><?= (int)$data['page'] ?> / <?= (int)$data['pages'] ?>ページ · <?= (int)$data['total'] ?>件</span>
        <?php if ($data['page'] < $data['pages']): ?><a class="small-button" href="?<?= h(http_build_query($filterQuery + ['learning_page' => $data['page'] + 1])) ?>#learning-records">古い記録</a><?php endif; ?>
      </nav><?php endif; ?>
    </section></details>
    <?php
}
function render_weekly_review_plan(array $plan, string $photoMode = 'student', ?int $childId = null): void {
    $statusLabels = ['proposed' => '提案済み', 'in_progress' => '取り組み中', 'completed' => '実施済み', 'not_done' => '未実施', 'needs_review' => '次回も復習'];
    ?>
    <section class="weekly-review-plan"><h3>今週のおすすめ復習</h3><p class="muted">AIの提案です。記録にない教材や問題番号は補っていません。</p>
      <?php if (empty($plan['items'])): ?><p class="muted">今週の復習記録はありません。</p><?php else: ?>
        <?php foreach ($plan['items'] as $index => $item): $candidate = $item['candidate'] ?? []; $title = trim(implode('／', array_filter([(string)($candidate['subject'] ?? ''), (string)($candidate['unit'] ?? '')]))) ?: '学習内容'; ?>
          <article class="weekly-review-item"><strong>優先<?= (int)$index + 1 ?> · <?= h($title) ?></strong><span class="weekly-review-status"><?= h($statusLabels[$item['status'] ?? 'proposed'] ?? '提案済み') ?></span>
            <p><?= h($item['reason'] ?? '今週学んだ内容を思い出す練習をします。') ?></p><p><strong>やってみよう:</strong> <?= h($item['how_to_review'] ?? '教材を見ずに、覚えていることを説明してみよう。') ?> · 目安<?= (int)($item['estimated_minutes'] ?? 10) ?>分</p>
            <?php foreach (($candidate['problems'] ?? []) as $problem): $material = trim(implode(' · ', array_filter([(string)($problem['material'] ?? ''), !empty($problem['page']) ? $problem['page'] . 'ページ' : '', !empty($problem['number']) ? $problem['number'] . '番' : '']))); if ($material): ?><small>記録された教材: <?= h($material) ?><?= !empty($problem['result']) ? ' · ' . h(['correct' => '○', 'partially_correct' => '△', 'incorrect' => '×', 'ungraded' => '未判定'][$problem['result']] ?? '') : '' ?><?= ($problem['source'] ?? '') === 'student_mark' ? '（写真上の印）' : (($problem['source'] ?? '') === 'ai_estimate' ? '（写真解析の推定）' : '') ?></small><?php endif; endforeach; ?>
            <?php if (!empty($candidate['record_id'])): ?><small>根拠: 学習記録 #<?= (int)$candidate['record_id'] ?><?= !empty($candidate['carried_from_week']) ? ' · 前週から継続' : '' ?></small><?php endif; ?>
            <?php if (!empty($candidate['photos'])): ?><div class="learning-photos"><?php foreach (array_slice(array_unique($candidate['photos']), 0, 4) as $photoId): $photoQuery = $photoMode === 'staff' ? '?staff_photo=' . (int)$photoId : ('?photo=' . (int)$photoId . ($photoMode === 'parent' && $childId ? '&parent_child_id=' . $childId : '')); ?><a href="<?= h($photoQuery) ?>" target="_blank" rel="noopener"><img src="<?= h($photoQuery) ?>" alt="復習に関連する写真" loading="lazy"></a><?php endforeach; ?></div><?php endif; ?>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
    <?php
}
function remember_view_mode(string $mode): void {
    if (!in_array($mode, ['student', 'parent'], true)) return;
    setcookie('manalog_view_mode', $mode, [
        'expires' => time() + 60 * 60 * 24 * 180,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
function remembered_view_mode(): string {
    $mode = (string)($_COOKIE['manalog_view_mode'] ?? 'student');
    return in_array($mode, ['student', 'parent'], true) ? $mode : 'student';
}
function subject_question(int $period, string $subject): string { return $period . '時間目の' . $subject . 'は、何を勉強した？'; }
function after_school_skip_reason(string $message): ?string {
    $text = mb_convert_kana(trim($message), 's');
    if (preg_match('/(この|その)?(教科|科目)(は|、)?(聞かなくていい|聞かなくて大丈夫|質問しなくていい|質問しなくて大丈夫|聞かなくてよい|質問不要|飛ばして|パスして)/u', $text)
        || preg_match('/(聞かなくていい|質問しなくていい|質問しなくて大丈夫|飛ばして|パスして)(ね|ください|もいい)?$/u', $text)) return 'exclude';
    if (preg_match('/(休んで(?:て|た|いた|る)|休んだ|休み(?:だった|で)|欠席した|学校を休んだ|学校行ってない|学校に行ってない|行ってない|授業がなかった|授業はなかった|授業なかった|授業なし|自習)/u', $text)) return 'no_lesson';
    return null;
}
function notification_time_input_value(?string $databaseTime): array {
    if (!$databaseTime || !preg_match('/^(\d{2}):(\d{2})/', $databaseTime, $matches)) return ['time' => '', 'next_day' => false];
    $hour = (int)$matches[1];
    $minute = $matches[2];
    $nextDay = $hour >= 24;
    if ($nextDay) $hour -= 24;
    return ['time' => sprintf('%02d:%s', $hour, $minute), 'next_day' => $nextDay];
}
function review_stage_label(string $stage, string $reviewType = 'after_school', bool $timetableUnavailable = false): string {
    if ($reviewType === 'after_school' && $timetableUnavailable) return match ($stage) {
        'activity' => '教科を確認',
        'recall' => '学習内容を確認',
        'difficulty' => '理解度を確認',
        'knowledge_check' => '理解チェック',
        'completed' => '完了',
        default => '',
    };
    if ($reviewType === 'after_school') return match ($stage) {
        'activity' => '教科を確認',
        'recall' => '内容を確認',
        'difficulty' => '理解度を確認',
        'knowledge_check' => '理解チェック',
        'completed' => '完了',
        default => '',
    };
    if ($reviewType === 'night') return match ($stage) {
        'activity' => '質問1/3',
        'duration' => '質問2/3',
        'difficulty' => '質問3/3',
        'knowledge_check' => '理解チェック',
        'completed' => '完了',
        default => '',
    };
    return match ($stage) {
        'activity', 'recall' => '質問1/2',
        'difficulty' => '質問2/2',
        'knowledge_check' => '理解チェック',
        'completed' => '完了',
        default => '',
    };
}
function subject_progress(int $position, int $total, string $stage, string $reviewType = 'after_school'): string {
    if ($total === 0) return '';
    if ($stage === 'completed') return $total . '/' . $total . '教科 · 完了';
    return $position . '/' . $total . '教科 · ' . review_stage_label($stage, $reviewType);
}
function ai_reply(array $config, array $context, array $history, string $message, ?array $focus = null, bool $askFollowup = false, string $reviewType = 'general'): string {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。.envのAI_API_KEYとAI_MODELを設定してください。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');
    $input = [];
    foreach ($history as $item) $input[] = ['role' => $item['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $item['content']];
    $input[] = ['role' => 'user', 'content' => $message];
    $instructions = 'あなたは日本の塾に通う生徒の学習記録を手伝う聞き役です。問題演習を主導せず、短く自然な日本語で、質問は原則ひとつずつにします。勉強していないことを責めず、不確かな内容を事実と断定しません。生徒の学年: ' . ($context['grade'] ?: '未設定') . '。';
    if ($reviewType === 'night') $instructions .= '今回は家庭学習についての夜の振り返りです。学校の授業を受けたことや家庭学習をしたことを決めつけず、回答を短く受け止めてください。質問はアプリが追加するので、あなたは質問を追加しないでください。';
    elseif ($reviewType === 'weekly_review') {
        $instructions .= '今回は今週の復習会話です。生徒自身に思い出して説明してもらい、答えや解法を先に教えず、質問は一度に一つだけにします。間違えた問題や「次に確認」にある内容を優先し、会話にない教材情報は作りません。復習候補: ' . json_encode($context['weekly_review_items'] ?? [], JSON_UNESCAPED_UNICODE);
        if ((int)($context['weekly_turn'] ?? 0) >= 4) $instructions .= 'これが最後の回答です。新しい質問をせず、短くねぎらって会話を締めてください。';
    } else $instructions .= '今日の時間割（予定）: ' . ($context['today'] ?: '登録なし') . '。時間割は予定であり、授業を受けたと断定しないでください。' . ($reviewType === 'after_school' && !$focus ? '質問はアプリが追加するので、あなたは質問を追加しないでください。' : '');
    if ($reviewType === 'after_school' && !empty($context['timetable_unavailable'])) $instructions .= '時間割は週ごとに変わります。教科は会話の中で生徒が答えた内容だけを使い、固定の時間割から推測しません。';
    if ($focus) {
        $instructions .= '今回は' . $focus['period_number'] . '時間目の' . $focus['subject'] . 'についての回答だけに、短く自然に返してください。';
        $instructions .= $askFollowup
            ? '答えを受け止めたあと、その教科で難しかった点や理解できた点を一つだけ質問してください。次の教科の話題は出さないでください。'
            : '答えを一文で受け止め、質問や別の教科の話題を追加しないでください。';
    }
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
function ai_review_knowledge_check(array $config, array $context, array $history, string $message, ?array $focus = null): array {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。.envのAI_API_KEYとAI_MODELを設定してください。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');
    $input = [];
    foreach ($history as $item) $input[] = ['role' => $item['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $item['content']];
    $input[] = ['role' => 'user', 'content' => $message];
    $focusText = $focus ? $focus['subject'] . 'の授業' : 'この振り返り';
    $instructions = 'あなたは生徒の振り返りを支援します。直近の生徒の発言に、具体的に難しい・分からない・自信がないとされた学習内容がある場合だけ、その内容を思い出す短い確認質問を1つ作ってください。質問は生徒が自分の言葉で答えられる形にし、答えやヒントを先に示さず、会話で出ていない単元や事実を持ち込まないでください。対象が具体的でない、難しい内容がない、または確認質問を作る根拠が足りない場合は needs_check を false とし、質問せず短く受け止めてください。' . $focusText . 'について、短い自然な日本語で返してください。';
    $schema = [
        'type' => 'object',
        'properties' => [
            'needs_check' => ['type' => 'boolean'],
            'reply' => ['type' => 'string'],
        ],
        'required' => ['needs_check', 'reply'],
        'additionalProperties' => false,
    ];
    $payload = json_encode([
        'model' => $config['ai_model'],
        'instructions' => $instructions,
        'input' => $input,
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'manalog_review_check', 'strict' => true, 'schema' => $schema]],
        'max_output_tokens' => 300,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 35]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('AIに接続できませんでした。少し待ってから、もう一度お試しください。');
    $data = json_decode($raw, true);
    $text = '';
    foreach (($data['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
    $result = json_decode($text, true);
    if (!is_array($result) || !is_bool($result['needs_check'] ?? null) || !is_string($result['reply'] ?? null) || trim($result['reply']) === '') throw new RuntimeException('AIから確認質問を受け取れませんでした。もう一度お試しください。');
    return ['needs_check' => $result['needs_check'], 'reply' => mb_substr(trim($result['reply']), 0, 1000)];
}
function ai_after_school_turn(array $config, array $context, array $history, string $message, ?array $focus, string $stage): array {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。.envのAI_API_KEYとAI_MODELを設定してください。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');
    $input = [];
    foreach ($history as $item) $input[] = ['role' => $item['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $item['content']];
    $input[] = ['role' => 'user', 'content' => $message];
    $target = $focus
        ? (int)$focus['period_number'] . '時間目の' . (string)$focus['subject']
        : '今日の学校の授業全体';
    $instructions = 'あなたは生徒の学校授業の振り返りを聞くAIです。今回の対象は' . $target . 'です。現在の段階は' . ($stage === 'activity' ? '教科を思い出す段階' : '授業内容を思い出す段階') . 'です。会話の文脈と生徒の最新の返答から、通常の授業を受けた、欠席した、授業がなかった、または自習だったかを判断してください。欠席・休校・行事などで授業がなかったことや自習だったことが明確なら、その状態を返してください。判断材料が足りない場合は決めつけず unclear にしてください。テストを受けた、授業内容を思い出せない、短い返答だったというだけで「授業なし」にしないでください。学習内容がまだ分からない、または教科名だけで具体的な単元・活動が分からない場合は content_captured を false にしてください。生徒が学んだ単元・用語・問題の種類・授業中の活動など、あとで記録に残せる具体的な内容を話した場合だけ true にしてください。false の場合は、記録に必要な内容を聞き出すため、会話履歴を見てまだ尋ねていない切り口から質問を一つしてください。何をやったか、どんな問題だったか、ノートや教科書のどの場面かなどを使い、同じ質問を繰り返さず、少しずつ具体化してください。質問回数に上限を設けず、内容が分かるまで急いで理解度確認へ進まないでください。思い出せないと言われたら答えを教えず、責めずに、より答えやすい小さな手掛かりを尋ねてください。true の場合は、生徒の実際の言葉に沿って短く受け止め、理解度や考え方を確かめる質問を一つだけ続けてください。毎回「難しかった？」と聞く定型文にせず、答えた単元や活動に合わせてください。授業がなかった場合の生徒向けの返答は短く受け止めるだけにし、別の質問を加えないでください。会話にない内容や単元を作らず、質問は一度に一つにしてください。';
    $schema = [
        'type' => 'object',
        'properties' => [
            'lesson_status' => ['type' => 'string', 'enum' => ['attended', 'absent', 'no_class', 'self_study', 'unclear']],
            'content_captured' => ['type' => 'boolean'],
            'reply' => ['type' => 'string'],
        ],
        'required' => ['lesson_status', 'content_captured', 'reply'],
        'additionalProperties' => false,
    ];
    $payload = json_encode([
        'model' => $config['ai_model'],
        'instructions' => $instructions,
        'input' => $input,
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'manalog_after_school_turn', 'strict' => true, 'schema' => $schema]],
        'max_output_tokens' => 300,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 35]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('AIに接続できませんでした。少し待ってから、もう一度お試しください。');
    $data = json_decode($raw, true);
    $text = '';
    foreach (($data['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
    $result = json_decode($text, true);
    $statuses = ['attended', 'absent', 'no_class', 'self_study', 'unclear'];
    if (!is_array($result) || !in_array($result['lesson_status'] ?? null, $statuses, true) || !is_bool($result['content_captured'] ?? null) || !is_string($result['reply'] ?? null) || trim($result['reply']) === '') throw new RuntimeException('AIから振り返りの応答を受け取れませんでした。もう一度お試しください。');
    return ['lesson_status' => $result['lesson_status'], 'content_captured' => $result['content_captured'], 'reply' => mb_substr(trim($result['reply']), 0, 1000)];
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
if ($pdo && isset($_SESSION['user_id'])) {
    $accountCheck = $pdo->prepare('SELECT u.role, u.status, u.organization_id, u.admin_scope, o.status AS organization_status FROM users u JOIN organizations o ON o.id = u.organization_id WHERE u.id = ? LIMIT 1');
    $accountCheck->execute([(int)$_SESSION['user_id']]);
    $account = $accountCheck->fetch();
    if (!$account || $account['status'] !== 'active' || $account['organization_status'] !== 'active' || $account['role'] !== $_SESSION['role']) {
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }
    $_SESSION['organization_id'] = (int)$account['organization_id'];
    $_SESSION['admin_scope'] = $account['admin_scope'];
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['view_mode'] ??= 'student';
if ($pdo && isset($_GET['photo']) && filter_var($_GET['photo'], FILTER_VALIDATE_INT) && isset($_SESSION['user_id'])) {
    $photoOwnerId = (int)$_SESSION['user_id'];
    if (($_SESSION['role'] ?? '') === 'parent') {
        $childId = filter_var($_GET['parent_child_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$childId) { http_response_code(404); exit; }
        if ((int)($_SESSION['organization_id'] ?? 0) < 1 || !tenant_parent_student_access($pdo, $photoOwnerId, (int)$childId)) { http_response_code(404); exit; }
        $photoOwnerId = (int)$childId;
    }
    $stmt = $pdo->prepare('SELECT storage_key, mime_type FROM learning_photos WHERE id = ? AND student_id = ? LIMIT 1');
    $stmt->execute([(int)$_GET['photo'], $photoOwnerId]);
    $photo = $stmt->fetch();
    $uploadRoot = realpath($appRoot . '/storage/uploads');
    $path = $photo && $uploadRoot ? realpath($uploadRoot . '/' . $photo['storage_key']) : false;
    if (!$photo || !$uploadRoot || !$path || !str_starts_with((string)$photo['storage_key'], 'learning/' . $photoOwnerId . '/') || !str_starts_with($path, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: ' . $photo['mime_type']);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    readfile($path);
    exit;
}
if ($pdo && isset($_GET['staff_photo'])) {
    $photoId = filter_var($_GET['staff_photo'], FILTER_VALIDATE_INT);
    if (!$photoId || !instructor_summary_admin_authorized($pdo)) { http_response_code(404); exit; }
    $stmt = $pdo->prepare("SELECT p.student_id, p.storage_key, p.mime_type FROM learning_photos p JOIN users s ON s.id = p.student_id WHERE p.id = ? AND s.organization_id = ? AND EXISTS (SELECT 1 FROM learning_record_photos rp JOIN learning_records r ON r.id = rp.learning_record_id WHERE rp.photo_id = p.id AND r.student_id = p.student_id)");
    $stmt->execute([(int)$photoId, (int)($_SESSION['organization_id'] ?? 0)]);
    $photo = $stmt->fetch();
    $uploadRoot = realpath($appRoot . '/storage/uploads');
    $path = $photo && $uploadRoot ? realpath($uploadRoot . '/' . $photo['storage_key']) : false;
    if (!$photo || !$uploadRoot || !$path || !str_starts_with((string)$photo['storage_key'], 'learning/' . (int)$photo['student_id'] . '/') || !str_starts_with($path, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: ' . $photo['mime_type']);
    header('Content-Length: ' . (string)filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    readfile($path);
    exit;
}
if (!isset($_SESSION['user_id']) && filter_var($_GET['session'] ?? null, FILTER_VALIDATE_INT)) $_SESSION['login_redirect'] = '?session=' . (int)$_GET['session'];
$requestedSchoolStudentId = filter_var($_GET['school_student_id'] ?? null, FILTER_VALIDATE_INT);
if (!isset($_SESSION['user_id']) && $requestedSchoolStudentId && $requestedSchoolStudentId > 0) {
    $_SESSION['staff_school_student_redirect'] = (int)$requestedSchoolStudentId;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

if ($pdo && isset($_SESSION['user_id']) && ($_SESSION['auth_source'] ?? '') === 'school'
    && time() - (int)($_SESSION['school_auth_checked_at'] ?? 0) >= 300) {
    try {
        if (!auth_revalidate_school_user($pdo, (int)$_SESSION['user_id'], $config)) {
            $_SESSION = [];
            session_destroy();
            header('Location: ./');
            exit;
        }
        $_SESSION['school_auth_checked_at'] = time();
    } catch (Throwable $e) {
        error_log('ManaLog school account revalidation failed: ' . $e->getMessage());
        $_SESSION = [];
        session_destroy();
        header('Location: ./');
        exit;
    }
}

$mustChangePassword = false;
$onboardingRequired = false;
$onboardingStep = 0;
if ($pdo && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student') {
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $passwordHash = $stmt->fetchColumn();
    $mustChangePassword = $passwordHash !== false && password_verify('0000', (string)$passwordHash);
    if (!$mustChangePassword) {
        $stmt = $pdo->prepare('SELECT onboarding_step, onboarding_completed_at FROM student_profiles WHERE user_id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $onboarding = $stmt->fetch();
        if ($onboarding && $onboarding['onboarding_completed_at'] === null) {
            $onboardingRequired = true;
            // Step 3 used to be subject exclusions and step 4 notification times.
            // Fold both the removed step 3 and legacy step 4 into the new notification step.
            $onboardingStep = max(1, min(3, (int)$onboarding['onboarding_step']));
        }
    }
}
$isAjaxChat = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' && ($_POST['action'] ?? '') === 'send_message';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        $error = '画面を更新して、もう一度お試しください。';
    } else {
        $action = (string)($_POST['action'] ?? 'login');
        try {
            if ($mustChangePassword && $action !== 'change_password') throw new RuntimeException('先にパスワードを変更してください。');
            if ($onboardingRequired) {
                $allowedActions = [1 => ['continue_onboarding_after_push'], 2 => ['parse_timetable', 'save_timetable', 'mark_no_timetable'], 3 => ['save_notifications', 'complete_onboarding']];
                if (!in_array($action, $allowedActions[$onboardingStep] ?? [], true)) throw new RuntimeException('初回設定を順番に完了してください。');
            }
            if ($action === 'refresh_instructor_summary') {
                if (!instructor_summary_admin_authorized($pdo)) throw new RuntimeException('管理者権限を確認できません。');
                $studentId = filter_var($_POST['summary_student_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$studentId) throw new RuntimeException('生徒を選択してください。');
                [$dateFrom, $dateTo] = instructor_summary_period((string)($_POST['summary_from'] ?? ''), (string)($_POST['summary_to'] ?? ''));
                if (!tenant_staff_student_access($pdo, (int)$_SESSION['user_id'], (int)$studentId)) throw new RuntimeException('対象の生徒を確認できません。');
                instructor_summary_cached($pdo, $config, (int)$studentId, $dateFrom, $dateTo, true);
                header('Location: ?staff_student_id=' . (int)$studentId . '&period_manual=1&summary_from=' . rawurlencode($dateFrom) . '&summary_to=' . rawurlencode($dateTo) . '#instructor-summary'); exit;
            } elseif (tenant_admin_authorized($pdo) && $action === 'set_organization_name') {
                $name = trim((string)($_POST['organization_name'] ?? ''));
                if ($name === '' || mb_strlen($name) > 150) throw new RuntimeException('塾名は1〜150文字で入力してください。');
                $stmt = $pdo->prepare('UPDATE organizations SET name = ? WHERE id = ?');
                $stmt->execute([$name, (int)$_SESSION['organization_id']]);
                $notice = '所属塾の名前を更新しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'create_staff_account') {
                $role = (string)($_POST['account_role'] ?? '');
                $login = trim((string)($_POST['login_id'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if (!in_array($role, ['teacher', 'parent'], true) || $login === '' || strlen($login) > 100 || strlen($password) < 12) throw new RuntimeException('種別・ログインIDを入力し、パスワードは12文字以上にしてください。');
                $stmt = $pdo->prepare("INSERT INTO users (organization_id, login_id, password_hash, role, auth_source) VALUES (?, ?, ?, ?, 'local')");
                $stmt->execute([(int)$_SESSION['organization_id'], $login, password_hash($password, PASSWORD_DEFAULT), $role]);
                $notice = $role === 'teacher' ? '講師アカウントを登録しました。' : '保護者アカウントを登録しました。子どもとの紐付けを追加してください。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'link_parent_student') {
                $parentId = filter_var($_POST['parent_user_id'] ?? null, FILTER_VALIDATE_INT);
                $studentId = filter_var($_POST['student_user_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$parentId || !$studentId) throw new RuntimeException('保護者と生徒を選択してください。');
                $check = $pdo->prepare("SELECT (SELECT COUNT(*) FROM users WHERE id = ? AND role = 'parent' AND status = 'active' AND organization_id = ?) AS parent_ok, (SELECT COUNT(*) FROM users WHERE id = ? AND role = 'student' AND status = 'active' AND organization_id = ?) AS student_ok");
                $check->execute([$parentId, (int)$_SESSION['organization_id'], $studentId, (int)$_SESSION['organization_id']]);
                $valid = $check->fetch();
                if (!$valid || !(int)$valid['parent_ok'] || !(int)$valid['student_ok']) throw new RuntimeException('有効な保護者アカウントと生徒アカウントを確認できません。');
                $stmt = $pdo->prepare('INSERT IGNORE INTO parent_student_links (parent_user_id, student_user_id) VALUES (?, ?)');
                $stmt->execute([$parentId, $studentId]);
                $notice = $stmt->rowCount() ? '保護者と生徒を紐付けました。' : 'この紐付けはすでに登録されています。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'unlink_parent_student') {
                $linkId = filter_var($_POST['link_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$linkId) throw new RuntimeException('紐付けを選択してください。');
                $stmt = $pdo->prepare('DELETE l FROM parent_student_links l JOIN users p ON p.id = l.parent_user_id WHERE l.id = ? AND p.organization_id = ?');
                $stmt->execute([$linkId, (int)$_SESSION['organization_id']]);
                if (!$stmt->rowCount()) throw new RuntimeException('この塾の紐付けを確認できません。');
                $notice = '保護者と生徒の紐付けを解除しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'toggle_staff_account') {
                $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$accountId) throw new RuntimeException('対象アカウントを選択してください。');
                $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'disabled', 'active') WHERE id = ? AND role IN ('teacher','parent') AND organization_id = ?");
                $stmt->execute([$accountId, (int)$_SESSION['organization_id']]);
                if (!$stmt->rowCount()) throw new RuntimeException('講師・保護者アカウントを確認できません。');
                $notice = 'アカウント状態を更新しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'reset_staff_password') {
                $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
                $password = (string)($_POST['password'] ?? '');
                if (!$accountId || strlen($password) < 12) throw new RuntimeException('対象アカウントと12文字以上のパスワードを指定してください。');
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND organization_id = ? AND role IN ('teacher','parent') AND auth_source = 'local'");
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $accountId, (int)$_SESSION['organization_id']]);
                if (!$stmt->rowCount()) throw new RuntimeException('この塾の講師・保護者アカウントを確認できません。');
                $notice = 'パスワードを再設定しました。';
            } elseif ($action === 'setup') {
                if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) throw new RuntimeException('初回セットアップは完了しています。');
                $login = trim((string)($_POST['login_id'] ?? ''));
                $password = (string)($_POST['password'] ?? '');
                if ($login === '' || strlen($password) < 12) throw new RuntimeException('ログインIDを入力し、パスワードは12文字以上にしてください。');
                $stmt = $pdo->prepare("INSERT INTO users (organization_id, login_id, password_hash, role, admin_scope) VALUES (1, ?, ?, 'admin', 'tenant')");
                $stmt->execute([$login, password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['user_id'] = (int)$pdo->lastInsertId();
                $_SESSION['role'] = 'admin';
                $_SESSION['organization_id'] = 1;
                $_SESSION['admin_scope'] = 'tenant';
                session_regenerate_id(true);
                header('Location: ./'); exit;
            } elseif ($action === 'login') {
                $user = auth_login($pdo, (string)($_POST['login_id'] ?? ''), (string)($_POST['password'] ?? ''), $config);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['auth_source'] = $user['auth_source'];
                $orgStmt = $pdo->prepare('SELECT organization_id, admin_scope FROM users WHERE id = ?');
                $orgStmt->execute([(int)$user['id']]);
                $orgContext = $orgStmt->fetch() ?: ['organization_id' => 1, 'admin_scope' => null];
                $_SESSION['organization_id'] = (int)$orgContext['organization_id'];
                $_SESSION['admin_scope'] = $orgContext['admin_scope'];
                $_SESSION['school_auth_checked_at'] = $user['auth_source'] === 'school' ? time() : 0;
                $_SESSION['view_mode'] = $user['role'] === 'student' ? remembered_view_mode() : 'student';
                $redirect = $user['role'] === 'student' ? ($_SESSION['login_redirect'] ?? './') : './';
                if (in_array($user['role'], ['admin', 'teacher'], true)) {
                    $staffSchoolStudentId = filter_var($_SESSION['staff_school_student_redirect'] ?? null, FILTER_VALIDATE_INT);
                    if ($staffSchoolStudentId && $staffSchoolStudentId > 0) $redirect = '?school_student_id=' . (int)$staffSchoolStudentId . '#instructor-summary';
                }
                if ($user['role'] === 'student') {
                    $stmt = $pdo->prepare('SELECT onboarding_completed_at FROM student_profiles WHERE user_id = ?');
                    $stmt->execute([(int)$user['id']]);
                    if ($stmt->fetchColumn() === null) $redirect = '?onboarding=1';
                }
                unset($_SESSION['login_redirect']);
                unset($_SESSION['staff_school_student_redirect']);
                header('Location: ' . $redirect); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'set_view_mode') {
                $mode = (string)($_POST['view_mode'] ?? '');
                if (!in_array($mode, ['student', 'parent'], true)) throw new RuntimeException('表示モードを確認してください。');
                $_SESSION['view_mode'] = $mode;
                remember_view_mode($mode);
                header('Location: ./'); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'change_password') {
                if (!$mustChangePassword) throw new RuntimeException('パスワード変更は必要ありません。');
                $newPassword = (string)($_POST['new_password'] ?? '');
                if (strlen($newPassword) < 12) throw new RuntimeException('新しいパスワードは12文字以上で入力してください。');
                if ($newPassword !== (string)($_POST['new_password_confirm'] ?? '')) throw new RuntimeException('確認用パスワードが一致しません。');
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'student'");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
                session_regenerate_id(true);
                $targetSession = filter_var($_GET['session'] ?? null, FILTER_VALIDATE_INT);
                header('Location: ' . ($targetSession ? '?session=' . $targetSession : './')); exit;
            } elseif (tenant_admin_authorized($pdo) && $action === 'create_student') {
                $login = trim((string)($_POST['login_id'] ?? ''));
                $name = trim((string)($_POST['display_name'] ?? ''));
                if ($login === '' || $name === '') throw new RuntimeException('ログインID・表示名を入力してください。');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (organization_id, login_id, password_hash, role) VALUES (?, ?, ?, 'student')");
                $stmt->execute([(int)$_SESSION['organization_id'], $login, password_hash('0000', PASSWORD_DEFAULT)]);
                $studentId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO student_profiles (user_id, display_name, school_name, grade) VALUES (?, ?, ?, ?)');
                $stmt->execute([$studentId, $name, trim((string)($_POST['school_name'] ?? '')) ?: null, trim((string)($_POST['grade'] ?? '')) ?: null]);
                $stmt = $pdo->prepare('INSERT INTO notification_preferences (student_id) VALUES (?)');
                $stmt->execute([$studentId]);
                $pdo->commit();
                $notice = '生徒アカウントを登録しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'preview_school_link') {
                $userId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $schoolStudentId = filter_var($_POST['school_student_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$userId || !$schoolStudentId) throw new RuntimeException('生徒アカウントと塾の生徒IDを確認してください。');
                $stmt = $pdo->prepare("SELECT u.id, u.login_id, p.display_name FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'student' AND u.status = 'active' AND u.auth_source = 'local' AND u.organization_id = ?");
                $stmt->execute([$userId, (int)$_SESSION['organization_id']]);
                $localStudent = $stmt->fetch();
                if (!$localStudent) throw new RuntimeException('連携できるまなログ専用アカウントが見つかりません。');
                $schoolStudent = auth_school_student_by_id((int)$schoolStudentId, false);
                if (!$schoolStudent || (int)$schoolStudent['delete_flg'] !== 0 || !auth_school_student_is_active((int)$schoolStudentId)) {
                    throw new RuntimeException('その塾生徒IDは在籍中のアカウントではありません。');
                }
                $_SESSION['pending_school_link'] = [
                    'user_id' => (int)$userId,
                    'local_login_id' => (string)$localStudent['login_id'],
                    'local_display_name' => (string)($localStudent['display_name'] ?? ''),
                    'school_student_id' => (int)$schoolStudentId,
                    'display_name' => (string)$schoolStudent['simei'],
                    'login_id' => (string)$schoolStudent['login_id'],
                    'token' => bin2hex(random_bytes(24)),
                ];
                $notice = '表示された氏名とログインIDを照合してください。確認後に連携を確定できます。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'cancel_school_link') {
                unset($_SESSION['pending_school_link']);
                $notice = '塾アカウントの連携をキャンセルしました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'confirm_school_link') {
                $pendingLink = $_SESSION['pending_school_link'] ?? null;
                if (!is_array($pendingLink) || !hash_equals((string)($pendingLink['token'] ?? ''), (string)($_POST['link_token'] ?? ''))) {
                    throw new RuntimeException('連携確認の有効期限が切れました。もう一度検索してください。');
                }
                $schoolStudent = auth_school_student_by_id((int)$pendingLink['school_student_id'], false);
                if (!$schoolStudent || (int)$schoolStudent['delete_flg'] !== 0 || !auth_school_student_is_active((int)$pendingLink['school_student_id'])
                    || (string)$schoolStudent['login_id'] !== (string)$pendingLink['login_id']) {
                    unset($_SESSION['pending_school_link']);
                    throw new RuntimeException('塾側の生徒情報が変わりました。もう一度検索してください。');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND status = 'active' AND auth_source = 'local' AND organization_id = ? FOR UPDATE");
                $stmt->execute([(int)$pendingLink['user_id'], (int)$_SESSION['organization_id']]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('対象のまなログアカウントを利用できません。');
                $stmt = $pdo->prepare('SELECT user_id FROM student_school_links WHERE school_provider = ? AND school_student_id = ?');
                $stmt->execute([auth_provider_id($config), (int)$pendingLink['school_student_id']]);
                if ($stmt->fetchColumn()) throw new RuntimeException('この塾生徒IDはすでに連携済みです。');
                $randomHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET auth_source = 'school', password_hash = ? WHERE id = ?")->execute([$randomHash, (int)$pendingLink['user_id']]);
                $pdo->prepare('INSERT INTO student_school_links (user_id, school_provider, school_student_id, last_verified_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)')
                    ->execute([(int)$pendingLink['user_id'], auth_provider_id($config), (int)$pendingLink['school_student_id']]);
                $pdo->commit();
                unset($_SESSION['pending_school_link']);
                $notice = '塾アカウントを既存のまなログアカウントに連携しました。学習履歴と通知設定は引き継がれます。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'toggle_student') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) throw new RuntimeException('対象の生徒が指定されていません。');
                $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'disabled', 'active') WHERE id = ? AND role = 'student' AND organization_id = ?");
                $stmt->execute([$id, (int)$_SESSION['organization_id']]);
                if (!$stmt->rowCount()) throw new RuntimeException('この塾の生徒アカウントを確認できません。');
                $notice = 'アカウント状態を更新しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'set_student_timetable_mode') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $weeklyVaries = (string)($_POST['timetable_unavailable'] ?? '');
                if (!$id || !in_array($weeklyVaries, ['0', '1'], true)) throw new RuntimeException('生徒と時間割の設定を確認してください。');
                $stmt = $pdo->prepare("SELECT sp.timetable_unavailable FROM student_profiles sp JOIN users u ON u.id = sp.user_id WHERE sp.user_id = ? AND u.role = 'student' AND u.organization_id = ?");
                $stmt->execute([$id, (int)$_SESSION['organization_id']]);
                if ($stmt->fetchColumn() === false) throw new RuntimeException('この塾の生徒プロフィールを確認できません。');
                $stmt = $pdo->prepare("UPDATE student_profiles sp JOIN users u ON u.id = sp.user_id SET sp.timetable_unavailable = ? WHERE sp.user_id = ? AND u.role = 'student' AND u.organization_id = ?");
                $stmt->execute([(int)$weeklyVaries, $id, (int)$_SESSION['organization_id']]);
                $notice = $weeklyVaries === '1' ? '時間割を「週ごとに変わります」に設定しました。' : '時間割を通常の登録方式に設定しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'set_student_review_exclusions') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $raw = $_POST['excluded_subjects'] ?? null;
                if (!$id || !is_string($raw)) throw new RuntimeException('生徒と教科の入力内容を確認してください。');
                if (mb_strlen($raw) > 5000) throw new RuntimeException('対象外の教科は30件以内で入力してください。');
                $subjects = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/u', $raw) ?: []), static fn($subject) => $subject !== '')));
                if (count($subjects) > 30) throw new RuntimeException('対象外の教科は30件以内で入力してください。');
                foreach ($subjects as $subject) if (mb_strlen($subject) > 100) throw new RuntimeException('教科名は100文字以内で入力してください。');
                $stmt = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'student' AND organization_id = ?");
                $stmt->execute([$id, (int)$_SESSION['organization_id']]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('この塾の生徒を確認できません。');
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM review_excluded_subjects WHERE student_id = ?')->execute([$id]);
                $insert = $pdo->prepare('INSERT IGNORE INTO review_excluded_subjects (student_id, subject) VALUES (?, ?)');
                foreach ($subjects as $subject) $insert->execute([$id, $subject]);
                $pdo->commit();
                $notice = '質問しない教科を保存しました。次に始める振り返りから反映されます。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'change_student_login_id') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $login = trim((string)($_POST['login_id'] ?? ''));
                if (!$id || $login === '' || mb_strlen($login) > 100) throw new RuntimeException('ログインIDは1〜100文字で入力してください。');
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND auth_source = 'local' AND organization_id = ?");
                $stmt->execute([$id, (int)$_SESSION['organization_id']]);
                if (!$stmt->fetchColumn()) throw new RuntimeException('ログインIDを変更できるまなログ専用生徒を確認できません。');
                $stmt = $pdo->prepare('SELECT id FROM users WHERE login_id = ? AND id <> ? LIMIT 1');
                $stmt->execute([$login, $id]);
                if ($stmt->fetchColumn()) throw new RuntimeException('そのログインIDはすでに使用されています。別のIDを入力してください。');
                $stmt = $pdo->prepare("UPDATE users SET login_id = ? WHERE id = ? AND role = 'student' AND auth_source = 'local' AND organization_id = ?");
                $stmt->execute([$login, $id, (int)$_SESSION['organization_id']]);
                $notice = '生徒のログインIDを変更しました。';
            } elseif (tenant_admin_authorized($pdo) && $action === 'reset_password') {
                $id = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $password = (string)($_POST['password'] ?? '');
                if (!$id || strlen($password) < 12) throw new RuntimeException('新しいパスワードは12文字以上で入力してください。');
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'student' AND auth_source = 'local' AND organization_id = ?");
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id, (int)$_SESSION['organization_id']]);
                if (!$stmt->rowCount()) throw new RuntimeException('この塾のまなログ認証アカウントを確認できません。');
                $notice = 'パスワードを再設定しました。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'save_profile') {
                $name = trim((string)($_POST['display_name'] ?? ''));
                if ($name === '') throw new RuntimeException('表示名を入力してください。');
                $stmt = $pdo->prepare('UPDATE student_profiles SET display_name = ?, school_name = ?, grade = ? WHERE user_id = ?');
                $stmt->execute([$name, trim((string)($_POST['school_name'] ?? '')) ?: null, trim((string)($_POST['grade'] ?? '')) ?: null, (int)$_SESSION['user_id']]);
                $notice = 'プロフィールを保存しました。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'continue_onboarding_after_push') {
                if (!$onboardingRequired || $onboardingStep !== 1) throw new RuntimeException('初回設定の状態を確認できません。');
                $pdo->prepare('UPDATE student_profiles SET onboarding_step = 2 WHERE user_id = ? AND onboarding_completed_at IS NULL')->execute([(int)$_SESSION['user_id']]);
                header('Location: ?onboarding=1'); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'save_notifications') {
                $submitted = $_POST['times'] ?? [];
                if (!is_array($submitted)) throw new RuntimeException('通知時刻の入力内容を確認してください。');
                $enabled = $_POST['notification_enabled'] ?? [];
                if (!is_array($enabled)) throw new RuntimeException('通知曜日の入力内容を確認してください。');
                $nightNextDay = $_POST['night_next_day'] ?? [];
                if (!is_array($nightNextDay)) throw new RuntimeException('夜の通知時刻を確認してください。');
                $schedule = [];
                for ($day = 1; $day <= 7; $day++) {
                    $row = $submitted[$day] ?? [];
                    if (!is_array($row)) throw new RuntimeException('通知時刻の入力内容を確認してください。');
                    $schedule[$day] = [];
                    foreach (['after_school', 'night'] as $type) {
                        if (!is_string($row[$type] ?? '')) throw new RuntimeException('通知時刻の入力内容を確認してください。');
                        $time = trim((string)($row[$type] ?? ''));
                        if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('通知時刻を確認してください。');
                        $isNextDay = $type === 'night' && isset($nightNextDay[$day]);
                        [$hour, $minute] = $time !== '' ? array_map('intval', explode(':', $time)) : [0, 0];
                        if ($isNextDay && $hour > 5) throw new RuntimeException('翌日の通知は00:00〜05:59で入力してください。');
                        if ($isNextDay && $time !== '') $hour += 24;
                        $storedTime = $time === '' ? null : sprintf('%02d:%02d:00', $hour, $minute);
                        $schedule[$day][$type] = $storedTime;
                        $schedule[$day][$type . '_enabled'] = isset($enabled[$day][$type]) ? 1 : 0;
                    }
                }
                $pdo->beginTransaction();
                $studentId = (int)$_SESSION['user_id'];
                $pdo->prepare('DELETE FROM notification_schedule WHERE student_id = ?')->execute([$studentId]);
                $insert = $pdo->prepare('INSERT INTO notification_schedule (student_id, day_of_week, after_school_time, after_school_enabled, night_time, night_enabled) VALUES (?, ?, ?, ?, ?, ?)');
                foreach ($schedule as $day => $row) $insert->execute([$studentId, $day, $row['after_school'], $row['after_school_enabled'], $row['night'], $row['night_enabled']]);
                $weeklyDay = filter_var($_POST['weekly_review_day'] ?? 6, FILTER_VALIDATE_INT);
                $weeklyTime = trim((string)($_POST['weekly_review_time'] ?? '10:00'));
                if ($weeklyDay === false || !in_array($weeklyDay, [6, 7], true) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $weeklyTime)) throw new RuntimeException('週末復習の曜日と時刻を確認してください。');
                $stmt = $pdo->prepare('UPDATE notification_preferences SET paused = ?, weekly_review_enabled = ?, weekly_review_day = ?, weekly_review_time = ? WHERE student_id = ?');
                $stmt->execute([isset($_POST['notifications_paused']) ? 1 : 0, isset($_POST['weekly_review_enabled']) ? 1 : 0, $weeklyDay, $weeklyTime . ':00', $studentId]);
                $pdo->commit();
                $notice = '通知設定を保存しました。端末で通知を許可すると、設定した時刻に送信されます。';
                if ($onboardingRequired) {
                    $pdo->prepare('UPDATE student_profiles SET onboarding_step = 3, onboarding_completed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND onboarding_completed_at IS NULL')->execute([(int)$_SESSION['user_id']]);
                    header('Location: ./'); exit;
                }
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'save_review_exclusions') {
                if (!is_string($_POST['excluded_subjects'] ?? '')) throw new RuntimeException('教科名を確認してください。');
                $raw = (string)($_POST['excluded_subjects'] ?? '');
                if (mb_strlen($raw) > 5000) throw new RuntimeException('対象外の教科は30件以内で入力してください。');
                $subjects = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/u', $raw) ?: []), static fn($subject) => $subject !== '')));
                if (count($subjects) > 30) throw new RuntimeException('対象外の教科は30件以内で入力してください。');
                foreach ($subjects as $subject) if (mb_strlen($subject) > 100) throw new RuntimeException('教科名は100文字以内で入力してください。');
                $pdo->beginTransaction();
                $studentId = (int)$_SESSION['user_id'];
                $pdo->prepare('DELETE FROM review_excluded_subjects WHERE student_id = ?')->execute([$studentId]);
                $insert = $pdo->prepare('INSERT IGNORE INTO review_excluded_subjects (student_id, subject) VALUES (?, ?)');
                foreach ($subjects as $subject) $insert->execute([$studentId, $subject]);
                $pdo->commit();
                $notice = '質問しない教科を保存しました。次に始める振り返りから反映されます。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'mark_no_timetable') {
                if ($onboardingRequired && $onboardingStep !== 2) throw new RuntimeException('初回設定の状態を確認できません。');
                $pdo->prepare('UPDATE student_profiles SET timetable_unavailable = 1' . ($onboardingRequired ? ', onboarding_step = 3' : '') . ' WHERE user_id = ?')->execute([(int)$_SESSION['user_id']]);
                unset($_SESSION['timetable_draft']);
                if ($onboardingRequired) { header('Location: ?onboarding=1'); exit; }
                $notice = '時間割がない設定にしました。帰宅後の振り返りは1時間目の教科から始まります。';
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
                $pdo->prepare('UPDATE student_profiles SET timetable_unavailable = 0 WHERE user_id = ?')->execute([$studentId]);
                $pdo->commit();
                unset($_SESSION['timetable_draft']);
                $notice = '時間割を保存しました。';
                if ($onboardingRequired) {
                    $pdo->prepare('UPDATE student_profiles SET onboarding_step = 3 WHERE user_id = ? AND onboarding_completed_at IS NULL')->execute([(int)$_SESSION['user_id']]);
                    header('Location: ?onboarding=1'); exit;
                }
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'complete_onboarding') {
                if (!$onboardingRequired || $onboardingStep !== 3) throw new RuntimeException('初回設定の状態を確認できません。');
                $pdo->prepare('UPDATE student_profiles SET onboarding_step = 3, onboarding_completed_at = CURRENT_TIMESTAMP WHERE user_id = ? AND onboarding_completed_at IS NULL')->execute([(int)$_SESSION['user_id']]);
                header('Location: ./'); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'delete_timetable') {
                $stmt = $pdo->prepare('DELETE e FROM timetable_entries AS e INNER JOIN timetables AS t ON t.id = e.timetable_id WHERE t.student_id = ? AND t.is_active = 1');
                $stmt->execute([(int)$_SESSION['user_id']]);
                unset($_SESSION['timetable_draft']);
                $notice = '登録済みの時間割をすべて削除しました。進行中の振り返りには反映されません。';
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'parse_timetable') {
                $file = $_FILES['timetable_image'] ?? null;
                if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('5MB以下の画像ファイルを選択してください。');
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === false) throw new RuntimeException('JPEG・PNG・WebPの画像を選択してください。');
                $uploadDir = $appRoot . '/storage/uploads';
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
            } elseif (($_SESSION['role'] ?? '') === 'student' && in_array($action, ['start_chat', 'start_night'], true)) {
                $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
                $type = $action === 'start_night' ? 'night' : 'after_school';
                $reviewDate = $type === 'night' && (int)$now->format('G') < 6 ? $now->modify('-1 day')->format('Y-m-d') : $now->format('Y-m-d');
                $sessionId = daily_review_session($pdo, (int)$_SESSION['user_id'], $reviewDate, $type);
                if ($sessionId === null) throw new RuntimeException('今日の教科はすべて質問しない設定です。設定を変更すると振り返りを始められます。');
                header('Location: ?session=' . $sessionId); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'start_weekly_review') {
                $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
                $weekStart = $today->modify('monday this week')->format('Y-m-d');
                $weekEnd = $today->modify('sunday this week')->format('Y-m-d');
                $sessionId = weekly_review_session($pdo, (int)$_SESSION['user_id'], $weekStart, $weekEnd, $config);
                header('Location: ?session=' . $sessionId); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'start_general') {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO chat_sessions (student_id, session_date, session_type) VALUES (?, ?, 'general')");
                $stmt->execute([(int)$_SESSION['user_id'], (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')]);
                $sessionId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
                $stmt->execute([$sessionId, '今日はどんなことを話したい？']);
                $pdo->commit();
                header('Location: ?session=' . $sessionId); exit;
            } elseif (($_SESSION['role'] ?? '') === 'student' && $action === 'send_message') {
                $sessionId = filter_var($_POST['session_id'] ?? null, FILTER_VALIDATE_INT);
                $message = trim((string)($_POST['message'] ?? ''));
                $photoCount = isset($_FILES['photos']['name']) && is_array($_FILES['photos']['name']) ? count(array_filter($_FILES['photos']['name'], static fn($name) => (string)$name !== '')) : 0;
                if (!$sessionId || ($message === '' && $photoCount === 0) || mb_strlen($message) > 2000) throw new RuntimeException('メッセージか写真を送信してください（文章は2000文字以内）。');
                $stmt = $pdo->prepare('SELECT id, session_date, session_type, questions_per_subject, review_stage, ended_at FROM chat_sessions WHERE id = ? AND student_id = ?');
                $stmt->execute([$sessionId, (int)$_SESSION['user_id']]);
                $chatSession = $stmt->fetch();
                if (!$chatSession) throw new RuntimeException('この会話を開けません。');
                if ($chatSession['ended_at']) throw new RuntimeException('この振り返りは終了しています。');
                $displayMessage = $message;
                $photoResult = ['photos' => [], 'summary' => ''];
                if ($photoCount > 0) {
                    if ($photoCount > 4) throw new RuntimeException('写真は一度に4枚まで送信できます。');
                    $activity = $chatSession['session_type'] === 'after_school' ? 'school' : (in_array($chatSession['session_type'], ['night','weekly_review'], true) ? 'homework' : 'unknown');
                    $photoSubject = null;
                    if ($chatSession['session_type'] === 'after_school') {
                        $subjectStmt = $pdo->prepare("SELECT subject FROM chat_session_subjects WHERE session_id = ? AND review_stage <> 'completed' ORDER BY position LIMIT 1");
                        $subjectStmt->execute([$sessionId]);
                        $photoSubject = $subjectStmt->fetchColumn() ?: null;
                    }
                    $photoResult = learning_photo_uploads($pdo, (int)$_SESSION['user_id'], (int)$sessionId, $_FILES['photos'], $activity, $photoSubject, (string)$chatSession['session_date'], $config);
                    $displayMessage = ($message !== '' ? $message . "\n" : '') . '📷 写真' . $photoCount . '枚を送信しました。' . "\n" . '読み取り: ' . $photoResult['summary'];
                    $message = trim($message . "\n写真解析結果: " . $photoResult['summary']);
                }
                $focusSubjects = [];
                $answeredCount = 0;
                $focus = null;
                $weeklyReviewItems = [];
                $weeklyReviewPlanId = 0;
                $weeklyReviewItem = null;
                $weeklyReviewReply = null;
                $subjectIndex = null;
                $reviewStage = (string)($chatSession['review_stage'] ?? 'recall');
                if (in_array($chatSession['session_type'], ['after_school', 'night'], true)) {
                    if ($chatSession['session_type'] === 'after_school') {
                        $stmt = $pdo->prepare('SELECT id, position, period_number, subject, review_stage FROM chat_session_subjects WHERE session_id = ? ORDER BY position');
                        $stmt->execute([$sessionId]);
                        $focusSubjects = $stmt->fetchAll();
                    }
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ? AND role = 'user'");
                    $stmt->execute([$sessionId]);
                    $answeredCount = (int)$stmt->fetchColumn();
                    if ($focusSubjects) {
                        foreach ($focusSubjects as $index => $candidate) {
                            if ($candidate['review_stage'] !== 'completed') {
                                $subjectIndex = $index;
                                $focus = $candidate;
                                $reviewStage = (string)$candidate['review_stage'];
                                break;
                            }
                        }
                        if ($subjectIndex === null) throw new RuntimeException('この振り返りは終了しています。');
                    } elseif (!in_array($reviewStage, ['activity', 'duration', 'recall', 'difficulty', 'knowledge_check'], true)) {
                        throw new RuntimeException('この振り返りは終了しています。');
                    }
                } elseif ($chatSession['session_type'] === 'weekly_review') {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ? AND role = 'user'");
                    $stmt->execute([$sessionId]);
                    $answeredCount = (int)$stmt->fetchColumn();
                    $stmt = $pdo->prepare('SELECT plan_id, selected_items_json FROM weekly_review_sessions WHERE session_id = ? AND student_id = ?');
                    $stmt->execute([$sessionId, (int)$_SESSION['user_id']]);
                    $weeklySessionRow = $stmt->fetch();
                    $weeklyReviewPlanId = (int)($weeklySessionRow['plan_id'] ?? 0);
                    $weeklyReviewItems = json_decode((string)($weeklySessionRow['selected_items_json'] ?? ''), true);
                    if (!is_array($weeklyReviewItems)) throw new RuntimeException('週末復習の記録を読み込めません。');
                    if ($weeklyReviewPlanId && $weeklyReviewItems) $weeklyReviewItem = $weeklyReviewItems[min($answeredCount, count($weeklyReviewItems) - 1)] ?? null;
                }
                $historySql = $chatSession['session_type'] === 'after_school'
                    ? 'SELECT role, content FROM chat_messages WHERE session_id = ? ORDER BY id ASC'
                    : 'SELECT role, content FROM chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT 12';
                $stmt = $pdo->prepare($historySql);
                $stmt->execute([$sessionId]);
                $history = $stmt->fetchAll();
                if ($chatSession['session_type'] !== 'after_school') $history = array_reverse($history);
                $stmt = $pdo->prepare('SELECT grade, timetable_unavailable FROM student_profiles WHERE user_id = ?');
                $stmt->execute([(int)$_SESSION['user_id']]);
                $studentLearningProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $grade = (string)($studentLearningProfile['grade'] ?? '');
                $timetableUnavailable = (bool)($studentLearningProfile['timetable_unavailable'] ?? false);
                $today = (int)(new DateTimeImmutable($chatSession['session_date'], new DateTimeZone('Asia/Tokyo')))->format('N');
                $stmt = $pdo->prepare('SELECT e.subject FROM timetables t JOIN timetable_entries e ON e.timetable_id = t.id WHERE t.student_id = ? AND t.is_active = 1 AND e.day_of_week = ? ORDER BY e.period_number');
                $stmt->execute([(int)$_SESSION['user_id'], $today]);
                $context = ['grade' => $grade, 'today' => implode('・', array_column($stmt->fetchAll(), 'subject')), 'timetable_unavailable' => (bool)($studentLearningProfile['timetable_unavailable'] ?? false)];
                if ($chatSession['session_type'] === 'weekly_review') {
                    $context['weekly_review_items'] = $weeklyReviewItems;
                    $context['weekly_turn'] = $answeredCount + 1;
                }
                $nextStage = $reviewStage;
                $needsKnowledgeCheck = false;
                $skipReason = $chatSession['session_type'] === 'after_school'
                    ? after_school_skip_reason($message)
                    : null;
                if ($skipReason === 'exclude' && !$focus) $skipReason = null;
                $afterSchoolTurn = null;
                if ($skipReason === null && $chatSession['session_type'] === 'after_school' && in_array($reviewStage, ['activity', 'recall'], true)) {
                    $afterSchoolTurn = ai_after_school_turn($config, $context, $history, $message, $focus, $reviewStage);
                    if (in_array($afterSchoolTurn['lesson_status'], ['absent', 'no_class', 'self_study'], true)) $skipReason = 'no_lesson';
                }
                if ($skipReason !== null) {
                    $reply = $skipReason === 'exclude'
                        ? 'わかった。この教科は次回から質問しない設定にするね。'
                        : ($focus ? 'そうなんだ。この時間の授業内容は飛ばして、次の時間を確認するね。' : 'そうなんだ。今日は学校の授業はなかったんだね。教えてくれてありがとう。');
                    $nextStage = 'completed';
                } elseif ($chatSession['session_type'] === 'after_school' && !$focusSubjects && $reviewStage === 'activity') {
                    $reply = $afterSchoolTurn['reply'] ?? '教科を教えてくれてありがとう。今日は何を勉強した？';
                    $nextStage = !empty($afterSchoolTurn['content_captured']) ? 'difficulty' : 'recall';
                } elseif ($reviewStage === 'difficulty') {
                    $check = ai_review_knowledge_check($config, $context, $history, $message, $focus);
                    $reply = $check['reply'];
                    $needsKnowledgeCheck = $check['needs_check'];
                    $nextStage = $needsKnowledgeCheck ? 'knowledge_check' : 'completed';
                } elseif ($chatSession['session_type'] === 'weekly_review' && $weeklyReviewPlanId && is_array($weeklyReviewItem)) {
                    $weeklyReviewReply = weekly_review_ai_reply($pdo, $config, (int)$_SESSION['user_id'], $sessionId, $weeklyReviewPlanId, $history, $weeklyReviewItem, $message, $answeredCount + 1, !empty($photoResult['photos']));
                    $reply = $weeklyReviewReply['reply'];
                } else {
                    $askFollowup = $focus && $reviewStage === 'recall';
                    $reply = $afterSchoolTurn !== null
                        ? $afterSchoolTurn['reply']
                        : ai_reply($config, $context, $history, $message, $focus, (bool)$askFollowup, $chatSession['session_type']);
                    if ($focus) {
                        $nextStage = $reviewStage === 'recall' ? (!empty($afterSchoolTurn['content_captured']) ? 'difficulty' : 'recall') : ($reviewStage === 'knowledge_check' ? 'completed' : $reviewStage);
                    } elseif ($chatSession['session_type'] === 'night') {
                        $nextStage = $reviewStage === 'activity' ? 'duration' : ($reviewStage === 'duration' ? 'difficulty' : ($reviewStage === 'knowledge_check' ? 'completed' : $reviewStage));
                    } elseif ($chatSession['session_type'] === 'after_school') {
                        $nextStage = $reviewStage === 'recall' ? (!empty($afterSchoolTurn['content_captured']) ? 'difficulty' : 'recall') : ($reviewStage === 'knowledge_check' ? 'completed' : $reviewStage);
                    }
                }
                if ($chatSession['session_type'] === 'night') {
                    if ($reviewStage === 'activity') $reply .= "\n\n家での勉強はどのくらいの時間できた？ しなかった場合もそのままで大丈夫だよ。";
                    elseif ($reviewStage === 'duration') $reply .= "\n\n困ったことや、難しかったところはあった？";
                } elseif ($chatSession['session_type'] === 'after_school' && !$focusSubjects && $reviewStage === 'recall' && $afterSchoolTurn === null) {
                    $reply .= "\n\n難しかったところや、よく分かったところはあった？";
                }

                $completedSubject = $focus && $nextStage === 'completed';
                $nextSubject = null;
                if ($completedSubject) {
                    foreach (array_slice($focusSubjects, (int)$subjectIndex + 1) as $candidate) {
                        if ($candidate['review_stage'] !== 'completed') { $nextSubject = $candidate; break; }
                    }
                }
                $finishedReview = $focusSubjects
                    ? ($completedSubject && !$nextSubject)
                    : ((in_array($chatSession['session_type'], ['after_school', 'night'], true) && $nextStage === 'completed') || ($chatSession['session_type'] === 'weekly_review' && $answeredCount + 1 >= min(3, count($weeklyReviewItems))));
                if ($completedSubject) $reply .= $nextSubject
                    ? "\n\n" . subject_question((int)$nextSubject['period_number'], (string)$nextSubject['subject'])
                    : "\n\n今日の振り返りはここまで。話してくれてありがとう。";
                elseif ($finishedReview) $reply .= "\n\n" . ($chatSession['session_type'] === 'night' ? '夜の振り返りはここまで。話してくれてありがとう。' : ($chatSession['session_type'] === 'weekly_review' ? '今週の復習はここまで。思い出してくれてありがとう。' : '今日の振り返りはここまで。話してくれてありがとう。'));
                $pdo->beginTransaction();
                if ($chatSession['session_type'] !== 'general') {
                    $stmt = $pdo->prepare('SELECT ended_at FROM chat_sessions WHERE id = ? FOR UPDATE');
                    $stmt->execute([$sessionId]);
                    if ($stmt->fetchColumn() !== null) throw new RuntimeException('この振り返りは終了しています。');
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE session_id = ? AND role = 'user'");
                    $stmt->execute([$sessionId]);
                    if ((int)$stmt->fetchColumn() !== $answeredCount) throw new RuntimeException('別の端末で回答が進みました。画面を更新してください。');
                }
                $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'user', ?)");
                $stmt->execute([$sessionId, $displayMessage]);
                $userMessageId = (int)$pdo->lastInsertId();
                if ($skipReason === 'exclude' && $focus) {
                    $stmt = $pdo->prepare('INSERT IGNORE INTO review_excluded_subjects (student_id, subject) VALUES (?, ?)');
                    $stmt->execute([(int)$_SESSION['user_id'], (string)$focus['subject']]);
                }
                if ($photoResult['photos']) {
                    $photoLink = $pdo->prepare('INSERT IGNORE INTO learning_photo_chat_messages (photo_id, message_id, session_id, position) VALUES (?, ?, ?, ?)');
                    foreach ($photoResult['photos'] as $position => $photoId) $photoLink->execute([(int)$photoId, $userMessageId, $sessionId, $position + 1]);
                }
                $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
                $stmt->execute([$sessionId, $reply]);
                $assistantMessageId = (int)$pdo->lastInsertId();
                if ($chatSession['session_type'] === 'weekly_review' && $weeklyReviewPlanId && is_array($weeklyReviewItem) && is_array($weeklyReviewReply)) {
                    weekly_review_save_response($pdo, (int)$_SESSION['user_id'], $sessionId, $weeklyReviewPlanId, $weeklyReviewItem, $weeklyReviewReply, $userMessageId, $assistantMessageId, !empty($photoResult['photos']));
                }
                if ($focus) {
                    $stmt = $pdo->prepare('UPDATE chat_session_subjects SET review_stage = ? WHERE id = ? AND session_id = ?');
                    $stmt->execute([$nextStage, (int)$focus['id'], $sessionId]);
                } elseif (in_array($chatSession['session_type'], ['after_school', 'night', 'weekly_review'], true)) {
                    $stmt = $pdo->prepare('UPDATE chat_sessions SET review_stage = ? WHERE id = ?');
                    $stmt->execute([$chatSession['session_type'] === 'weekly_review' && $finishedReview ? 'completed' : $nextStage, $sessionId]);
                }
                if ($finishedReview) {
                    $stmt = $pdo->prepare('UPDATE chat_sessions SET ended_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $stmt->execute([$sessionId]);
                    if ($chatSession['session_type'] === 'weekly_review' && $weeklyReviewPlanId) weekly_review_finish($pdo, (int)$_SESSION['user_id'], $sessionId);
                    if (in_array($chatSession['session_type'], ['after_school', 'night'], true)) {
                        $stmt = $pdo->prepare('INSERT IGNORE INTO learning_extraction_jobs (student_id, session_id) VALUES (?, ?)');
                        $stmt->execute([(int)$_SESSION['user_id'], $sessionId]);
                    }
                }
                $pdo->commit();
                if ($isAjaxChat) {
                    header('Content-Type: application/json; charset=utf-8');
                    if ($finishedReview) $progress = '完了';
                    elseif ($focusSubjects) $progress = $completedSubject
                        ? subject_progress($subjectIndex + 2, count($focusSubjects), 'recall')
                        : subject_progress($subjectIndex + 1, count($focusSubjects), $nextStage);
                    else $progress = $chatSession['session_type'] === 'weekly_review' ? '今週の復習 ' . min($answeredCount + 1, 4) . '/4' : (in_array($chatSession['session_type'], ['after_school', 'night'], true) ? review_stage_label($nextStage, $chatSession['session_type'], $timetableUnavailable) : '');
                    $title = $chatSession['session_type'] === 'night' ? '夜の振り返り' : ($chatSession['session_type'] === 'weekly_review' ? '週末の復習' : ($chatSession['session_type'] === 'general' ? '自由に話す' : '帰宅後の振り返り'));
                    echo json_encode(['reply' => $reply, 'user_message' => $displayMessage, 'photo_ids' => $photoResult['photos'], 'ended' => (bool)$finishedReview, 'progress' => $finishedReview ? '完了' : $progress, 'title' => $title], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    exit;
                }
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
if ($isAjaxChat) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error ?: '送信できませんでした。もう一度お試しください。'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$isFirst = false;
if ($pdo) { try { $isFirst = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0; } catch (Throwable $e) { $pdo = null; } }
$students = [];
$notificationHistory = [];
$weeklyNotificationHistory = [];
$instructorStudents = [];
$instructorStats = [];
$organizationName = '';
$instructorReport = null;
$instructorSelectedStudent = null;
$parentChildren = [];
$parentSelectedChild = null;
$parentSnapshot = null;
$instructorPreviousLessonDate = null;
$instructorDateFrom = '';
$instructorDateTo = '';
$instructorPeriodSource = '';
$instructorError = '';
$instructorManualPeriod = false;
[$instructorDateFrom, $instructorDateTo] = instructor_summary_default_period(null);
$profile = null;
$timetable = [];
$timetableUnavailable = false;
$studentSetupStatus = null;
$reviewExcludedSubjects = [];
$notificationSchedule = [];
$notificationsPaused = false;
$weeklyReviewPreferences = ['enabled' => false, 'day' => 6, 'time' => '10:00'];
$weeklyReviewPlan = null;
$parentActivity = [];
$reviewHomeState = [];
$recommendedReview = null;
$recommendedReviewLabel = '';
$afterSchoolAvailable = true;
$viewMode = ($_SESSION['role'] ?? '') === 'student' && ($_SESSION['view_mode'] ?? 'student') === 'parent' ? 'parent' : 'student';
$chatMessages = [];
$activeChatEnded = false;
$activeChatTitle = '帰宅後の振り返り';
$subjectProgress = '';
$activeChatId = filter_var($_GET['session'] ?? null, FILTER_VALIDATE_INT) ?: null;
$timetableDraft = $_SESSION['timetable_draft'] ?? [];
if ($pdo && isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'teacher'], true)) {
    $students = [];
    $notificationHistory = [];
    $instructorStudents = [];
    if (tenant_admin_authorized($pdo)) {
        $organizationStmt = $pdo->prepare('SELECT name FROM organizations WHERE id = ?');
        $organizationStmt->execute([(int)$_SESSION['organization_id']]);
        $organizationName = (string)$organizationStmt->fetchColumn();
        $studentsStmt = $pdo->prepare("SELECT u.id, u.login_id, u.status, u.auth_source, p.display_name, p.school_name, p.grade, p.timetable_unavailable FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id WHERE u.role = 'student' AND u.organization_id = ? ORDER BY u.created_at DESC");
        $studentsStmt->execute([(int)$_SESSION['organization_id']]);
        $students = $studentsStmt->fetchAll();
        $excludedSubjectsByStudent = [];
        $excludedSubjectsStmt = $pdo->prepare("SELECT x.student_id, x.subject FROM review_excluded_subjects x JOIN users u ON u.id = x.student_id WHERE u.role = 'student' AND u.organization_id = ? ORDER BY x.subject");
        $excludedSubjectsStmt->execute([(int)$_SESSION['organization_id']]);
        foreach ($excludedSubjectsStmt->fetchAll(PDO::FETCH_ASSOC) as $excludedSubject) $excludedSubjectsByStudent[(int)$excludedSubject['student_id']][] = $excludedSubject['subject'];
        $notificationStmt = $pdo->prepare('SELECT d.review_date, d.notification_type, d.status, d.sent_at, d.last_error, d.resend_status, d.resend_sent_at, d.resend_error, p.display_name FROM notification_deliveries d JOIN users u ON u.id = d.student_id LEFT JOIN student_profiles p ON p.user_id = d.student_id WHERE u.organization_id = ? ORDER BY d.id DESC LIMIT 30');
        $notificationStmt->execute([(int)$_SESSION['organization_id']]);
        $notificationHistory = $notificationStmt->fetchAll();
        $weeklyNotificationStmt = $pdo->prepare('SELECT n.week_start, n.status, n.sent_at, n.last_error, p.display_name FROM weekly_review_notifications n JOIN users u ON u.id = n.student_id LEFT JOIN student_profiles p ON p.user_id = n.student_id WHERE u.organization_id = ? ORDER BY n.id DESC LIMIT 30');
        $weeklyNotificationStmt->execute([(int)$_SESSION['organization_id']]);
        $weeklyNotificationHistory = $weeklyNotificationStmt->fetchAll();
        $staffStmt = $pdo->prepare("SELECT id, login_id, role, status FROM users WHERE role IN ('teacher','parent') AND organization_id = ? ORDER BY role, login_id");
        $staffStmt->execute([(int)$_SESSION['organization_id']]);
        $staffAccounts = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
        $linksStmt = $pdo->prepare("SELECT l.id, p.login_id AS parent_login, s.login_id AS student_login, sp.display_name FROM parent_student_links l JOIN users p ON p.id = l.parent_user_id JOIN users s ON s.id = l.student_user_id LEFT JOIN student_profiles sp ON sp.user_id = s.id WHERE p.organization_id = ? AND s.organization_id = p.organization_id ORDER BY p.login_id, sp.display_name");
        $linksStmt->execute([(int)$_SESSION['organization_id']]);
        $parentLinks = $linksStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (instructor_summary_admin_authorized($pdo)) {
        $provider = auth_provider_id($config);
        $staffStudentsStmt = $pdo->prepare("SELECT u.id, u.login_id, u.auth_source, p.display_name, p.school_name, p.grade, l.school_student_id,
            (SELECT MAX(r.learning_date) FROM learning_records r WHERE r.student_id = u.id) AS last_learning_date,
            (SELECT COUNT(*) FROM learning_records r WHERE r.student_id = u.id AND r.learning_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)) AS recent_learning_count,
            (SELECT COUNT(*) FROM learning_understanding_history h WHERE h.student_id = u.id AND h.understanding_level IN ('not_understood','needs_help') AND h.observed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS weak_unit_count,
            (SELECT COUNT(*) FROM weekly_review_items wi WHERE wi.student_id = u.id AND wi.status IN ('proposed','in_progress','not_done','needs_review')) AS pending_review_count,
            EXISTS(SELECT 1 FROM learning_record_photos rp JOIN learning_records r ON r.id = rp.learning_record_id WHERE r.student_id = u.id) AS has_learning_photos
            FROM users u LEFT JOIN student_profiles p ON p.user_id = u.id LEFT JOIN student_school_links l ON l.user_id = u.id AND l.school_provider = ? WHERE u.role = 'student' AND u.status = 'active' AND u.organization_id = ? ORDER BY (l.school_student_id IS NULL), p.display_name, u.login_id");
        $staffStudentsStmt->execute([$provider, (int)$_SESSION['organization_id']]);
        $instructorStudents = $staffStudentsStmt->fetchAll(PDO::FETCH_ASSOC);
        $selectedId = filter_var($_GET['staff_student_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$selectedId && $requestedSchoolStudentId && $requestedSchoolStudentId > 0) {
            foreach ($instructorStudents as $candidate) {
                if ((int)($candidate['school_student_id'] ?? 0) === (int)$requestedSchoolStudentId) {
                    $selectedId = (int)$candidate['id'];
                    break;
                }
            }
            if (!$selectedId) $instructorError = 'この生徒はまなログにまだ連携されていないか、学習サマリーを表示できるアカウントがありません。';
        }
        if ($selectedId) {
            foreach ($instructorStudents as $candidate) if ((int)$candidate['id'] === (int)$selectedId) { $instructorSelectedStudent = $candidate; break; }
            if (!$instructorSelectedStudent) {
                $instructorError = '対象の生徒を確認できません。';
            } else {
                $schoolId = (int)($instructorSelectedStudent['school_student_id'] ?? 0);
                $instructorPreviousLessonDate = $schoolId ? instructor_previous_school_lesson_date($schoolId, $config) : null;
                [$defaultFrom, $defaultTo] = instructor_summary_default_period($instructorPreviousLessonDate);
                $instructorManualPeriod = ($_GET['period_manual'] ?? '') === '1';
                $instructorDateFrom = $instructorManualPeriod ? (string)($_GET['summary_from'] ?? '') : $defaultFrom;
                $instructorDateTo = $instructorManualPeriod ? (string)($_GET['summary_to'] ?? '') : $defaultTo;
                try {
                    [$instructorDateFrom, $instructorDateTo] = instructor_summary_period($instructorDateFrom, $instructorDateTo);
                    $instructorPeriodSource = $instructorManualPeriod
                        ? '指定した期間'
                        : ($instructorPreviousLessonDate ? '塾DBに登録された前回授業日から' : '前回授業日を取得できないため直近7日間');
                    $instructorReport = instructor_summary_cached($pdo, $config, (int)$selectedId, $instructorDateFrom, $instructorDateTo);
                } catch (Throwable $e) {
                    $instructorError = $e instanceof InvalidArgumentException ? $e->getMessage() : '学習サマリーを表示できませんでした。時間をおいて再度お試しください。';
                    if (!$e instanceof InvalidArgumentException) error_log('ManaLog instructor summary page failed: ' . $e->getMessage());
                }
            }
        }
    }
} elseif ($pdo && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'parent') {
    $parentId = (int)$_SESSION['user_id'];
    $parentChildrenStmt = $pdo->prepare("SELECT s.id, s.login_id, p.display_name, p.school_name, p.grade FROM parent_student_links l JOIN users s ON s.id = l.student_user_id JOIN users a ON a.id = l.parent_user_id JOIN organizations o ON o.id = a.organization_id LEFT JOIN student_profiles p ON p.user_id = s.id WHERE l.parent_user_id = ? AND a.role = 'parent' AND a.status = 'active' AND s.role = 'student' AND s.status = 'active' AND s.organization_id = a.organization_id AND a.organization_id = ? AND o.status = 'active' ORDER BY p.display_name, s.login_id");
    $parentChildrenStmt->execute([$parentId, (int)($_SESSION['organization_id'] ?? 0)]);
    $parentChildren = $parentChildrenStmt->fetchAll(PDO::FETCH_ASSOC);
    $requestedChildId = filter_var($_GET['child_id'] ?? null, FILTER_VALIDATE_INT);
    $parentSelectedChild = null;
    foreach ($parentChildren as $child) if ((int)$child['id'] === (int)$requestedChildId) { $parentSelectedChild = $child; break; }
    if (!$parentSelectedChild && count($parentChildren) === 1) $parentSelectedChild = $parentChildren[0];
    if ($parentSelectedChild) {
        $childId = (int)$parentSelectedChild['id'];
        $snapshotStmt = $pdo->prepare("SELECT MAX(r.learning_date) AS last_learning_date,
            SUM(r.learning_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)) AS recent_records,
            (SELECT COUNT(*) FROM learning_understanding_history h WHERE h.student_id = ? AND h.understanding_level IN ('not_understood','needs_help') AND h.observed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS needs_support_count,
            (SELECT COUNT(*) FROM weekly_review_items wi WHERE wi.student_id = ? AND wi.status IN ('proposed','in_progress','not_done','needs_review')) AS pending_reviews,
            EXISTS(SELECT 1 FROM learning_record_photos rp JOIN learning_records pr ON pr.id = rp.learning_record_id WHERE pr.student_id = ?) AS has_photos
            FROM learning_records r WHERE r.student_id = ?");
        $snapshotStmt->execute([$childId, $childId, $childId, $childId]);
        $parentSnapshot = $snapshotStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} elseif ($pdo && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student' && !$mustChangePassword && !$onboardingRequired && $viewMode === 'parent') {
    $stmt = $pdo->prepare('SELECT display_name FROM student_profiles WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $profile = $stmt->fetch() ?: ['display_name' => ''];
    $parentActivity = [];
} elseif ($pdo && isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student' && !$mustChangePassword) {
    $stmt = $pdo->prepare('SELECT display_name, school_name, grade, timetable_unavailable FROM student_profiles WHERE user_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $profile = $stmt->fetch() ?: ['display_name' => '', 'school_name' => '', 'grade' => ''];
    $timetableUnavailable = (bool)($profile['timetable_unavailable'] ?? false);
    $stmt = $pdo->prepare('SELECT id FROM timetables WHERE student_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $timetableId = (int)($stmt->fetchColumn() ?: 0);
    if ($timetableId) {
        $stmt = $pdo->prepare('SELECT day_of_week, period_number, subject FROM timetable_entries WHERE timetable_id = ?');
        $stmt->execute([$timetableId]);
        foreach ($stmt->fetchAll() as $entry) $timetable[(int)$entry['day_of_week']][(int)$entry['period_number']] = $entry['subject'];
    }
    $stmt = $pdo->prepare('SELECT subject FROM review_excluded_subjects WHERE student_id = ? ORDER BY subject');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $reviewExcludedSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stmt = $pdo->prepare('SELECT day_of_week, after_school_time, after_school_enabled, night_time, night_enabled FROM notification_schedule WHERE student_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    foreach ($stmt->fetchAll() as $row) $notificationSchedule[(int)$row['day_of_week']] = $row;
    $stmt = $pdo->prepare('SELECT paused, weekly_review_enabled, weekly_review_day, weekly_review_time FROM notification_preferences WHERE student_id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $notificationPrefs = $stmt->fetch() ?: [];
    $notificationsPaused = (bool)($notificationPrefs['paused'] ?? false);
    $weeklyReviewPreferences = ['enabled' => (bool)($notificationPrefs['weekly_review_enabled'] ?? false), 'day' => (int)($notificationPrefs['weekly_review_day'] ?? 6), 'time' => substr((string)($notificationPrefs['weekly_review_time'] ?? '10:00:00'), 0, 5)];
    $studentSetupStatus = student_initial_setup_status($pdo, (int)$_SESSION['user_id']);
    if (!$onboardingRequired && !$activeChatId) {
        $homeNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $homeDate = $homeNow->format('Y-m-d');
        $homeHour = (int)$homeNow->format('G');
        $homeWeekday = (int)$homeNow->format('N');
        $nightReviewDate = $homeHour < 6 ? $homeNow->modify('-1 day')->format('Y-m-d') : $homeDate;
        $scheduledSubjects = $timetableUnavailable ? [] : array_filter($timetable[$homeWeekday] ?? []);
        $afterSchoolAvailable = !$scheduledSubjects || (bool)array_filter($scheduledSubjects, static fn($subject) => !in_array($subject, $reviewExcludedSubjects, true));
        $stmt = $pdo->prepare("SELECT d.review_type, s.ended_at,
            (SELECT MAX(m.id) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') AS last_user_message_id
            FROM daily_review_sessions d JOIN chat_sessions s ON s.id = d.session_id
            WHERE d.student_id = ? AND ((d.review_type = 'after_school' AND d.review_date = ?) OR (d.review_type = 'night' AND d.review_date = ?))");
        $stmt->execute([(int)$_SESSION['user_id'], $homeDate, $nightReviewDate]);
        foreach ($stmt->fetchAll() as $row) $reviewHomeState[$row['review_type']] = $row;
        $weekStart = $homeNow->modify('monday this week')->format('Y-m-d');
        $weekEnd = $homeNow->modify('sunday this week')->format('Y-m-d');
        if ($homeWeekday >= 6 && $homeHour >= 8) {
            try { $weeklyReviewPlan = weekly_review_plan($pdo, $config, (int)$_SESSION['user_id'], $weekStart, $weekEnd); }
            catch (Throwable $e) { error_log('ManaLog weekly review home plan failed: ' . $e->getMessage()); }
        }
        $stmt = $pdo->prepare("SELECT s.ended_at,
            (SELECT MAX(m.id) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') AS last_user_message_id
            FROM weekly_review_sessions w JOIN chat_sessions s ON s.id = w.session_id
            WHERE w.student_id = ? AND w.week_start = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id'], $weekStart]);
        if ($row = $stmt->fetch()) $reviewHomeState['weekly_review'] = $row;
        $stmt = $pdo->prepare('SELECT 1 FROM learning_records WHERE student_id = ? AND learning_date BETWEEN ? AND ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id'], $weekStart, $weekEnd]);
        $hasWeeklyRecords = (bool)$stmt->fetchColumn();
        $latestUnfinishedMessage = 0;
        foreach (['after_school', 'night', 'weekly_review'] as $reviewType) {
            $state = $reviewHomeState[$reviewType] ?? null;
            $messageId = (int)($state['last_user_message_id'] ?? 0);
            if ($state && $state['ended_at'] === null && $messageId > $latestUnfinishedMessage) {
                $recommendedReview = $reviewType;
                $latestUnfinishedMessage = $messageId;
                $recommendedReviewLabel = '続きから';
            }
        }
        if ($recommendedReview === null) {
            $afterSchoolDone = ($reviewHomeState['after_school']['ended_at'] ?? null) !== null;
            $nightDone = ($reviewHomeState['night']['ended_at'] ?? null) !== null;
            $weeklyDone = ($reviewHomeState['weekly_review']['ended_at'] ?? null) !== null;
            if (($homeHour >= 20 || $homeHour < 6) && !$nightDone) $recommendedReview = 'night';
            elseif ($homeWeekday >= 6 && $homeHour >= 9 && $hasWeeklyRecords && !$weeklyDone) $recommendedReview = 'weekly_review';
            elseif ($homeHour >= 15 && $homeHour < 20 && ($homeWeekday <= 5 || $scheduledSubjects) && $afterSchoolAvailable && !$afterSchoolDone) $recommendedReview = 'after_school';
            elseif ($homeHour >= 18 && !$nightDone && $afterSchoolDone) $recommendedReview = 'night';
            if ($recommendedReview !== null) $recommendedReviewLabel = 'いまおすすめ';
        }
    }
    if ($activeChatId) {
        $stmt = $pdo->prepare('SELECT id, session_type, questions_per_subject, review_stage, ended_at FROM chat_sessions WHERE id = ? AND student_id = ?');
        $stmt->execute([$activeChatId, (int)$_SESSION['user_id']]);
        $openedSession = $stmt->fetch();
        if (!$openedSession) $activeChatId = null;
        else {
            $activeChatEnded = $openedSession['ended_at'] !== null;
            $activeChatTitle = $openedSession['session_type'] === 'night' ? '夜の振り返り' : ($openedSession['session_type'] === 'weekly_review' ? '週末の復習' : ($openedSession['session_type'] === 'general' ? '自由に話す' : '帰宅後の振り返り'));
            if ($openedSession['session_type'] === 'weekly_review') $subjectProgress = $activeChatEnded ? '完了' : '今週の復習';
            if ($openedSession['session_type'] === 'after_school') {
                $stmt = $pdo->prepare('SELECT position, review_stage FROM chat_session_subjects WHERE session_id = ? ORDER BY position');
                $stmt->execute([$activeChatId]);
                $reviewSubjects = $stmt->fetchAll();
                $totalSubjects = count($reviewSubjects);
                if ($totalSubjects) {
                    $currentReviewSubject = null;
                    foreach ($reviewSubjects as $reviewSubject) if ($reviewSubject['review_stage'] !== 'completed') { $currentReviewSubject = $reviewSubject; break; }
                    $subjectProgress = $activeChatEnded ? '完了' : ($currentReviewSubject ? subject_progress((int)$currentReviewSubject['position'], $totalSubjects, (string)$currentReviewSubject['review_stage']) : '');
                } else $subjectProgress = $activeChatEnded ? '完了' : review_stage_label((string)$openedSession['review_stage'], 'after_school', $timetableUnavailable);
            } elseif ($openedSession['session_type'] === 'night') {
                $subjectProgress = $activeChatEnded ? '完了' : review_stage_label((string)$openedSession['review_stage'], 'night');
            }
            $stmt = $pdo->prepare('SELECT id, role, content, created_at FROM chat_messages WHERE session_id = ? ORDER BY id');
            $stmt->execute([$activeChatId]);
            $chatMessages = $stmt->fetchAll();
            $photoStmt = $pdo->prepare('SELECT message_id, photo_id FROM learning_photo_chat_messages WHERE session_id = ? ORDER BY position');
            $photoStmt->execute([$activeChatId]);
            $photosByMessage = [];
            foreach ($photoStmt->fetchAll() as $photoRow) $photosByMessage[(int)$photoRow['message_id']][] = (int)$photoRow['photo_id'];
            foreach ($chatMessages as &$chatMessage) $chatMessage['photo_ids'] = $photosByMessage[(int)$chatMessage['id']] ?? [];
            unset($chatMessage);
        }
    }
}
?><!doctype html>
<html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#45795e"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="まなログ"><title>まなログ</title><link rel="manifest" href="manifest.php"><link rel="apple-touch-icon" href="assets/icon-192.png"><link rel="stylesheet" href="assets/app.css?v=20261021-notification-layout"><link rel="stylesheet" href="assets/chat.css?v=20261021-notification-layout"><link rel="stylesheet" href="assets/notification-settings.css?v=20261027-time-placeholder"><?php if (in_array(($_SESSION['role'] ?? ''), ['admin','teacher'], true)): ?><link rel="stylesheet" href="assets/instructor.css?v=20261020-phase4-tenant"><?php endif; ?></head>
<body><main class="shell">
  <header class="brand"><span class="brand-mark">学</span><div><h1>まなログ</h1><p>今日の学びを、少しずつ。</p></div></header>
  <?php if (isset($_SESSION['user_id']) && in_array(($_SESSION['role'] ?? ''), ['admin', 'teacher'], true)): ?>
    <?php if (tenant_admin_authorized($pdo)): ?><section class="card"><p class="eyebrow">塾管理者 · <?= h($organizationName) ?></p><h2>アカウント管理</h2>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
      <details class="profile-edit"><summary>所属塾の設定</summary><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="set_organization_name"><label>塾名<input name="organization_name" value="<?= h($organizationName) ?>" required maxlength="150"></label><button class="small-button" type="submit">塾名を保存</button></form><p class="muted">この管理者は所属塾のアカウントと学習データだけを管理します。サービス全体の運営管理者とは権限を分けています。</p></details>
      <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_student">
        <h3>生徒を登録</h3><p class="muted">初期パスワードは 0000 です。</p><label>ログインID<input name="login_id" required maxlength="100"></label><label>表示名<input name="display_name" required maxlength="100"></label><label>学校名<input name="school_name" maxlength="150"></label><label>学年<input name="grade" maxlength="40" placeholder="例：中学2年"></label><button class="button" type="submit">生徒を登録</button>
      </form>
      <details class="profile-edit"><summary>既存アカウントを塾生徒に連携</summary>
        <p class="muted">名前で自動照合しません。塾DBで確認した kaiin.id を入力してください。連携すると認証先が塾DBになり、既存の学習データ・通知・時間割を引き継ぎます。</p>
        <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="preview_school_link">
          <label>既存のまなログ生徒<select name="student_id" required><option value="">選択してください</option><?php foreach ($students as $student): if ($student['auth_source'] !== 'local' || $student['status'] !== 'active') continue; ?><option value="<?= (int)$student['id'] ?>"><?= h(($student['display_name'] ?: $student['login_id']) . ' · ' . $student['login_id']) ?></option><?php endforeach; ?></select></label>
          <label>塾の kaiin.id<input type="number" name="school_student_id" min="1" step="1" required></label><button class="small-button" type="submit">塾情報を確認</button>
        </form>
        <?php $pendingSchoolLink = $_SESSION['pending_school_link'] ?? null; if (is_array($pendingSchoolLink)): ?>
          <p>連携先を確認してください。</p><ul><li>まなログ: <?= h(($pendingSchoolLink['local_display_name'] ?: '生徒') . ' · ' . $pendingSchoolLink['local_login_id']) ?></li><li>塾: <?= h($pendingSchoolLink['display_name']) ?> · <?= h($pendingSchoolLink['login_id']) ?> (ID <?= (int)$pendingSchoolLink['school_student_id'] ?>)</li></ul>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_school_link"><input type="hidden" name="link_token" value="<?= h($pendingSchoolLink['token']) ?>"><button class="small-button" type="submit">このアカウントを連携する</button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_school_link"><button class="small-button" type="submit">キャンセル</button></form>
        <?php endif; ?>
      </details>
      <h3 class="section-title">登録済み（<?= count($students) ?>名）</h3>
      <?php if (!$students): ?><p class="muted">生徒はまだ登録されていません。</p><?php endif; ?>
      <?php foreach ($students as $student): $setupStatus = student_initial_setup_status($pdo, (int)$student['id']); ?><article class="student-row">
        <div><strong><?= h($student['display_name'] ?: $student['login_id']) ?></strong><p><?= h($student['login_id']) ?> · <?= h($student['school_name'] ?: '学校未設定') ?> · <?= h($student['grade'] ?: '学年未設定') ?> · <?= $student['auth_source'] === 'school' ? '塾DB認証' : 'まなログ認証' ?></p><span class="status <?= $student['status'] === 'active' ? 'active' : '' ?>"><?= $student['status'] === 'active' ? '有効' : '無効' ?></span><p class="setup-status">初期設定：時間割 <?= $setupStatus['timetable_unavailable'] ? '週ごとに変わる' : ($setupStatus['timetable'] ? '済' : '未設定') ?> ／ 通知時刻 <?= $setupStatus['notification_times'] ? '済' : '未設定' ?> ／ Push <?= $setupStatus['push'] ? '有効' : '未設定' ?></p></div>
        <div class="row-actions">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_student"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><button class="small-button" type="submit"><?= $student['status'] === 'active' ? '無効化' : '有効化' ?></button></form>
          <details><summary>時間割設定</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="set_student_timetable_mode"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><label>時間割<select name="timetable_unavailable"><option value="0" <?= empty($student['timetable_unavailable']) ? 'selected' : '' ?>>曜日ごとに登録</option><option value="1" <?= !empty($student['timetable_unavailable']) ? 'selected' : '' ?>>週ごとに変わります</option></select></label><button class="small-button" type="submit">保存</button></form><p class="muted">この設定は選択した生徒だけに適用されます。登録済みの時間割は削除されません。</p></details>
          <?php $savedExcludedSubjects = $excludedSubjectsByStudent[(int)$student['id']] ?? []; $excludedValue = $error && ($_POST['action'] ?? '') === 'set_student_review_exclusions' && (int)($_POST['student_id'] ?? 0) === (int)$student['id'] && is_string($_POST['excluded_subjects'] ?? null) ? $_POST['excluded_subjects'] : implode("\n", $savedExcludedSubjects); ?><details><summary>質問しない教科（<?= count($savedExcludedSubjects) ?>）</summary><p class="muted">道徳など、振り返りで質問しなくてよい教科を1行に1つ入力します。時間割の教科名と同じ表記にしてください。</p><form method="post" class="review-settings"><?= csrf_field() ?><input type="hidden" name="action" value="set_student_review_exclusions"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><label for="admin-excluded-subjects-<?= (int)$student['id'] ?>">質問しない教科</label><textarea id="admin-excluded-subjects-<?= (int)$student['id'] ?>" name="excluded_subjects" rows="3" maxlength="5000" placeholder="道徳&#10;学活"><?= h($excludedValue) ?></textarea><button class="small-button" type="submit">保存</button></form></details>
          <?php if ($student['auth_source'] === 'local'): ?><details><summary>ID・パスワード</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="change_student_login_id"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><label>ログインID<input name="login_id" value="<?= h($student['login_id']) ?>" required maxlength="100" autocomplete="off"></label><button class="small-button" type="submit">IDを変更</button></form><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>"><label>新パスワード<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button class="small-button" type="submit">PWを再設定</button></form></details><?php else: ?><span class="muted">ID・パスワードは塾側で管理</span><?php endif; ?>
        </div>
      </article><?php endforeach; ?>
      <details class="profile-edit"><summary>講師・保護者アカウント</summary>
        <p class="muted">個別ログインIDと12文字以上のパスワードで作成します。保護者には生徒との明示的な紐付けが必要です。</p>
        <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="create_staff_account"><label>アカウント種別<select name="account_role"><option value="teacher">講師</option><option value="parent">保護者</option></select></label><label>ログインID<input name="login_id" required maxlength="100"></label><label>初期パスワード<input type="password" name="password" required minlength="12" autocomplete="new-password"></label><button class="small-button" type="submit">アカウントを作成</button></form>
        <?php $parentAccounts = array_values(array_filter($staffAccounts, static fn(array $account): bool => $account['role'] === 'parent' && $account['status'] === 'active')); $activeStudents = array_values(array_filter($students, static fn(array $student): bool => $student['status'] === 'active')); ?>
        <?php if ($parentAccounts && $activeStudents): ?><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="link_parent_student"><h3>保護者と生徒を紐付け</h3><label>保護者<select name="parent_user_id" required><option value="">選択してください</option><?php foreach ($parentAccounts as $account): ?><option value="<?= (int)$account['id'] ?>"><?= h($account['login_id']) ?></option><?php endforeach; ?></select></label><label>生徒<select name="student_user_id" required><option value="">選択してください</option><?php foreach ($activeStudents as $student): ?><option value="<?= (int)$student['id'] ?>"><?= h(($student['display_name'] ?: $student['login_id']) . ' · ' . $student['login_id']) ?></option><?php endforeach; ?></select></label><button class="small-button" type="submit">紐付けを追加</button></form><?php endif; ?>
        <?php if ($staffAccounts): ?><h3>登録済みアカウント</h3><?php foreach ($staffAccounts as $account): ?><div class="student-row"><span><?= $account['role'] === 'teacher' ? '講師' : '保護者' ?> · <?= h($account['login_id']) ?> · <?= $account['status'] === 'active' ? '有効' : '無効' ?></span><div class="row-actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_staff_account"><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><button class="small-button" type="submit"><?= $account['status'] === 'active' ? '無効化' : '有効化' ?></button></form><details><summary>PW再発行</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_staff_password"><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><label>新パスワード<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button class="small-button" type="submit">再設定</button></form></details></div></div><?php endforeach; ?><?php endif; ?>
        <?php if ($parentLinks): ?><h3>保護者との紐付け</h3><?php foreach ($parentLinks as $parentLink): ?><div class="student-row"><span><?= h($parentLink['parent_login']) ?> → <?= h($parentLink['display_name'] ?: $parentLink['student_login']) ?> (<?= h($parentLink['student_login']) ?>)</span><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unlink_parent_student"><input type="hidden" name="link_id" value="<?= (int)$parentLink['id'] ?>"><button class="small-button" type="submit">解除</button></form></div><?php endforeach; ?><?php endif; ?>
      </details>
      </section><?php endif; ?>
      <?php if ($instructorStudents): ?>
      <section id="instructor-summary" class="card instructor-summary">
        <p class="eyebrow"><?= ($_SESSION['role'] ?? '') === 'teacher' ? '講師' : '講師向け' ?></p><h2>学習サマリー</h2>
        <p class="muted">今回の授業前に、学習記録と確認候補を確認できます。AIの提案は授業判断の参考情報です。</p>
        <form method="get" class="instructor-summary-filter">
          <input type="hidden" name="period_manual" value="<?= $instructorManualPeriod ? '1' : '0' ?>">
          <label>生徒<select name="staff_student_id" required onchange="this.form.elements.period_manual.value='0'"><option value="">生徒を選択</option><?php foreach ($instructorStudents as $staffStudent): ?><option value="<?= (int)$staffStudent['id'] ?>" <?= $instructorSelectedStudent && (int)$instructorSelectedStudent['id'] === (int)$staffStudent['id'] ? 'selected' : '' ?>><?= h(($staffStudent['display_name'] ?: $staffStudent['login_id']) . ($staffStudent['school_student_id'] ? ' · 塾ID ' . $staffStudent['school_student_id'] : ' · まなログ専用')) ?></option><?php endforeach; ?></select></label>
          <label>開始日<input type="date" name="summary_from" value="<?= h($instructorDateFrom) ?>" required onchange="this.form.elements.period_manual.value='1'"></label><label>終了日<input type="date" name="summary_to" value="<?= h($instructorDateTo) ?>" required onchange="this.form.elements.period_manual.value='1'"></label><button class="small-button" type="submit">サマリーを表示</button>
        </form>
        <div class="instructor-roster-wrap"><h3>生徒の最近の状況</h3><div class="table-scroll"><table class="instructor-roster"><thead><tr><th>生徒</th><th>最終記録</th><th>直近7日の記録</th><th>確認候補の単元</th><th>未実施の復習</th><th>写真</th></tr></thead><tbody><?php foreach ($instructorStudents as $staffStudent): ?><tr><td><a href="?staff_student_id=<?= (int)$staffStudent['id'] ?>#instructor-summary"><?= h($staffStudent['display_name'] ?: $staffStudent['login_id']) ?></a></td><td><?= h($staffStudent['last_learning_date'] ?: '記録なし') ?></td><td><?= (int)$staffStudent['recent_learning_count'] ?>件</td><td><?= (int)$staffStudent['weak_unit_count'] ?>件</td><td><?= (int)$staffStudent['pending_review_count'] ?>件</td><td><?= (int)$staffStudent['has_learning_photos'] ? 'あり' : 'なし' ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        <?php if ($instructorError): ?><p class="error"><?= h($instructorError) ?></p><?php endif; ?>
        <?php if ($instructorSelectedStudent && $instructorReport): ?>
          <?php $staffWeekStart = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('monday this week')->format('Y-m-d'); $staffWeeklyPlan = weekly_review_load_plan($pdo, (int)$instructorSelectedStudent['id'], $staffWeekStart); if ($staffWeeklyPlan) render_weekly_review_plan($staffWeeklyPlan, 'staff'); ?>
          <div class="instructor-summary-meta"><strong><?= h($instructorSelectedStudent['display_name'] ?: $instructorSelectedStudent['login_id']) ?></strong><span><?= h($instructorDateFrom) ?> 〜 <?= h($instructorDateTo) ?></span><span><?= h($instructorPeriodSource) ?><?= $instructorPreviousLessonDate ? '（' . h($instructorPreviousLessonDate) . '）' : '' ?></span><span><?= $instructorReport['cached'] ? '保存済みサマリー' : '最新記録から生成' ?> · <?= h($instructorReport['generated_at']) ?></span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_instructor_summary"><input type="hidden" name="summary_student_id" value="<?= (int)$instructorSelectedStudent['id'] ?>"><input type="hidden" name="summary_from" value="<?= h($instructorDateFrom) ?>"><input type="hidden" name="summary_to" value="<?= h($instructorDateTo) ?>"><button class="small-button" type="submit">最新の記録で再生成</button></form>
          </div>
          <?php $staffData = $instructorReport['data']; $staffSummary = $instructorReport['summary']; $staffRecordMap = array_column($staffData['records'], null, 'id'); $staffQuestionMap = array_column($staffData['questions'], null, 'id'); $staffPhotoMap = array_column($staffData['photos'], null, 'id'); $staffRecordRefs = static function (array $ids) use ($staffRecordMap): string { $links = []; foreach ($ids as $id) if (isset($staffRecordMap[(int)$id])) $links[] = '<a href="#staff-record-' . (int)$id . '">記録 #' . (int)$id . '</a>'; return implode(' ', $links); }; $staffEvidence = static function (array $item) use ($staffRecordMap, $staffQuestionMap, $staffPhotoMap, $staffRecordRefs): string { $parts = []; if (!empty($item['evidence_record_ids'])) $parts[] = $staffRecordRefs($item['evidence_record_ids']); foreach (($item['evidence_question_ids'] ?? []) as $id) if (isset($staffQuestionMap[(int)$id])) $parts[] = '<a href="#staff-question-' . (int)$id . '">質問 #' . (int)$id . '</a>'; foreach (['evidence_problem_ids' => '問題', 'evidence_photo_ids' => '写真'] as $key => $label) foreach (($item[$key] ?? []) as $id) { if ($label === '問題') $parts[] = '<a href="#staff-problem-' . (int)$id . '">問題 #' . (int)$id . '</a>'; elseif (isset($staffPhotoMap[(int)$id])) $parts[] = '<a href="?staff_photo=' . (int)$id . '" target="_blank" rel="noopener">写真 #' . (int)$id . '</a>'; } return implode(' · ', $parts); }; ?>
          <?php if (!$staffData['records']): ?><p class="instructor-empty">対象期間に学習記録はありません。</p><?php else: ?>
          <?php if ($staffData['truncated']): ?><p class="instructor-truncated">対象件数が多いため、直近150件をサマリー対象にしています。期間を短くすると全体を確認できます。</p><?php endif; ?>
          <section class="instructor-priorities"><h3>今回の授業で確認したいこと</h3><p class="muted">AIによる候補です。授業での確認内容は講師が判断してください。</p>
            <?php if (!$staffSummary['recommendations']): ?><p class="instructor-empty">この期間の記録から、具体的な確認候補は挙がりませんでした。</p><?php endif; ?>
            <?php foreach ($staffSummary['recommendations'] as $index => $recommendation): ?><article class="instructor-priority"><span class="priority-number">優先 <?= $index + 1 ?></span><h4><?= h(trim(($recommendation['subject'] ?? '') . '／' . ($recommendation['unit'] ?? ''), '／')) ?></h4><p><strong>確認内容：</strong><?= h($recommendation['check_content']) ?></p><p><strong>理由：</strong><?= h($recommendation['reason']) ?></p><p><strong>確認方法：</strong><?= h($recommendation['how_to_check']) ?></p><?php $evidenceHtml = $staffEvidence($recommendation); if ($evidenceHtml): ?><p class="instructor-evidence"><strong>根拠：</strong><?= $evidenceHtml ?></p><?php endif; ?></article><?php endforeach; ?>
          </section>
          <div class="instructor-summary-grid">
            <section class="instructor-panel"><h3>学校で習った内容</h3><?php if (!$staffSummary['school_topics']): ?><p class="muted">記録なし</p><?php endif; ?><?php foreach ($staffSummary['school_topics'] as $topic): ?><article><strong><?= h(trim(($topic['subject'] ?? '') . '／' . ($topic['unit'] ?? ''), '／')) ?></strong><p><?= h($topic['summary']) ?></p><?php $evidenceHtml = $staffEvidence($topic); if ($evidenceHtml): ?><small>根拠: <?= $evidenceHtml ?></small><?php endif; ?></article><?php endforeach; ?></section>
            <section class="instructor-panel"><h3>理解度・つまずき</h3><?php if (!$staffSummary['understanding_points']): ?><p class="muted">記録なし</p><?php endif; ?><?php $understandingKindLabels = ['self_report' => '本人の申告', 'answered' => 'AI質問への回答', 'unanswered' => '未回答', 'needs_review' => '確認候補', 'progress' => '過去との比較']; foreach ($staffSummary['understanding_points'] as $point): ?><article><strong><?= h($understandingKindLabels[$point['kind']] ?? '記録') ?> · <?= h(trim(($point['subject'] ?? '') . '／' . ($point['unit'] ?? ''), '／')) ?></strong><p><?= h($point['summary']) ?></p><?php $evidenceHtml = $staffEvidence($point); if ($evidenceHtml): ?><small>根拠: <?= $evidenceHtml ?></small><?php endif; ?></article><?php endforeach; ?>
              <details class="instructor-observation-history"><summary>理解度の元記録（<?= count($staffData['observations']) ?>件）</summary><?php if (!$staffData['observations']): ?><p class="muted">記録なし</p><?php endif; ?><?php $observationSourceLabels = ['student_self_report' => '本人の申告', 'ai_estimate' => 'AI推定', 'question_response' => 'AI質問への回答', 'later_review' => '後日の復習']; $understandingLevelLabels = ['not_understood' => 'まだ分からない', 'needs_help' => '助けが必要', 'partial' => '一部わかる', 'mostly_understood' => 'だいたい分かる', 'confident' => '自信あり']; foreach ($staffData['observations'] as $observation): ?><article><strong><?= h($observation['observed_at']) ?> · <?= h($observation['subject'] . '／' . $observation['unit_name']) ?></strong><p><?= h($observationSourceLabels[$observation['observation_source']] ?? $observation['observation_source']) ?>: <?= h($understandingLevelLabels[$observation['understanding_level']] ?? $observation['understanding_level']) ?><?php if ($observation['confidence'] !== null): ?> · AI信頼度 <?= h(number_format((float)$observation['confidence'], 2)) ?><?php endif; ?></p><?php if ($observation['evidence_note']): ?><small><?= h($observation['evidence_note']) ?></small><?php endif; ?><?php if ($observation['learning_record_id']): ?><small> · 学習記録 #<?= (int)$observation['learning_record_id'] ?></small><?php endif; ?></article><?php endforeach; ?></details>
            </section>
            <section class="instructor-panel"><h3>家庭学習</h3><?php if (!$staffSummary['home_study']): ?><p class="muted">記録なし</p><?php endif; ?><?php foreach ($staffSummary['home_study'] as $study): ?><article><strong><?= h(trim(($study['subject'] ?? '') . '／' . ($study['unit'] ?? ''), '／')) ?></strong><p><?= h($study['summary']) ?></p><?php $evidenceHtml = $staffEvidence($study); if ($evidenceHtml): ?><small>根拠: <?= $evidenceHtml ?></small><?php endif; ?></article><?php endforeach; ?></section>
            <section class="instructor-panel"><h3>その他の学習</h3><?php if (!$staffSummary['other_study']): ?><p class="muted">記録なし</p><?php endif; ?><?php foreach ($staffSummary['other_study'] as $study): ?><article><strong><?= h(trim(($study['subject'] ?? '') . '／' . ($study['unit'] ?? ''), '／')) ?></strong><p><?= h($study['summary']) ?></p><?php $evidenceHtml = $staffEvidence($study); if ($evidenceHtml): ?><small>根拠: <?= $evidenceHtml ?></small><?php endif; ?></article><?php endforeach; ?></section>
          </div>
          <section class="instructor-records"><h3>学習記録・写真・会話の根拠</h3><?php foreach ($staffData['records'] as $record): ?><details class="instructor-record" id="staff-record-<?= (int)$record['id'] ?>"><summary><?= h($record['learning_date'] . ' · ' . ($record['subject'] ?: '科目未特定') . ($record['unit_name'] ? ' · ' . $record['unit_name'] : '')) ?> <span><?= h(['school' => '学校', 'homework' => '家庭学習', 'juku' => '塾', 'other' => 'その他', 'unknown' => '分類なし'][$record['activity_type']] ?? '学習') ?></span></summary>
              <?php if ($record['summary']): ?><p><?= nl2br(h($record['summary'])) ?></p><?php endif; ?><?php if ($record['study_minutes'] !== null): ?><p>学習時間: <?= (int)$record['study_minutes'] ?>分</p><?php endif; ?><?php if ($record['not_understood']): ?><p><strong>分からなかった内容:</strong> <?= h($record['not_understood']) ?></p><?php endif; ?><?php if ($record['next_check']): ?><p><strong>次回確認:</strong> <?= h($record['next_check']) ?></p><?php endif; ?>
              <?php foreach ($record['questions'] as $question): ?><article class="instructor-evidence-row" id="staff-question-<?= (int)$question['id'] ?>"><strong>AI質問 #<?= (int)$question['id'] ?> · <?= h($question['answer_status']) ?></strong><p><?= h($question['question_text']) ?></p><?php if ($question['answer_text']): ?><p>回答: <?= h($question['answer_text']) ?></p><?php endif; ?><?php if ($question['ai_estimated_understanding']): ?><small>AI推定: <?= h($question['ai_estimated_understanding']) ?><?= $question['understanding_note'] ? ' · ' . h($question['understanding_note']) : '' ?></small><?php endif; ?></article><?php endforeach; ?>
              <?php foreach ($record['problems'] as $problem): $problemResultLabels = ['correct' => '○', 'partially_correct' => '△', 'incorrect' => '×', 'ungraded' => '未判定']; $problemSourceLabels = ['student_mark' => '写真上の生徒の印', 'ai_estimate' => 'AI推定', 'teacher_confirmed' => '講師確認', 'unknown' => '判定根拠不明']; ?><article class="instructor-evidence-row" id="staff-problem-<?= (int)$problem['id'] ?>"><strong>問題 #<?= (int)$problem['id'] ?> · <?= h($problemResultLabels[$problem['answer_result']] ?? '未判定') ?>（<?= h($problemSourceLabels[$problem['result_source']] ?? '判定根拠不明') ?>）</strong><p><?= h(trim(($problem['material_name'] ?? '') . ' ' . ($problem['page_number'] ? $problem['page_number'] . 'ページ ' : '') . ($problem['problem_number'] ? $problem['problem_number'] . '番' : ''))) ?></p><?php if ($problem['problem_text']): ?><p><?= h(mb_substr($problem['problem_text'], 0, 500)) ?></p><?php endif; ?><?php if ($problem['student_answer']): ?><small>解答: <?= h(mb_substr($problem['student_answer'], 0, 400)) ?></small><?php endif; ?></article><?php endforeach; ?>
              <?php if ($record['photos']): ?><div class="instructor-photos"><?php foreach ($record['photos'] as $photo): ?><a href="?staff_photo=<?= (int)$photo['id'] ?>" target="_blank" rel="noopener"><img src="?staff_photo=<?= (int)$photo['id'] ?>" alt="学習写真 <?= (int)$photo['id'] ?>" loading="lazy"><span>写真 #<?= (int)$photo['id'] ?>・<?= h($photo['analysis_status']) ?></span></a><?php endforeach; ?></div><?php endif; ?>
            </details><?php endforeach; ?></section>
          <?php endif; ?>
        <?php endif; ?>
      </section>
      <?php endif; ?>
      <?php if (tenant_admin_authorized($pdo)): ?><section class="card"><details class="profile-edit"><summary>通知送信履歴</summary><?php if (!$notificationHistory && !$weeklyNotificationHistory): ?><p class="muted">送信履歴はまだありません。</p><?php endif; ?><?php foreach ($notificationHistory as $delivery): ?><article class="delivery-row"><strong><?= h($delivery['display_name'] ?: '生徒') ?> · <?= h($delivery['review_date']) ?> · <?= $delivery['notification_type'] === 'night' ? '夜' : '帰宅後' ?></strong><span><?= h($delivery['status']) ?><?= $delivery['sent_at'] ? ' · ' . h($delivery['sent_at']) : '' ?></span><?php if ($delivery['last_error']): ?><p><?= h($delivery['last_error']) ?></p><?php endif; ?><?php if ($delivery['resend_status'] !== null): ?><span>再送: <?= h($delivery['resend_status']) ?><?= $delivery['resend_sent_at'] ? ' · ' . h($delivery['resend_sent_at']) : '' ?></span><?php if ($delivery['resend_error']): ?><p><?= h($delivery['resend_error']) ?></p><?php endif; ?><?php endif; ?></article><?php endforeach; ?><?php foreach ($weeklyNotificationHistory as $delivery): ?><article class="delivery-row"><strong><?= h($delivery['display_name'] ?: '生徒') ?> · <?= h($delivery['week_start']) ?>週 · 週末復習</strong><span><?= h($delivery['status']) ?><?= $delivery['sent_at'] ? ' · ' . h($delivery['sent_at']) : '' ?></span><?php if ($delivery['last_error']): ?><p><?= h($delivery['last_error']) ?></p><?php endif; ?></article><?php endforeach; ?></details></section><?php endif; ?>
      <section class="card"><a class="button secondary" href="?logout=1">ログアウト</a></section>
  <?php elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'parent'): ?>
    <section class="card"><p class="eyebrow">保護者向け</p><h2>学習状況</h2><p class="muted">管理者が紐付けたお子さまの学習記録を表示します。AIとの会話全文は表示しません。</p>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
      <?php if (!$parentChildren): ?><p class="muted">閲覧できるお子さまがまだ登録されていません。管理者に紐付けを依頼してください。</p><?php else: ?>
        <form method="get" class="learning-filters"><label>お子さま<select name="child_id" required><option value="">選択してください</option><?php foreach ($parentChildren as $child): ?><option value="<?= (int)$child['id'] ?>" <?= $parentSelectedChild && (int)$parentSelectedChild['id'] === (int)$child['id'] ? 'selected' : '' ?>><?= h(($child['display_name'] ?: $child['login_id']) . ($child['grade'] ? ' · ' . $child['grade'] : '')) ?></option><?php endforeach; ?></select></label><button class="small-button" type="submit">学習状況を表示</button></form>
        <?php if ($parentSelectedChild): ?>
          <h3><?= h($parentSelectedChild['display_name'] ?: $parentSelectedChild['login_id']) ?>さんの最近の学習</h3>
          <?php if ($parentSnapshot): ?><div class="parent-overview"><article><span>最終学習記録</span><strong><?= h($parentSnapshot['last_learning_date'] ?: '記録なし') ?></strong></article><article><span>直近7日の学習記録</span><strong><?= (int)($parentSnapshot['recent_records'] ?? 0) ?>件</strong></article><article><span>理解の確認候補</span><strong><?= (int)$parentSnapshot['needs_support_count'] ?>件</strong></article><article><span>未実施の復習</span><strong><?= (int)$parentSnapshot['pending_reviews'] ?>件</strong></article><article><span>学習写真</span><strong><?= (int)$parentSnapshot['has_photos'] ? 'あり' : 'なし' ?></strong></article></div><?php endif; ?>
          <?php $parentWeekStart = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->modify('monday this week')->format('Y-m-d'); $parentWeeklyPlan = weekly_review_load_plan($pdo, (int)$parentSelectedChild['id'], $parentWeekStart); if ($parentWeeklyPlan) render_weekly_review_plan($parentWeeklyPlan, 'parent', (int)$parentSelectedChild['id']); ?>
          <?php render_learning_list($pdo, (int)$parentSelectedChild['id'], true, (int)$parentSelectedChild['id']); ?>
        <?php endif; ?>
      <?php endif; ?>
      <a class="button secondary" href="?logout=1">ログアウト</a>
    </section>
  <?php elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student' && $mustChangePassword): ?>
    <section class="card"><p class="eyebrow">初回ログイン</p><h2>パスワードを変更</h2><p class="muted">初期パスワード 0000 を変更してください。新しいパスワードは12文字以上で入力します。</p>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="change_password"><label>新しいパスワード<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><label>新しいパスワード（確認）<input type="password" name="new_password_confirm" minlength="12" autocomplete="new-password" required></label><button class="button" type="submit">パスワードを変更</button></form>
      <a class="button secondary" href="?logout=1">ログアウト</a>
    </section>
  <?php elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student' && $onboardingRequired): ?>
    <section class="card onboarding-card">
      <p class="eyebrow">はじめての設定 · <?= $onboardingStep ?>/3</p>
      <h2><?= h(match ($onboardingStep) { 1 => 'ホーム画面と通知', 2 => '時間割を登録', default => '通知希望時刻' }) ?></h2>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
      <div class="onboarding-progress" aria-label="初回設定の進行状況"><?php for ($step = 1; $step <= 3; $step++): ?><span class="<?= $step <= $onboardingStep ? 'current' : '' ?>"></span><?php endfor; ?></div>
      <?php if ($onboardingStep === 1): ?>
        <p class="muted">通知に気づきやすくするため、まずホーム画面への追加と、この端末の通知を設定します。通知を許可しなくても、あとで設定してチャットを利用できます。</p>
        <div id="install-panel" class="onboarding-install"><p id="install-status" class="muted">この端末での追加方法を確認しています…</p><button id="install-button" class="small-button" type="button" hidden>ホーム画面に追加</button></div>
        <div id="push-panel" class="push-panel" data-onboarding="1" data-vapid-key="<?= h($config['vapid_public_key'] ?? '') ?>" data-csrf="<?= h($_SESSION['csrf']) ?>"><h3>端末の通知</h3><button id="push-subscribe" class="small-button" type="button" disabled>通知の状態を確認中…</button><p id="push-status" class="muted" role="status">確認中…</p></div>
        <form method="post" class="onboarding-finish"><?= csrf_field() ?><input type="hidden" name="action" value="continue_onboarding_after_push"><button class="button" type="submit">時間割の設定へ進む</button></form>
      <?php elseif ($onboardingStep === 2): ?>
        <p class="muted">時間割を写真から読み取るか、下の表に入力してください。週ごとに時間割が変わる場合は「時間割は週ごとに変わります」を選べます。</p>
        <div class="timetable-photo-card"><strong>📷 時間割の写真から取り込む</strong><p>写真を選ぶか撮影し、読み取り結果を確認してから登録します。</p><form method="post" enctype="multipart/form-data" class="image-upload"><?= csrf_field() ?><input type="hidden" name="action" value="parse_timetable"><label>時間割の写真（5MBまで）<input type="file" name="timetable_image" accept="image/jpeg,image/png,image/webp" required></label><button class="small-button" type="submit">写真を読み取る</button></form></div>
        <form method="post" class="no-timetable-option"><?= csrf_field() ?><input type="hidden" name="action" value="mark_no_timetable"><button class="small-button" type="submit">時間割は週ごとに変わります</button></form>
        <form method="post" class="timetable-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_timetable"><div class="table-scroll"><table class="timetable"><thead><tr><th>限</th><?php foreach ([1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土'] as $name): ?><th><?= h($name) ?></th><?php endforeach; ?></tr></thead><tbody><?php for ($period = 1; $period <= 8; $period++): ?><tr><th><?= $period ?></th><?php foreach ([1, 2, 3, 4, 5, 6] as $day): ?><td><input aria-label="<?= $day ?>曜日 <?= $period ?>限" name="subjects[<?= $day ?>][<?= $period ?>]" value="<?= h($timetableDraft[$day][$period] ?? $timetable[$day][$period] ?? '') ?>" maxlength="100"></td><?php endforeach; ?></tr><?php endfor; ?></tbody></table></div><button class="button" type="submit">時間割を保存して次へ</button></form>
      <?php elseif ($onboardingStep === 3): ?>
        <p class="muted">曜日ごとに帰宅後・夜の通知時刻とON/OFFを設定します。夜中の時刻は「翌日」を選びます。空欄にすると通知しません。</p>
        <form method="post" class="notification-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_notifications">
          <div class="notification-copy-tools"><button class="small-button" type="button" data-copy-notification-time="after_school">入力した帰宅後時刻を全曜日にコピー</button><button class="small-button" type="button" data-copy-notification-time="night">入力した夜の時刻を全曜日にコピー</button><p class="muted" role="status" aria-live="polite"></p></div>
          <table class="notification-schedule"><thead><tr><th>曜日</th><th>帰宅後</th><th>夜</th></tr></thead><tbody><?php foreach ([1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'] as $day => $name): ?><tr><th><?= $name ?></th><?php foreach (['after_school' => 'after_school_time', 'night' => 'night_time'] as $type => $column): ?><?php $row = $notificationSchedule[$day] ?? []; $posted = $_POST['times'][$day][$type] ?? null; $databaseUiTime = notification_time_input_value($row[$column] ?? null); $hasPostedError = ($_POST['action'] ?? '') === 'save_notifications' && $error; $value = $hasPostedError && is_string($posted) ? $posted : ($type === 'night' ? $databaseUiTime['time'] : substr((string)($row[$column] ?? ''), 0, 5)); $nextDay = $hasPostedError ? isset($_POST['night_next_day'][$day]) : $databaseUiTime['next_day']; $enabled = $hasPostedError ? isset($_POST['notification_enabled'][$day][$type]) : (bool)($row[$type . '_enabled'] ?? true); ?><td><div class="notification-time-control"><input type="time" placeholder="22:00" aria-label="<?= $name ?>曜日 <?= $type === 'after_school' ? '帰宅後' : '夜' ?>の時刻" name="times[<?= $day ?>][<?= $type ?>]" value="<?= h($value) ?>"><?php if ($value === ''): ?><span class="time-example">例 22:00</span><?php endif; ?><?php if ($type === 'night'): ?><label class="night-next-day"><input type="checkbox" name="night_next_day[<?= $day ?>]" value="1" <?= $nextDay ? 'checked' : '' ?>><span>翌日</span></label><?php endif; ?></div><label class="notification-toggle"><input type="checkbox" name="notification_enabled[<?= $day ?>][<?= $type ?>]" value="1" <?= $enabled ? 'checked' : '' ?>><span>ON</span></label></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table><button class="button" type="submit">通知時刻を保存して設定完了</button></form>
        <p class="muted">通知時刻はあとから「設定」で変更できます。時刻を決めずに進む場合は、下のボタンを使ってください。</p>
        <form method="post" class="onboarding-finish"><?= csrf_field() ?><input type="hidden" name="action" value="complete_onboarding"><button class="button secondary" type="submit">時刻はあとで設定してホームへ</button></form>
      <?php endif; ?>
      <a class="button secondary" href="?logout=1">ログアウト</a>
    </section>
  <?php elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student' && $viewMode === 'parent'): ?>
    <section class="card">
      <p class="eyebrow">保護者向け表示</p><h2><?= h($profile['display_name'] ?? '') ?>さんの学習状況</h2>
      <p class="muted">このアカウントを共有している本人も閲覧できます。AIとの会話内容は表示しません。</p>
      <?php render_learning_list($pdo, (int)$_SESSION['user_id'], true); ?>
      <a class="button secondary" href="?logout=1">ログアウト</a>
      <form method="post" class="mode-switch-bottom"><?= csrf_field() ?><input type="hidden" name="action" value="set_view_mode"><input type="hidden" name="view_mode" value="student"><button class="small-button" type="submit">生徒モードへ切り替え</button></form>
    </section>
  <?php elseif (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'student'): ?>
    <section class="card student-home"><p class="eyebrow">生徒ホーム · <?= h((new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y年n月j日')) ?></p><h2><?= h($profile['display_name'] ?? '') ?>さん、こんにちは</h2><?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if ($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
      <?php if ($studentSetupStatus): ?><section id="initial-setup-guide" class="initial-setup-guide" data-timetable-ready="<?= $studentSetupStatus['timetable'] ? '1' : '0' ?>" data-notification-times-ready="<?= $studentSetupStatus['notification_times'] ? '1' : '0' ?>" data-push-ready="<?= $studentSetupStatus['push'] ? '1' : '0' ?>" <?= $studentSetupStatus['timetable'] && $studentSetupStatus['notifications'] ? 'hidden' : '' ?>><h3>はじめの設定</h3><p class="muted">まだの項目を設定すると、振り返りをあなたの時間割や通知に合わせられます。設定しなくてもチャットは使えます。</p><div class="setup-guide-items"><div class="setup-guide-item" data-setup-item="timetable" <?= $studentSetupStatus['timetable'] ? 'hidden' : '' ?>><span class="setup-guide-state">未設定</span><strong>時間割</strong><p>学校の教科を登録します。</p><a class="small-button" href="#timetable-settings">時間割を設定</a></div><div class="setup-guide-item" data-setup-item="notifications" <?= $studentSetupStatus['notifications'] ? 'hidden' : '' ?>><span class="setup-guide-state">未設定</span><strong>通知</strong><p>通知時刻とこの端末のPush通知を設定します。許可は通知ボタンを押したときだけ確認します。</p><a class="small-button" href="#notification-settings">通知設定を見る</a><p class="setup-guide-help">iPhoneではホーム画面に追加したまなログから通知を設定できます。対応していない場合や通知が拒否済みの場合は、設定欄に理由と案内を表示します。</p></div></div></section><?php endif; ?>
      <?php $today = (int)(new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('N'); $dayNames = [1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土']; $todaySubjects = array_filter($timetable[$today] ?? []); ?>
      <div class="today-box"><strong>今日の時間割（<?= $today <= 6 ? h($dayNames[$today]) . '曜日' : '日曜日' ?>）</strong><p><?php ksort($todaySubjects); echo $timetableUnavailable ? '時間割は週ごとに変わる設定です。帰宅後の振り返りでは1時間目の教科から聞きます。' : ($todaySubjects ? h(implode('・', array_map(fn($period, $subject) => $period . '時間目 ' . $subject, array_keys($todaySubjects), array_values($todaySubjects)))) : '時間割はまだ登録されていません。'); ?></p></div>
      <?php if ($weeklyReviewPlan): ?><?php render_weekly_review_plan($weeklyReviewPlan); ?><?php endif; ?>
      <?php if ($activeChatId): ?>
        <section class="chat-panel">
          <div class="chat-heading"><h3 id="chat-title"><?= h($activeChatTitle) ?><?= $subjectProgress ? ' · ' . h($subjectProgress) : '' ?></h3><a href="./">ホーム</a></div>
          <p id="chat-error" class="error" <?= $error ? '' : 'hidden' ?>><?= h($error) ?></p>
          <div id="chat-messages" class="messages"><?php foreach ($chatMessages as $chatMessage): ?><div class="bubble <?= $chatMessage['role'] === 'user' ? 'mine' : 'theirs' ?>"><?= h($chatMessage['content']) ?><?php foreach ($chatMessage['photo_ids'] as $photoId): ?><a class="chat-photo-link" href="?photo=<?= (int)$photoId ?>" target="_blank" rel="noopener"><img class="chat-photo" src="?photo=<?= (int)$photoId ?>" alt="送信した学習写真" loading="lazy"></a><?php endforeach; ?></div><?php endforeach; ?></div>
          <?php if (!$activeChatEnded): ?>
            <form id="chat-form" method="post" class="chat-form"><?= csrf_field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="session_id" value="<?= (int)$activeChatId ?>">
              <label class="sr-only" for="chat-message">メッセージ</label><textarea id="chat-message" name="message" maxlength="2000" rows="3" placeholder="話してみよう"><?= h($_POST['message'] ?? '') ?></textarea>
              <div class="chat-controls"><label class="small-button photo-pick" for="chat-photos">📷 写真を追加</label><input id="chat-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" multiple hidden><span id="photo-status" class="muted" aria-live="polite"></span><button class="small-button" id="voice-button" type="button" hidden>🎙 会話モードを開始</button><span id="voice-status" class="muted" aria-live="polite"></span><button class="button" id="send-button" type="submit">送信</button></div>
              <p class="muted photo-help">問題集に正誤の印があれば一緒に読み取ります。印のない問題集のあとにノートを送ると、番号や問題文が一致したものだけ関連付けます。写真は1回4枚までです。</p>
            </form>
          <?php endif; ?>
        </section>
      <?php endif; ?>
      <?php if (!$activeChatId): ?>
        <section class="review-choices" aria-labelledby="review-choices-title">
          <div class="review-choices-heading"><h3 id="review-choices-title">今日の学び</h3><p class="muted"><?= $recommendedReview !== null ? '色のついた項目がおすすめです。ほかの項目も選べます。' : '今は好きな項目から始められます。' ?></p></div>
          <div class="review-choice-grid">
            <?php foreach ([
                ['after_school', 'start_chat', '帰宅後の振り返り', $timetableUnavailable ? 'まず1時間目の教科を教えてね' : '学校で習ったことを思い出そう'],
                ['night', 'start_night', '夜の振り返り', '家での勉強を振り返ろう'],
                ['weekly_review', 'start_weekly_review', '今週の復習', '今週の記録を使って復習しよう'],
                ['general', 'start_general', '自由に話す', '気になることを話してみよう'],
            ] as [$reviewType, $actionName, $buttonLabel, $description]):
                $state = $reviewHomeState[$reviewType] ?? null;
                $completed = $state && $state['ended_at'] !== null;
                $inProgress = $state && !$completed && (int)($state['last_user_message_id'] ?? 0) > 0;
                $unavailable = ($reviewType === 'after_school' && !$afterSchoolAvailable && !$state)
                    || ($reviewType === 'weekly_review' && empty($hasWeeklyRecords) && !$state);
                $status = $reviewType === 'weekly_review' && $completed ? '今週は完了'
                    : ($completed ? '今日は完了' : ($inProgress ? '続きから再開' : ($unavailable ? ($reviewType === 'after_school' ? '今日の教科は質問しない設定です' : '今週の学習記録ができると始められます') : $description)));
                $isRecommended = $recommendedReview === $reviewType && !$unavailable;
            ?>
              <form method="post" class="review-choice-card<?= $isRecommended ? ' is-recommended' : '' ?><?= $unavailable ? ' is-unavailable' : '' ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= h($actionName) ?>">
                <?php if ($isRecommended): ?><span class="review-choice-badge"><?= h($recommendedReviewLabel) ?></span><?php endif; ?>
                <button class="review-choice-button" type="submit" <?= $unavailable ? 'disabled' : '' ?>><?= h($buttonLabel) ?></button>
                <span class="review-choice-status"><?= h($status) ?></span>
              </form>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
      <?php render_learning_list($pdo, (int)$_SESSION['user_id']); ?>
      <details class="profile-edit app-settings dashboard-disclosure"><summary>設定</summary>
      <details class="profile-edit" id="timetable-settings"><summary>時間割<?= $timetableUnavailable ? '（週ごとに変わります）' : '' ?></summary>
        <p class="muted">写真を選ぶか撮影して読み取り、結果を確認して登録できます。手入力もできます。個人名や学校名が写っている場合は、先に画像から切り取ってください。</p>
        <div class="timetable-photo-card"><strong>📷 時間割の写真から取り込む</strong><p>写真を選ぶか撮影し、読み取り結果を確認してから登録します。</p><form method="post" enctype="multipart/form-data" class="image-upload"><?= csrf_field() ?><input type="hidden" name="action" value="parse_timetable"><label>時間割の写真（5MBまで）<input type="file" name="timetable_image" accept="image/jpeg,image/png,image/webp" required></label><button class="small-button" type="submit">写真を読み取る</button></form></div>
        <?php if (!$timetableUnavailable): ?><form method="post" class="no-timetable-option"><?= csrf_field() ?><input type="hidden" name="action" value="mark_no_timetable"><button class="small-button" type="submit">時間割は週ごとに変わります</button></form><?php else: ?><p class="muted timetable-missing-note">時間割は週ごとに変わる設定です。表を入力して保存すると、登録した時間割に戻ります。</p><?php endif; ?>
        <form method="post" class="timetable-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_timetable"><div class="table-scroll"><table class="timetable"><thead><tr><th>限</th><?php foreach ($dayNames as $name): ?><th><?= h($name) ?></th><?php endforeach; ?></tr></thead><tbody><?php for ($period = 1; $period <= 8; $period++): ?><tr><th><?= $period ?></th><?php foreach ($dayNames as $day => $name): ?><td><input aria-label="<?= h($name) ?>曜日 <?= $period ?>限" name="subjects[<?= $day ?>][<?= $period ?>]" value="<?= h($timetableDraft[$day][$period] ?? $timetable[$day][$period] ?? '') ?>" maxlength="100"></td><?php endforeach; ?></tr><?php endfor; ?></tbody></table></div><button class="button" type="submit">時間割を保存</button></form>
        <?php if ($timetable || $timetableDraft): ?><form method="post" class="delete-timetable-form" onsubmit="return confirm('登録済みの時間割をすべて削除します。よろしいですか？');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_timetable"><button class="button danger-button" type="submit">時間割をすべて削除</button></form><?php endif; ?></details>
      <details class="profile-edit"><summary>質問しない教科</summary><p class="muted">道徳など、振り返りで質問しなくてよい教科を1行に1つ入力してください。時間割の教科名と同じ表記にすると、どの曜日でも質問から外れます。</p><form method="post" class="review-settings"><?= csrf_field() ?><input type="hidden" name="action" value="save_review_exclusions"><label for="excluded-subjects">質問しない教科</label><textarea id="excluded-subjects" name="excluded_subjects" rows="4" maxlength="5000" placeholder="道徳&#10;学活"><?= h(($_POST['action'] ?? '') === 'save_review_exclusions' && $error ? ($_POST['excluded_subjects'] ?? '') : implode("\n", $reviewExcludedSubjects)) ?></textarea><button class="button" type="submit">設定を保存</button></form></details>
      <details class="profile-edit" id="notification-settings"><summary>通知希望時刻・Push通知</summary>
        <p class="muted">曜日ごとに時刻とON/OFFを設定できます。夜中の時刻は「翌日」を選択します（例：水曜の翌日1:00は木曜午前1時）。入力した時刻は同じ種類の全曜日へコピーできます。</p>
        <form method="post" class="notification-form"><?= csrf_field() ?><input type="hidden" name="action" value="save_notifications">
          <label class="pause-control"><input type="checkbox" name="notifications_paused" value="1" <?= (($_POST['action'] ?? '') === 'save_notifications' && $error ? isset($_POST['notifications_paused']) : $notificationsPaused) ? 'checked' : '' ?>>通知を一時停止する</label>
          <?php $weeklyPosted = ($_POST['action'] ?? '') === 'save_notifications' && $error; ?>
          <fieldset class="weekly-notification-settings"><legend>週末の復習通知</legend><label class="weekly-review-toggle"><input type="checkbox" name="weekly_review_enabled" value="1" <?= ($weeklyPosted ? isset($_POST['weekly_review_enabled']) : $weeklyReviewPreferences['enabled']) ? 'checked' : '' ?>><span>週末に通知を受け取る</span></label><label>曜日<select name="weekly_review_day"><?php foreach ([6 => '土曜日', 7 => '日曜日'] as $day => $label): ?><option value="<?= $day ?>" <?= (int)($weeklyPosted ? ($_POST['weekly_review_day'] ?? 6) : $weeklyReviewPreferences['day']) === $day ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><label>時刻<input type="time" name="weekly_review_time" placeholder="22:00" value="<?= h($weeklyPosted ? ($_POST['weekly_review_time'] ?? '10:00') : $weeklyReviewPreferences['time']) ?>"></label><p class="muted">帰宅後・夜の通知と2時間以内に重なる場合は、週末通知を省略します。</p></fieldset>
          <div class="notification-copy-tools"><button class="small-button" type="button" data-copy-notification-time="after_school">入力した帰宅後時刻を全曜日にコピー</button><button class="small-button" type="button" data-copy-notification-time="night">入力した夜の時刻を全曜日にコピー</button><p class="muted" role="status" aria-live="polite"></p></div>
          <table class="notification-schedule"><thead><tr><th scope="col">曜日</th><th scope="col">帰宅後</th><th scope="col">夜</th></tr></thead><tbody>
            <?php foreach ([1 => '月', 2 => '火', 3 => '水', 4 => '木', 5 => '金', 6 => '土', 7 => '日'] as $day => $name): ?>
              <tr><th scope="row"><?= $name ?></th>
                <?php foreach (['after_school' => 'after_school_time', 'night' => 'night_time'] as $type => $column): ?>
                  <?php $postedTime = $_POST['times'][$day][$type] ?? null; $row = $notificationSchedule[$day] ?? []; $databaseUiTime = notification_time_input_value($row[$column] ?? null); $hasPostedError = ($_POST['action'] ?? '') === 'save_notifications' && $error; $timeValue = $hasPostedError && is_string($postedTime) ? $postedTime : ($type === 'night' ? $databaseUiTime['time'] : substr((string)($row[$column] ?? ''), 0, 5)); $nextDay = $hasPostedError ? isset($_POST['night_next_day'][$day]) : $databaseUiTime['next_day']; $dayEnabled = $hasPostedError ? isset($_POST['notification_enabled'][$day][$type]) : (bool)($row[$type . '_enabled'] ?? true); ?>
                  <td><div class="notification-time-control"><input type="time" placeholder="22:00" aria-label="<?= $name ?>曜日 <?= $type === 'after_school' ? '帰宅後' : '夜' ?>の時刻" name="times[<?= $day ?>][<?= $type ?>]" value="<?= h($timeValue) ?>"><?php if ($timeValue === ''): ?><span class="time-example">例 22:00</span><?php endif; ?><?php if ($type === 'night'): ?><label class="night-next-day"><input type="checkbox" name="night_next_day[<?= $day ?>]" value="1" <?= $nextDay ? 'checked' : '' ?>><span>翌日</span></label><?php endif; ?></div><label class="notification-toggle"><input type="checkbox" aria-label="<?= $name ?>曜日 <?= $type === 'after_school' ? '帰宅後' : '夜' ?>の通知をONにする" name="notification_enabled[<?= $day ?>][<?= $type ?>]" value="1" <?= $dayEnabled ? 'checked' : '' ?>><span>ON</span></label></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody></table><button class="button" type="submit">通知時刻を保存</button>
        </form>
        <div id="push-panel" class="push-panel" data-vapid-key="<?= h($config['vapid_public_key'] ?? '') ?>" data-csrf="<?= h($_SESSION['csrf']) ?>">
          <h3>この端末の通知</h3><p class="muted">時刻を保存したあと、この端末で通知を許可してください。iPhoneではホーム画面に追加したアプリから操作します。</p>
          <button id="push-subscribe" class="small-button" type="button" disabled>この端末で通知を受け取る</button><p id="push-status" class="muted" role="status">確認中…</p>
        </div>
      </details>
      <details class="profile-edit"><summary>プロフィール</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save_profile"><label>表示名<input name="display_name" value="<?= h($profile['display_name'] ?? '') ?>" required maxlength="100"></label><label>学校名<input name="school_name" value="<?= h($profile['school_name'] ?? '') ?>" maxlength="150"></label><label>学年<input name="grade" value="<?= h($profile['grade'] ?? '') ?>" maxlength="40"></label><button class="button" type="submit">保存</button></form></details>
      </details>
      <a class="button secondary" href="?logout=1">ログアウト</a>
      <form method="post" class="mode-switch-bottom"><?= csrf_field() ?><input type="hidden" name="action" value="set_view_mode"><input type="hidden" name="view_mode" value="parent"><button class="small-button" type="submit">保護者モードに切り替え</button></form>
    </section>
  <?php elseif (!$pdo): ?>
    <section class="card"><p class="eyebrow">セットアップ</p><h2>データベースに接続できません</h2><p class="muted">`.env` の接続情報とMySQLの起動状態を確認してください。</p></section>
  <?php else: ?>
    <section class="card"><p class="eyebrow"><?= $isFirst ? '初回設定' : '生徒・管理者ログイン' ?></p><h2><?= $isFirst ? '管理者を作成' : 'おかえりなさい' ?></h2>
      <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
      <form method="post" autocomplete="on"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isFirst ? 'setup' : 'login' ?>"><label>ログインID<input name="login_id" required autocomplete="username" maxlength="120"></label><label>パスワード<input type="password" name="password" required autocomplete="<?= $isFirst ? 'new-password' : 'current-password' ?>" minlength="<?= $isFirst ? '12' : '1' ?>"></label><button class="button" type="submit"><?= $isFirst ? '管理者を作成' : 'ログイン' ?></button></form>
      <?php if (!$isFirst): ?><p class="muted">同じログインIDがまなログにも登録されている場合、塾生はIDの前に <code>school:</code> を付けてください。</p><?php endif; ?>
    </section>
  <?php endif; ?>
  <footer>石渡塾 学習記録</footer>
</main><script src="assets/app.js?v=20261020-processing" defer></script><script src="assets/notification-settings.js?v=20261027-copy-source" defer></script><script src="assets/setup-guide.js?v=20261021-setup-guide" defer></script><script src="assets/push.js?v=20261021-setup-guide" defer></script><?php if ($onboardingRequired): ?><script src="assets/onboarding.js?v=20261021-notifications-first" defer></script><?php endif; ?></body></html>
