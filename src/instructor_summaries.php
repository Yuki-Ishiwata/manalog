<?php
declare(strict_types=1);

function instructor_summary_valid_date(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function instructor_summary_period(string $dateFrom, string $dateTo): array {
    $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    if (!instructor_summary_valid_date($dateFrom) || !instructor_summary_valid_date($dateTo) || $dateFrom > $dateTo || $dateTo > $today) {
        throw new InvalidArgumentException('対象期間の日付を確認してください。');
    }
    $days = (int)(new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->format('%a');
    if ($days > 89) throw new InvalidArgumentException('対象期間は90日以内にしてください。');
    return [$dateFrom, $dateTo];
}

/** Reads scheduled lesson history from the independently configured school DB. */
function instructor_previous_school_lesson_date(int $schoolStudentId, array $config): ?string {
    if ($schoolStudentId < 1) return null;
    try {
        $school = school_db();
        $today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
        $stmt = $school->prepare("SELECT MAX(ss.date) FROM syuki_schedule ss JOIN syuki_schedule_kaiin ssk ON ssk.syuki_schedule_id = ss.id WHERE ssk.kaiin_id = ? AND ss.date < ? AND ss.chusi_flg = 0 AND ssk.status_id IN (0, 1, 4)");
        $stmt->execute([$schoolStudentId, $today]);
        $date = $stmt->fetchColumn();
        return is_string($date) && instructor_summary_valid_date($date) ? $date : null;
    } catch (Throwable $e) {
        error_log('ManaLog instructor summary school schedule lookup failed: ' . $e->getMessage());
        return null;
    }
}

function instructor_summary_default_period(?string $previousLessonDate): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
    $from = $previousLessonDate && instructor_summary_valid_date($previousLessonDate)
        ? $previousLessonDate
        : $today->modify('-6 days')->format('Y-m-d');
    return [$from, $today->format('Y-m-d')];
}

/** Collects only one student's records and the source evidence linked to those records. */
function instructor_summary_source_data(PDO $pdo, int $studentId, string $dateFrom, string $dateTo): array {
    instructor_summary_period($dateFrom, $dateTo);
    $stmt = $pdo->prepare("SELECT id, learning_date, subject, unit_name, activity_type, summary, study_minutes, not_understood, next_check, status FROM learning_records WHERE student_id = ? AND learning_date BETWEEN ? AND ? ORDER BY learning_date DESC, id DESC LIMIT 151");
    $stmt->execute([$studentId, $dateFrom, $dateTo]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $truncated = count($records) > 150;
    if ($truncated) $records = array_slice($records, 0, 150);
    usort($records, static fn(array $a, array $b): int => strcmp((string)$a['learning_date'], (string)$b['learning_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
    if (!$records) return ['records' => [], 'questions' => [], 'problems' => [], 'observations' => [], 'photos' => [], 'source_messages' => [], 'truncated' => false];

    $recordIds = array_map(static fn(array $row): int => (int)$row['id'], $records);
    $marks = implode(',', array_fill(0, count($recordIds), '?'));
    $recordById = [];
    foreach ($records as $index => &$record) {
        $record['id'] = (int)$record['id'];
        $record['study_minutes'] = $record['study_minutes'] === null ? null : (int)$record['study_minutes'];
        $record['questions'] = [];
        $record['problems'] = [];
        $record['photos'] = [];
        $recordById[$record['id']] = $index;
    }
    unset($record);

    $stmt = $pdo->prepare("SELECT q.id, q.learning_record_id, q.sequence_number, q.question_text, q.answer_text, q.answer_status, q.ai_estimated_understanding, q.understanding_note, q.next_check, q.source_question_message_id, q.source_answer_message_id FROM learning_record_questions q JOIN learning_records r ON r.id = q.learning_record_id WHERE q.learning_record_id IN ($marks) AND r.student_id = ? ORDER BY q.learning_record_id, q.sequence_number");
    $stmt->execute([...$recordIds, $studentId]);
    $questions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $question) {
        $question['id'] = (int)$question['id'];
        $question['learning_record_id'] = (int)$question['learning_record_id'];
        $questions[] = $question;
        $records[$recordById[$question['learning_record_id']]]['questions'][] = $question;
    }

    $stmt = $pdo->prepare("SELECT p.id, p.learning_record_id, p.material_name, p.page_number, p.problem_number, p.problem_text, p.student_answer, p.answer_result, p.result_source, p.analysis_confidence FROM learning_problems p JOIN learning_records r ON r.id = p.learning_record_id WHERE p.learning_record_id IN ($marks) AND r.student_id = ? ORDER BY p.learning_record_id, p.id");
    $stmt->execute([...$recordIds, $studentId]);
    $problems = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $problem) {
        $problem['id'] = (int)$problem['id'];
        $problem['learning_record_id'] = (int)$problem['learning_record_id'];
        $problems[] = $problem;
        $records[$recordById[$problem['learning_record_id']]]['problems'][] = $problem;
    }

    $unitStmt = $pdo->prepare("SELECT DISTINCT learning_unit_id FROM learning_records WHERE id IN ($marks) AND student_id = ? AND learning_unit_id IS NOT NULL");
    $unitStmt->execute([...$recordIds, $studentId]);
    $unitIds = array_map('intval', $unitStmt->fetchAll(PDO::FETCH_COLUMN));
    $observations = [];
    if ($unitIds) {
        $unitMarks = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = $pdo->prepare("SELECT h.id, h.learning_unit_id, h.learning_record_id, h.observation_source, h.understanding_level, h.evidence_note, h.confidence, h.observed_at, u.subject, u.unit_name FROM learning_understanding_history h JOIN learning_units u ON u.id = h.learning_unit_id WHERE h.student_id = ? AND h.learning_unit_id IN ($unitMarks) AND h.observed_at < DATE_ADD(?, INTERVAL 1 DAY) ORDER BY h.observed_at, h.id LIMIT 500");
        $stmt->execute([$studentId, ...$unitIds, $dateTo]);
        $observations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($observations as &$observation) {
            $observation['id'] = (int)$observation['id'];
            $observation['learning_unit_id'] = (int)$observation['learning_unit_id'];
            $observation['learning_record_id'] = $observation['learning_record_id'] === null ? null : (int)$observation['learning_record_id'];
        }
        unset($observation);
    }

    $stmt = $pdo->prepare("SELECT rp.learning_record_id, p.id AS photo_id, p.mime_type, p.storage_key, p.analysis_status, p.analysis_result, rp.relation_status FROM learning_record_photos rp JOIN learning_photos p ON p.id = rp.photo_id JOIN learning_records r ON r.id = rp.learning_record_id WHERE rp.learning_record_id IN ($marks) AND r.student_id = ? AND p.student_id = ? ORDER BY rp.learning_record_id, rp.position, p.id");
    $stmt->execute([...$recordIds, $studentId, $studentId]);
    $photos = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
        $photoId = (int)$photo['photo_id'];
        $result = json_decode((string)$photo['analysis_result'], true);
        $images = [];
        foreach (($result['image_results'] ?? []) as $image) {
            $images[] = [
                'subject' => mb_substr((string)($image['subject'] ?? ''), 0, 80),
                'unit' => mb_substr((string)($image['unit'] ?? ''), 0, 120),
                'material' => mb_substr((string)($image['material'] ?? ''), 0, 120),
                'page' => mb_substr((string)($image['page'] ?? ''), 0, 30),
                'kind' => (string)($image['kind'] ?? 'other'),
                'notebook_summary' => mb_substr((string)($image['notebook_summary'] ?? ''), 0, 500),
                'problems' => array_slice(array_map(static fn(array $item): array => [
                    'number' => mb_substr((string)($item['number'] ?? ''), 0, 30),
                    'text' => mb_substr((string)($item['text'] ?? ''), 0, 350),
                    'answer' => mb_substr((string)($item['answer'] ?? ''), 0, 250),
                    'mark' => (string)($item['mark'] ?? 'ungraded'),
                ], is_array($image['problems'] ?? null) ? $image['problems'] : []), 0, 20),
            ];
        }
        $photoData = ['id' => $photoId, 'analysis_status' => $photo['analysis_status'], 'relation_status' => $photo['relation_status'], 'analysis' => $images];
        $photos[$photoId] = $photoData;
        $records[$recordById[(int)$photo['learning_record_id']]]['photos'][] = $photoData;
    }

    $stmt = $pdo->prepare("SELECT DISTINCT rc.learning_record_id, m.id AS message_id, m.role, m.content, m.created_at FROM learning_record_chat_messages rc JOIN chat_messages m ON m.id = rc.message_id JOIN learning_records r ON r.id = rc.learning_record_id JOIN chat_sessions s ON s.id = m.session_id WHERE rc.learning_record_id IN ($marks) AND r.student_id = ? AND s.student_id = ? ORDER BY rc.learning_record_id, m.id LIMIT 300");
    $stmt->execute([...$recordIds, $studentId, $studentId]);
    $sourceMessages = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $message) {
        $message['learning_record_id'] = (int)$message['learning_record_id'];
        $message['message_id'] = (int)$message['message_id'];
        $message['content'] = mb_substr((string)$message['content'], 0, 1200);
        $sourceMessages[] = $message;
    }

    return ['records' => array_values($records), 'questions' => $questions, 'problems' => $problems, 'observations' => $observations, 'photos' => array_values($photos), 'source_messages' => $sourceMessages, 'truncated' => $truncated];
}

function instructor_summary_schema(): array {
    $ids = ['type' => 'array', 'items' => ['type' => 'integer']];
    $entry = static function (array $properties) use ($ids): array {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    };
    return [
        'type' => 'object',
        'properties' => [
            'school_topics' => ['type' => 'array', 'items' => $entry(['subject' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'evidence_record_ids' => $ids])],
            'understanding_points' => ['type' => 'array', 'items' => $entry(['subject' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['self_report', 'answered', 'unanswered', 'needs_review', 'progress']], 'summary' => ['type' => 'string'], 'evidence_record_ids' => $ids, 'evidence_question_ids' => $ids])],
            'home_study' => ['type' => 'array', 'items' => $entry(['subject' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'evidence_record_ids' => $ids, 'evidence_problem_ids' => $ids, 'evidence_photo_ids' => $ids])],
            'other_study' => ['type' => 'array', 'items' => $entry(['subject' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'summary' => ['type' => 'string'], 'evidence_record_ids' => $ids])],
            'recommendations' => ['type' => 'array', 'maxItems' => 3, 'items' => $entry(['subject' => ['type' => 'string'], 'unit' => ['type' => 'string'], 'check_content' => ['type' => 'string'], 'reason' => ['type' => 'string'], 'how_to_check' => ['type' => 'string'], 'evidence_record_ids' => $ids, 'evidence_problem_ids' => $ids, 'evidence_photo_ids' => $ids])],
        ],
        'required' => ['school_topics', 'understanding_points', 'home_study', 'other_study', 'recommendations'],
        'additionalProperties' => false,
    ];
}

function instructor_summary_ids(mixed $values, array $allowed): array {
    if (!is_array($values)) return [];
    $allowed = array_fill_keys(array_map('intval', $allowed), true);
    $result = [];
    foreach ($values as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id && isset($allowed[(int)$id])) $result[(int)$id] = (int)$id;
    }
    return array_values($result);
}

function instructor_summary_sanitize(array $summary, array $data): array {
    $recordIds = array_column($data['records'], 'id');
    $activityByRecord = [];
    foreach ($data['records'] as $record) $activityByRecord[(int)$record['id']] = (string)$record['activity_type'];
    $questionIds = array_column($data['questions'], 'id');
    $problemIds = array_column($data['problems'], 'id');
    $photoIds = array_column($data['photos'], 'id');
    $cleanEntries = static function (mixed $entries, string $kind) use ($recordIds, $questionIds, $problemIds, $photoIds, $activityByRecord): array {
        if (!is_array($entries)) return [];
        $entries = array_slice($entries, 0, $kind === 'recommendations' ? 3 : 100);
        foreach ($entries as &$item) {
            if (!is_array($item)) { $item = []; continue; }
            foreach (['subject' => 100, 'unit' => 200, 'summary' => 1800, 'check_content' => 600, 'reason' => 800, 'how_to_check' => 800] as $field => $max) {
                if (array_key_exists($field, $item)) $item[$field] = mb_substr(trim((string)$item[$field]), 0, $max);
            }
            if (isset($item['evidence_record_ids'])) $item['evidence_record_ids'] = instructor_summary_ids($item['evidence_record_ids'], $recordIds);
            if ($kind === 'school_topics') $item['evidence_record_ids'] = array_values(array_filter($item['evidence_record_ids'] ?? [], static fn(int $id): bool => ($activityByRecord[$id] ?? '') === 'school'));
            if ($kind === 'home_study') $item['evidence_record_ids'] = array_values(array_filter($item['evidence_record_ids'] ?? [], static fn(int $id): bool => ($activityByRecord[$id] ?? '') === 'homework'));
            if ($kind === 'other_study') $item['evidence_record_ids'] = array_values(array_filter($item['evidence_record_ids'] ?? [], static fn(int $id): bool => !in_array($activityByRecord[$id] ?? '', ['school', 'homework'], true)));
            if (isset($item['evidence_question_ids'])) $item['evidence_question_ids'] = instructor_summary_ids($item['evidence_question_ids'], $questionIds);
            if (isset($item['evidence_problem_ids'])) $item['evidence_problem_ids'] = instructor_summary_ids($item['evidence_problem_ids'], $problemIds);
            if (isset($item['evidence_photo_ids'])) $item['evidence_photo_ids'] = instructor_summary_ids($item['evidence_photo_ids'], $photoIds);
            if (isset($item['kind']) && !in_array($item['kind'], ['self_report', 'answered', 'unanswered', 'needs_review', 'progress'], true)) $item['kind'] = 'needs_review';
        }
        unset($item);
        return array_values(array_filter($entries, static function (array $item): bool {
            $hasText = (string)($item['summary'] ?? $item['check_content'] ?? '') !== '';
            foreach (['evidence_record_ids', 'evidence_question_ids', 'evidence_problem_ids', 'evidence_photo_ids'] as $field) {
                if (!empty($item[$field])) return $hasText;
            }
            return false;
        }));
    };
    return [
        'school_topics' => $cleanEntries($summary['school_topics'] ?? [], 'school_topics'),
        'understanding_points' => $cleanEntries($summary['understanding_points'] ?? [], 'understanding_points'),
        'home_study' => $cleanEntries($summary['home_study'] ?? [], 'home_study'),
        'other_study' => $cleanEntries($summary['other_study'] ?? [], 'other_study'),
        'recommendations' => $cleanEntries($summary['recommendations'] ?? [], 'recommendations'),
    ];
}

function instructor_summary_generate(array $config, array $data, string $dateFrom, string $dateTo): array {
    if (!$data['records']) return ['school_topics' => [], 'understanding_points' => [], 'home_study' => [], 'other_study' => [], 'recommendations' => []];
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。');
    $apiData = $data;
    foreach ($apiData['records'] as &$record) {
        $record['summary'] = mb_substr((string)($record['summary'] ?? ''), 0, 1000);
        $record['not_understood'] = mb_substr((string)($record['not_understood'] ?? ''), 0, 500);
        $record['next_check'] = mb_substr((string)($record['next_check'] ?? ''), 0, 500);
        unset($record['questions'], $record['problems'], $record['photos']);
    }
    unset($record);
    foreach ($apiData['questions'] as &$question) {
        $question['question_text'] = mb_substr((string)$question['question_text'], 0, 500);
        $question['answer_text'] = mb_substr((string)($question['answer_text'] ?? ''), 0, 500);
        $question['understanding_note'] = mb_substr((string)($question['understanding_note'] ?? ''), 0, 400);
        $question['next_check'] = mb_substr((string)($question['next_check'] ?? ''), 0, 400);
    }
    unset($question);
    foreach ($apiData['problems'] as &$problem) {
        $problem['problem_text'] = mb_substr((string)($problem['problem_text'] ?? ''), 0, 500);
        $problem['student_answer'] = mb_substr((string)($problem['student_answer'] ?? ''), 0, 400);
    }
    unset($problem);
    foreach ($apiData['source_messages'] as &$message) unset($message['content']);
    unset($message);
    $input = [
        ['role' => 'system', 'content' => 'あなたは塾講師向けの学習サマリー作成者です。入力JSONに含まれる事実だけを使い、記録のない内容を作らず、理解度を断定せず、根拠IDを必ず保持してください。生徒の自己申告、AI推定、AI質問への回答、問題写真の正誤マークを明確に区別してください。単発の誤答や未回答を苦手と決めつけず、授業で確認する提案は最大3件、具体的な確認方法と理由を含めてください。学校授業と家庭学習を混ぜず、正誤マークは result_source が student_mark の場合「生徒が付けた印」として扱い、ai_estimate は推定として表現してください。'] ,
        ['role' => 'user', 'content' => "対象期間: $dateFrom から $dateTo\n次の学習記録JSONを要約してください。truncatedがtrueなら対象期間の全記録ではないことを明記し、記録ID・質問ID・問題ID・写真IDは入力値から選び、存在しないIDを作らないでください。school_topicsにはactivity_type=school、home_studyにはactivity_type=homeworkの記録を使い、その他はother_studyへ。understanding_pointsでは根拠に応じて kind を選んでください。progressは同一単元の複数観察を比較できる場合だけ使います。recommendationsの根拠IDは提案内容を支える記録に限ってください。\n" . json_encode($apiData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
    ];
    $payload = [
        'model' => $config['ai_model'],
        'input' => $input,
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'instructor_learning_summary', 'strict' => true, 'schema' => instructor_summary_schema()]],
        'store' => false,
    ];
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), CURLOPT_TIMEOUT => 60]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        error_log('ManaLog instructor summary API error: HTTP ' . $status . ' ' . $error);
        throw new RuntimeException('学習サマリーを生成できませんでした。時間をおいて再度お試しください。');
    }
    $response = json_decode($raw, true);
    $output = '';
    foreach (($response['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $output .= (string)($part['text'] ?? '');
    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) throw new RuntimeException('学習サマリーの形式を読み取れませんでした。');
    return instructor_summary_sanitize($decoded, $data);
}

function instructor_summary_cached(PDO $pdo, array $config, int $studentId, string $dateFrom, string $dateTo, bool $force = false): array {
    [$dateFrom, $dateTo] = instructor_summary_period($dateFrom, $dateTo);
    $data = instructor_summary_source_data($pdo, $studentId, $dateFrom, $dateTo);
    $sourceHash = hash('sha256', json_encode(['version' => 'phase3-v1', 'model' => $config['ai_model'] ?? '', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    if (!$force) {
        $stmt = $pdo->prepare('SELECT source_hash, summary_json, generated_at FROM instructor_learning_summaries WHERE student_id = ? AND date_from = ? AND date_to = ? LIMIT 1');
        $stmt->execute([$studentId, $dateFrom, $dateTo]);
        $cache = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($cache && hash_equals((string)$cache['source_hash'], $sourceHash)) {
            $summary = json_decode((string)$cache['summary_json'], true);
            if (is_array($summary)) return ['summary' => instructor_summary_sanitize($summary, $data), 'data' => $data, 'generated_at' => $cache['generated_at'], 'cached' => true, 'source_hash' => $sourceHash];
        }
    }
    $summary = instructor_summary_generate($config, $data, $dateFrom, $dateTo);
    $stmt = $pdo->prepare('INSERT INTO instructor_learning_summaries (student_id, date_from, date_to, source_hash, summary_json, model, generated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source_hash = VALUES(source_hash), summary_json = VALUES(summary_json), model = VALUES(model), generated_at = CURRENT_TIMESTAMP');
    $stmt->execute([$studentId, $dateFrom, $dateTo, $sourceHash, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string)($config['ai_model'] ?? 'none')]);
    return ['summary' => $summary, 'data' => $data, 'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s'), 'cached' => false, 'source_hash' => $sourceHash];
}
