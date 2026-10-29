<?php
declare(strict_types=1);
require_once __DIR__ . '/telegram.php';

function learning_understanding_values(): array {
    return ['not_understood', 'needs_help', 'partial', 'mostly_understood', 'confident'];
}

function learning_activity_values(): array {
    return ['school', 'homework', 'juku', 'other', 'unknown'];
}

function learning_extraction_schema(): array {
    $nullableUnderstanding = ['type' => ['string', 'null'], 'enum' => array_merge(learning_understanding_values(), [null])];
    $nullableNumber = ['type' => ['number', 'null']];
    return [
        'type' => 'object',
        'properties' => [
            'records' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'subject' => ['type' => 'string'],
                        'unit' => ['type' => 'string'],
                        'activity_type' => ['type' => 'string', 'enum' => learning_activity_values()],
                        'summary' => ['type' => 'string'],
                        'study_minutes' => ['type' => ['integer', 'null']],
                        'self_reported_understanding' => $nullableUnderstanding,
                        'self_report_evidence' => ['type' => 'string'],
                        'ai_estimated_understanding' => $nullableUnderstanding,
                        'ai_estimate_confidence' => $nullableNumber,
                        'ai_estimate_evidence' => ['type' => 'string'],
                        'not_understood' => ['type' => 'string'],
                        'next_check' => ['type' => 'string'],
                        'evidence_message_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'questions' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'question' => ['type' => 'string'],
                                    'answer' => ['type' => 'string'],
                                    'answer_status' => ['type' => 'string', 'enum' => ['unanswered', 'answered', 'unsure', 'skipped', 'hint_given']],
                                    'ai_estimated_understanding' => $nullableUnderstanding,
                                    'ai_estimate_confidence' => $nullableNumber,
                                    'understanding_note' => ['type' => 'string'],
                                    'next_check' => ['type' => 'string'],
                                    'question_message_id' => ['type' => 'integer'],
                                    'answer_message_id' => ['type' => 'integer'],
                                ],
                                'required' => ['question', 'answer', 'answer_status', 'ai_estimated_understanding', 'ai_estimate_confidence', 'understanding_note', 'next_check', 'question_message_id', 'answer_message_id'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['subject', 'unit', 'activity_type', 'summary', 'study_minutes', 'self_reported_understanding', 'self_report_evidence', 'ai_estimated_understanding', 'ai_estimate_confidence', 'ai_estimate_evidence', 'not_understood', 'next_check', 'evidence_message_ids', 'questions'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['records'],
        'additionalProperties' => false,
    ];
}

function learning_call_extraction_api(array $config, array $messages, string $sessionType): array {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');

    $transcript = [];
    foreach ($messages as $message) {
        $transcript[] = [
            'message_id' => (int)$message['id'],
            'role' => $message['role'],
            'text' => (string)$message['content'],
        ];
    }
    $activityContext = $sessionType === 'after_school'
        ? 'この会話は学校の授業を思い出す振り返りです。activity_type は school としてください。'
        : 'この会話は夜の家庭学習の振り返りです。発言に塾での学習が明示されていれば juku、家庭での学習なら homework、どちらか判断できなければ unknown としてください。';
    $instructions = 'あなたは学習記録の抽出器です。会話に明示された内容だけをJSON Schemaに従って抽出してください。生徒が話していない科目・単元・学習時間・理解度を補わないでください。科目や単元が特定できない場合は空文字にします。学習したこととAIが推定したことを混同しません。生徒自身が理解度を明示した場合だけ self_reported_understanding に設定します。AIの推定は質問への回答やヒント後の反応を根拠にし、根拠が不十分なら null にします。一回の正答だけで単元全体を confident と判定しません。分からない・答えられない内容は not_understood に残し、次回確認できる内容だけを next_check にします。学習時間は明示された場合だけ整数の分にします。会話中に学習内容についての確認質問があれば、その質問と直後の生徒の回答を必ず questions に記録し、質問と回答それぞれの実際の message ID を設定します。未回答なら answer_status を unanswered にします。質問・回答の文面は会話にあるものを使い、message IDも会話中のIDから選びます。各記録の evidence_message_ids には、その記録を裏付けるIDだけを含めます。' . $activityContext;
    $payload = [
        'model' => $config['ai_model'],
        'instructions' => $instructions,
        'input' => json_encode($transcript, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'manalog_learning_extraction',
                'strict' => true,
                'schema' => learning_extraction_schema(),
            ],
        ],
        'max_output_tokens' => 3500,
    ];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 40,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('学習記録の抽出APIに接続できませんでした。');
    $response = json_decode($raw, true);
    if (!is_array($response)) throw new RuntimeException('学習記録の抽出結果を読み取れませんでした。');
    $output = '';
    foreach (($response['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $part) {
            if (($part['type'] ?? '') === 'refusal') throw new RuntimeException('学習記録を抽出できませんでした。');
            if (($part['type'] ?? '') === 'output_text') $output .= (string)($part['text'] ?? '');
        }
    }
    $decoded = json_decode($output, true);
    if (!is_array($decoded) || !is_array($decoded['records'] ?? null)) throw new RuntimeException('学習記録の抽出結果が不正です。');
    return $decoded;
}

function learning_normalized_key(string $value): string {
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
}

function learning_nullable_level(mixed $value): ?string {
    return is_string($value) && in_array($value, learning_understanding_values(), true) ? $value : null;
}

function learning_safe_text(mixed $value, int $maxLength = 4000): ?string {
    if (!is_string($value)) return null;
    $value = trim($value);
    if ($value === '') return null;
    return mb_substr($value, 0, $maxLength, 'UTF-8');
}

function learning_list_for_student(PDO $pdo, int $studentId, ?string $date = null, ?string $subject = null, int $page = 1): array {
    $subjectsStmt = $pdo->prepare("SELECT DISTINCT subject FROM learning_records WHERE student_id = ? AND subject IS NOT NULL AND subject <> '' ORDER BY subject");
    $subjectsStmt->execute([$studentId]);
    $subjects = $subjectsStmt->fetchAll(PDO::FETCH_COLUMN);

    $conditions = ['r.student_id = ?'];
    $params = [$studentId];
    if ($date !== null) {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed && $parsed->format('Y-m-d') === $date) { $conditions[] = 'r.learning_date = ?'; $params[] = $date; }
        else $date = null;
    }
    if ($subject !== null && in_array($subject, $subjects, true)) { $conditions[] = 'r.subject = ?'; $params[] = $subject; }
    else $subject = null;

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM learning_records r WHERE ' . implode(' AND ', $conditions));
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pageSize = 30;
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($page, $pages));
    $offset = ($page - 1) * $pageSize;
    $sql = "SELECT r.id, r.learning_date, r.subject, r.unit_name, r.activity_type, r.summary, r.study_minutes, r.not_understood, r.next_check,
        (SELECT h.understanding_level FROM learning_understanding_history h WHERE h.learning_record_id = r.id AND h.observation_source = 'student_self_report' ORDER BY h.observed_at DESC, h.id DESC LIMIT 1) AS self_understanding,
        (SELECT h.understanding_level FROM learning_understanding_history h WHERE h.learning_record_id = r.id AND h.observation_source IN ('ai_estimate','question_response') ORDER BY h.observed_at DESC, h.id DESC LIMIT 1) AS ai_understanding,
        (SELECT h.understanding_level FROM learning_understanding_history h WHERE h.learning_record_id = r.id ORDER BY h.observed_at DESC, h.id DESC LIMIT 1) AS latest_understanding,
        (SELECT h.understanding_level FROM learning_understanding_history h JOIN learning_records previous_record ON previous_record.id = h.learning_record_id WHERE h.learning_unit_id = r.learning_unit_id AND previous_record.student_id = r.student_id AND previous_record.learning_date < r.learning_date ORDER BY h.observed_at DESC, h.id DESC LIMIT 1) AS previous_understanding,
        (SELECT previous_record.learning_date FROM learning_understanding_history h JOIN learning_records previous_record ON previous_record.id = h.learning_record_id WHERE h.learning_unit_id = r.learning_unit_id AND previous_record.student_id = r.student_id AND previous_record.learning_date < r.learning_date ORDER BY h.observed_at DESC, h.id DESC LIMIT 1) AS previous_understanding_date
        FROM learning_records r WHERE " . implode(' AND ', $conditions) . " ORDER BY r.learning_date DESC, r.id DESC LIMIT $pageSize OFFSET $offset";
    $recordsStmt = $pdo->prepare($sql);
    $recordsStmt->execute($params);
    $records = $recordsStmt->fetchAll();
    if (!$records) return ['subjects' => $subjects, 'records' => [], 'photos' => [], 'problems' => [], 'date' => $date, 'subject' => $subject, 'page' => $page, 'pages' => $pages, 'total' => $total];

    $recordIds = array_map(static fn($record) => (int)$record['id'], $records);
    $marks = implode(',', array_fill(0, count($recordIds), '?'));
    $photoStmt = $pdo->prepare("SELECT rp.learning_record_id, p.id, p.mime_type FROM learning_record_photos rp JOIN learning_photos p ON p.id = rp.photo_id WHERE rp.learning_record_id IN ($marks) AND p.student_id = ? ORDER BY rp.position, p.id");
    $photoStmt->execute([...$recordIds, $studentId]);
    $photos = [];
    foreach ($photoStmt->fetchAll() as $photo) $photos[(int)$photo['learning_record_id']][] = ['id' => (int)$photo['id'], 'mime_type' => $photo['mime_type']];

    $problemStmt = $pdo->prepare("SELECT p.id, p.learning_record_id, p.material_name, p.page_number, p.problem_number, p.problem_text, p.student_answer, p.answer_result FROM learning_problems p JOIN learning_records r ON r.id = p.learning_record_id WHERE p.learning_record_id IN ($marks) AND r.student_id = ? ORDER BY p.id");
    $problemStmt->execute([...$recordIds, $studentId]);
    $problems = [];
    foreach ($problemStmt->fetchAll() as $problem) $problems[(int)$problem['learning_record_id']][] = $problem;
    return ['subjects' => $subjects, 'records' => $records, 'photos' => $photos, 'problems' => $problems, 'date' => $date, 'subject' => $subject, 'page' => $page, 'pages' => $pages, 'total' => $total];
}

/** Select a small, evidence-backed set of learning items for a weekly review. */
function learning_weekly_review_candidates(PDO $pdo, int $studentId, string $dateFrom, string $dateTo, int $limit = 8): array {
    foreach ([$dateFrom, $dateTo] as $date) {
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo')) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Invalid weekly review date range.');
    }
    if ($dateFrom > $dateTo) throw new InvalidArgumentException('Invalid weekly review date range.');
    $limit = max(1, min($limit, 12));
    $stmt = $pdo->prepare("SELECT r.id, r.learning_date, r.learning_unit_id, r.subject, r.unit_name, r.summary, r.not_understood, r.next_check,
        (SELECT h.understanding_level FROM learning_understanding_history h WHERE h.learning_record_id = r.id ORDER BY h.observed_at DESC, h.id DESC LIMIT 1) AS understanding
        FROM learning_records r WHERE r.student_id = ? AND r.learning_date BETWEEN ? AND ? ORDER BY r.learning_date DESC, r.id DESC");
    $stmt->execute([$studentId, $dateFrom, $dateTo]);
    $records = $stmt->fetchAll();
    if (!$records) return [];

    $recordIds = array_map(static fn($record) => (int)$record['id'], $records);
    $marks = implode(',', array_fill(0, count($recordIds), '?'));
    $problemStmt = $pdo->prepare("SELECT p.id, p.learning_record_id, p.material_name, p.page_number, p.problem_number, p.answer_result, p.result_source FROM learning_problems p JOIN learning_records r ON r.id = p.learning_record_id WHERE p.learning_record_id IN ($marks) AND r.student_id = ? ORDER BY p.id");
    $problemStmt->execute([...$recordIds, $studentId]);
    $problems = [];
    foreach ($problemStmt->fetchAll() as $problem) $problems[(int)$problem['learning_record_id']][] = [
        'id' => (int)$problem['id'], 'material' => $problem['material_name'], 'page' => $problem['page_number'],
        'number' => $problem['problem_number'], 'result' => $problem['answer_result'], 'source' => $problem['result_source'],
    ];
    $questionStmt = $pdo->prepare("SELECT q.learning_record_id, q.question_text, q.answer_status, q.next_check FROM learning_record_questions q JOIN learning_records r ON r.id = q.learning_record_id WHERE q.learning_record_id IN ($marks) AND r.student_id = ? ORDER BY q.sequence_number");
    $questionStmt->execute([...$recordIds, $studentId]);
    $questions = [];
    foreach ($questionStmt->fetchAll() as $question) $questions[(int)$question['learning_record_id']][] = [
        'question' => $question['question_text'], 'status' => $question['answer_status'], 'next_check' => $question['next_check'],
    ];
    $photoStmt = $pdo->prepare("SELECT rp.learning_record_id, p.id FROM learning_record_photos rp JOIN learning_photos p ON p.id = rp.photo_id WHERE rp.learning_record_id IN ($marks) AND p.student_id = ? ORDER BY rp.position, p.id");
    $photoStmt->execute([...$recordIds, $studentId]);
    $photos = [];
    foreach ($photoStmt->fetchAll() as $photo) $photos[(int)$photo['learning_record_id']][] = (int)$photo['id'];
    $problemPhotoStmt = $pdo->prepare("SELECT p.learning_record_id, pp.photo_id FROM learning_problem_photos pp JOIN learning_problems p ON p.id = pp.problem_id JOIN learning_records r ON r.id = p.learning_record_id JOIN learning_photos photo ON photo.id = pp.photo_id WHERE p.learning_record_id IN ($marks) AND r.student_id = ? AND photo.student_id = ? ORDER BY pp.problem_id, pp.photo_id");
    $problemPhotoStmt->execute([...$recordIds, $studentId, $studentId]);
    foreach ($problemPhotoStmt->fetchAll() as $photo) $photos[(int)$photo['learning_record_id']][] = (int)$photo['photo_id'];
    foreach ($photos as $recordId => $photoIds) $photos[$recordId] = array_values(array_unique($photoIds));

    $candidates = [];
    foreach ($records as $record) {
        $id = (int)$record['id'];
        $recordProblems = $problems[$id] ?? [];
        $recordQuestions = $questions[$id] ?? [];
        $level = (string)($record['understanding'] ?? '');
        $score = match ($level) { 'not_understood' => 4, 'needs_help' => 3, 'partial' => 2, default => 0 };
        if (trim((string)$record['not_understood']) !== '') $score += 4;
        if (trim((string)$record['next_check']) !== '') $score += 2;
        foreach ($recordProblems as $problem) $score += match ($problem['result']) { 'incorrect' => 3, 'partially_correct' => 2, default => 0 };
        foreach ($recordQuestions as $question) if (in_array($question['status'], ['unanswered', 'unsure', 'skipped', 'hint_given'], true)) $score += 2;
        // If no weak point was recorded, keep recent learning eligible as a light recall topic.
        if ($score === 0) $score = 1;
        $candidates[] = [
            'record_id' => $id, 'date' => (string)$record['learning_date'], 'subject' => $record['subject'],
            'unit' => $record['unit_name'], 'summary' => $record['summary'], 'understanding' => $level ?: null,
            'not_understood' => $record['not_understood'], 'next_check' => $record['next_check'],
            'problems' => $recordProblems, 'questions' => $recordQuestions, 'photos' => $photos[$id] ?? [], 'priority' => $score,
        ];
    }
    usort($candidates, static fn($a, $b) => ($b['priority'] <=> $a['priority']) ?: ($b['date'] <=> $a['date']));
    return array_slice($candidates, 0, $limit);
}

function learning_store_extraction(PDO $pdo, int $studentId, array $session, array $messages, array $extraction): int {
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $messageById = [];
        foreach ($messages as $message) $messageById[(int)$message['id']] = $message;
        $unitUpsert = $pdo->prepare('INSERT INTO learning_units (student_id, subject, unit_name, unit_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), subject = VALUES(subject), unit_name = VALUES(unit_name)');
        $recordUpsert = $pdo->prepare("INSERT INTO learning_records (student_id, learning_date, learning_unit_id, subject, unit_name, activity_type, summary, study_minutes, not_understood, next_check, source_session_id, idempotency_key, status, extraction_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'phase2-v1') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), learning_unit_id = VALUES(learning_unit_id), subject = VALUES(subject), unit_name = VALUES(unit_name), activity_type = VALUES(activity_type), summary = VALUES(summary), study_minutes = VALUES(study_minutes), not_understood = VALUES(not_understood), next_check = VALUES(next_check), extraction_version = VALUES(extraction_version)");
        $recordMessageInsert = $pdo->prepare('INSERT IGNORE INTO learning_record_chat_messages (learning_record_id, message_id, relation_type) VALUES (?, ?, ?)');
        $questionUpsert = $pdo->prepare("INSERT INTO learning_record_questions (learning_record_id, sequence_number, question_text, answer_text, answer_status, ai_estimated_understanding, understanding_note, next_check, source_question_message_id, source_answer_message_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE question_text = VALUES(question_text), answer_text = VALUES(answer_text), answer_status = VALUES(answer_status), ai_estimated_understanding = VALUES(ai_estimated_understanding), understanding_note = VALUES(understanding_note), next_check = VALUES(next_check), source_question_message_id = VALUES(source_question_message_id), source_answer_message_id = VALUES(source_answer_message_id)");
        $historyInsert = $pdo->prepare('INSERT INTO learning_understanding_history (student_id, learning_unit_id, learning_record_id, observation_source, understanding_level, evidence_note, confidence, observed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $saved = 0;
        foreach (array_slice($extraction['records'], 0, 20) as $record) {
            if (!is_array($record)) continue;
            $subject = learning_safe_text($record['subject'] ?? null, 100);
            $unitName = learning_safe_text($record['unit'] ?? null, 200);
            $summary = learning_safe_text($record['summary'] ?? null);
            if ($subject === null && $unitName === null && $summary === null) continue;
            $activity = in_array($session['session_type'], ['after_school', 'night'], true)
                ? ($session['session_type'] === 'after_school' ? 'school' : ((string)($record['activity_type'] ?? 'unknown')))
                : 'unknown';
            if (!in_array($activity, learning_activity_values(), true)) $activity = 'unknown';
            $unitId = null;
            if ($subject !== null && $unitName !== null) {
                $unitKey = hash('sha256', learning_normalized_key($subject) . "\0" . learning_normalized_key($unitName));
                $unitUpsert->execute([$studentId, $subject, $unitName, $unitKey]);
                $unitId = (int)$pdo->lastInsertId();
            }
            $idempotencyKey = hash('sha256', implode("\0", [
                (string)$session['id'],
                learning_normalized_key((string)$subject),
                learning_normalized_key((string)$unitName),
                $activity,
            ]));
            $minutes = filter_var($record['study_minutes'] ?? null, FILTER_VALIDATE_INT);
            if ($minutes === false || $minutes < 0 || $minutes > 1440) $minutes = null;
            $notUnderstood = learning_safe_text($record['not_understood'] ?? null);
            $nextCheck = learning_safe_text($record['next_check'] ?? null);
            $recordUpsert->execute([
                $studentId,
                $session['session_date'],
                $unitId,
                $subject,
                $unitName,
                $activity,
                $summary,
                $minutes,
                $notUnderstood,
                $nextCheck,
                (int)$session['id'],
                $idempotencyKey,
            ]);
            $recordId = (int)$pdo->lastInsertId();
            $saved++;

            foreach (array_slice($record['evidence_message_ids'] ?? [], 0, 30) as $messageId) {
                $messageId = filter_var($messageId, FILTER_VALIDATE_INT);
                if ($messageId && isset($messageById[$messageId])) $recordMessageInsert->execute([$recordId, $messageId, 'evidence']);
            }

            $selfLevel = learning_nullable_level($record['self_reported_understanding'] ?? null);
            if ($unitId && $selfLevel) {
                $historyInsert->execute([$studentId, $unitId, $recordId, 'student_self_report', $selfLevel, learning_safe_text($record['self_report_evidence'] ?? null), null, date('Y-m-d H:i:s')]);
            }
            $aiLevel = learning_nullable_level($record['ai_estimated_understanding'] ?? null);
            if ($unitId && $aiLevel) {
                $confidence = filter_var($record['ai_estimate_confidence'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($confidence === false || $confidence < 0 || $confidence > 1) $confidence = null;
                $historyInsert->execute([$studentId, $unitId, $recordId, 'ai_estimate', $aiLevel, learning_safe_text($record['ai_estimate_evidence'] ?? null), $confidence, date('Y-m-d H:i:s')]);
            }

            foreach (array_slice($record['questions'] ?? [], 0, 30) as $index => $question) {
                if (!is_array($question)) continue;
                $questionText = learning_safe_text($question['question'] ?? null);
                if ($questionText === null) continue;
                $questionMessageId = filter_var($question['question_message_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$questionMessageId || !isset($messageById[$questionMessageId]) || $messageById[$questionMessageId]['role'] !== 'assistant') $questionMessageId = null;
                $answerMessageId = filter_var($question['answer_message_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$answerMessageId || !isset($messageById[$answerMessageId]) || $messageById[$answerMessageId]['role'] !== 'user') $answerMessageId = null;
                $answerStatus = (string)($question['answer_status'] ?? 'unanswered');
                $allowedAnswerStatuses = ['unanswered', 'answered', 'unsure', 'skipped', 'hint_given'];
                if (!in_array($answerStatus, $allowedAnswerStatuses, true)) $answerStatus = 'unanswered';
                $questionLevel = learning_nullable_level($question['ai_estimated_understanding'] ?? null);
                $questionUpsert->execute([
                    $recordId,
                    $index + 1,
                    $questionText,
                    learning_safe_text($question['answer'] ?? null),
                    $answerStatus,
                    $questionLevel,
                    learning_safe_text($question['understanding_note'] ?? null),
                    learning_safe_text($question['next_check'] ?? null),
                    $questionMessageId,
                    $answerMessageId,
                ]);
                if ($questionMessageId) $recordMessageInsert->execute([$recordId, $questionMessageId, 'question']);
                if ($answerMessageId) $recordMessageInsert->execute([$recordId, $answerMessageId, 'answer']);
                if ($unitId && $questionLevel && $answerStatus !== 'unanswered') {
                    $confidence = filter_var($question['ai_estimate_confidence'] ?? null, FILTER_VALIDATE_FLOAT);
                    if ($confidence === false || $confidence < 0 || $confidence > 1) $confidence = null;
                    $historyInsert->execute([$studentId, $unitId, $recordId, 'question_response', $questionLevel, learning_safe_text($question['understanding_note'] ?? null), $confidence, date('Y-m-d H:i:s')]);
                }
            }
        }
        if ($ownsTransaction) $pdo->commit();
        return $saved;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function process_learning_extraction_jobs(PDO $pdo, array $config, int $limit = 10): array {
    $counts = ['completed' => 0, 'failed' => 0];
    for ($i = 0; $i < max(1, min(20, $limit)); $i++) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->query("SELECT id, student_id, session_id FROM learning_extraction_jobs WHERE attempts < 3 AND (status = 'pending' OR (status = 'failed' AND next_attempt_at <= NOW()) OR (status = 'processing' AND lease_until < NOW())) ORDER BY id LIMIT 1 FOR UPDATE");
            $job = $stmt->fetch();
            if (!$job) {
                $pdo->commit();
                break;
            }
            $claim = $pdo->prepare("UPDATE learning_extraction_jobs SET status = 'processing', attempts = attempts + 1, lease_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE), last_error = NULL WHERE id = ?");
            $claim->execute([(int)$job['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        try {
            $sessionStmt = $pdo->prepare("SELECT id, student_id, session_date, session_type, ended_at FROM chat_sessions WHERE id = ? AND student_id = ?");
            $sessionStmt->execute([(int)$job['session_id'], (int)$job['student_id']]);
            $session = $sessionStmt->fetch();
            if (!$session || !$session['ended_at'] || !in_array($session['session_type'], ['after_school', 'night'], true)) {
                throw new RuntimeException('対象の振り返りを確認できません。');
            }
            $messageStmt = $pdo->prepare('SELECT id, role, content FROM chat_messages WHERE session_id = ? ORDER BY id');
            $messageStmt->execute([(int)$session['id']]);
            $messages = $messageStmt->fetchAll();
            $extraction = learning_call_extraction_api($config, $messages, (string)$session['session_type']);

            $pdo->beginTransaction();
            $stillOwned = $pdo->prepare("SELECT id FROM learning_extraction_jobs WHERE id = ? AND student_id = ? AND session_id = ? AND status = 'processing' FOR UPDATE");
            $stillOwned->execute([(int)$job['id'], (int)$job['student_id'], (int)$job['session_id']]);
            if (!$stillOwned->fetchColumn()) throw new RuntimeException('学習記録の処理状態が更新されました。');
            $saved = learning_store_extraction($pdo, (int)$job['student_id'], $session, $messages, $extraction);
            try {
                telegram_queue_learning_summary($pdo, $config, (int)$job['student_id'], (int)$job['session_id']);
            } catch (Throwable $telegramQueueError) {
                error_log('ManaLog Telegram summary could not be queued: session=' . (int)$job['session_id'] . ' exception=' . get_class($telegramQueueError));
            }
            $done = $pdo->prepare("UPDATE learning_extraction_jobs SET status = 'completed', lease_until = NULL, next_attempt_at = NULL, completed_at = NOW(), last_error = NULL WHERE id = ?");
            $done->execute([(int)$job['id']]);
            $pdo->commit();
            $counts['completed']++;
            error_log('ManaLog learning extraction completed: session=' . (int)$session['id'] . ' records=' . $saved);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $failed = $pdo->prepare("UPDATE learning_extraction_jobs SET status = 'failed', lease_until = NULL, next_attempt_at = IF(attempts < 3, DATE_ADD(NOW(), INTERVAL 5 MINUTE), NULL), last_error = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'processing'");
            $failed->execute(['学習記録を抽出できませんでした。次の処理で再試行します。', (int)$job['id']]);
            $counts['failed']++;
            error_log('ManaLog learning extraction failed: session=' . (int)$job['session_id'] . ' exception=' . get_class($e));
        }
    }
    return $counts;
}
