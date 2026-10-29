<?php
declare(strict_types=1);

function weekly_review_range(DateTimeImmutable $now): array {
    $local = $now->setTimezone(new DateTimeZone('Asia/Tokyo'));
    return [$local->modify('monday this week')->format('Y-m-d'), $local->modify('sunday this week')->format('Y-m-d')];
}

function weekly_review_safe_json(mixed $value): array {
    if (is_array($value)) return $value;
    $decoded = is_string($value) ? json_decode($value, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function weekly_review_load_plan(PDO $pdo, int $studentId, string $weekStart): ?array {
    $stmt = $pdo->prepare('SELECT * FROM weekly_review_plans WHERE student_id = ? AND week_start = ? LIMIT 1');
    $stmt->execute([$studentId, $weekStart]);
    $plan = $stmt->fetch();
    if (!$plan) return null;
    $plan['recommendations'] = weekly_review_safe_json($plan['recommendations_json']);
    $items = $pdo->prepare('SELECT id, item_key, position, learning_record_id, details_json, status, last_outcome, understanding_level, last_reviewed_at FROM weekly_review_items WHERE plan_id = ? AND student_id = ? ORDER BY position');
    $items->execute([(int)$plan['id'], $studentId]);
    $plan['items'] = [];
    foreach ($items->fetchAll() as $item) {
        $details = weekly_review_safe_json($item['details_json']);
        $plan['items'][] = $details + [
            'id' => (int)$item['id'], 'item_key' => (string)$item['item_key'], 'position' => (int)$item['position'],
            'learning_record_id' => $item['learning_record_id'] === null ? null : (int)$item['learning_record_id'],
            'status' => (string)$item['status'], 'last_outcome' => $item['last_outcome'],
            'understanding_level' => $item['understanding_level'], 'last_reviewed_at' => $item['last_reviewed_at'],
        ];
    }
    return $plan;
}

function weekly_review_collect_candidates(PDO $pdo, int $studentId, string $weekStart, string $weekEnd, int $limit = 10): array {
    $current = learning_weekly_review_candidates($pdo, $studentId, $weekStart, $weekEnd, max(3, min(12, $limit)));
    $candidates = [];
    $byKey = [];
    foreach ($current as $candidate) {
        $candidate['candidate_key'] = 'record:' . (int)$candidate['record_id'];
        $candidate['record_ids'] = [(int)$candidate['record_id']];
        $candidate['priority'] = (int)($candidate['priority'] ?? 1);
        $byKey[$candidate['candidate_key']] = count($candidates);
        $candidates[] = $candidate;
    }

    $carry = $pdo->prepare("SELECT i.item_key, i.status, i.last_outcome, i.updated_at, i.details_json, p.week_start FROM weekly_review_items i JOIN weekly_review_plans p ON p.id = i.plan_id WHERE i.student_id = ? AND p.week_start < ? AND p.week_start >= DATE_SUB(?, INTERVAL 6 WEEK) AND i.status IN ('proposed','in_progress','not_done','needs_review') ORDER BY FIELD(i.status,'needs_review','in_progress','not_done','proposed'), p.week_start DESC, i.position LIMIT 20");
    $carry->execute([$studentId, $weekStart, $weekStart]);
    foreach ($carry->fetchAll() as $row) {
        $details = weekly_review_safe_json($row['details_json']);
        $candidate = weekly_review_safe_json($details['candidate'] ?? null);
        if (!$candidate) continue;
        $candidate['candidate_key'] = (string)($candidate['candidate_key'] ?? ('carry:' . $row['item_key']));
        $candidate['carried_from_week'] = (string)$row['week_start'];
        $candidate['previous_review_status'] = (string)$row['status'];
        $candidate['previous_review_outcome'] = $row['last_outcome'];
        $ageWeeks = max(0, (int)floor((strtotime($weekStart) - strtotime((string)$row['week_start'])) / 604800));
        $bonus = match ($row['status']) { 'needs_review' => 7, 'in_progress' => 5, 'not_done' => 3, default => 1 };
        $candidate['priority'] = max(1, (int)($candidate['priority'] ?? 1) + $bonus - min(5, $ageWeeks));
        if (isset($byKey[$candidate['candidate_key']])) {
            $idx = $byKey[$candidate['candidate_key']];
            $candidates[$idx]['carried_from_week'] = $candidate['carried_from_week'];
            $candidates[$idx]['previous_review_status'] = $candidate['previous_review_status'];
            $candidates[$idx]['previous_review_outcome'] = $candidate['previous_review_outcome'];
            $candidates[$idx]['priority'] += $bonus;
        } else {
            $byKey[$candidate['candidate_key']] = count($candidates);
            $candidates[] = $candidate;
        }
    }
    usort($candidates, static fn(array $a, array $b): int => ((int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0)) ?: strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
    return array_slice($candidates, 0, max(1, min(12, $limit)));
}

function weekly_review_log_usage(PDO $pdo, int $studentId, ?int $planId, ?int $sessionId, string $requestType, string $model, ?array $response, bool $success, ?string $errorCode, array $config): ?int {
    $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
    $inputTokens = isset($usage['input_tokens']) ? max(0, (int)$usage['input_tokens']) : null;
    $outputTokens = isset($usage['output_tokens']) ? max(0, (int)$usage['output_tokens']) : null;
    $inputRate = isset($config['ai_input_usd_per_million']) && is_numeric($config['ai_input_usd_per_million']) ? max(0, (float)$config['ai_input_usd_per_million']) : null;
    $outputRate = isset($config['ai_output_usd_per_million']) && is_numeric($config['ai_output_usd_per_million']) ? max(0, (float)$config['ai_output_usd_per_million']) : null;
    $cost = $inputTokens !== null && $outputTokens !== null && $inputRate !== null && $outputRate !== null
        ? ($inputTokens * $inputRate + $outputTokens * $outputRate) / 1000000 : null;
    $stmt = $pdo->prepare('INSERT INTO weekly_review_api_usage (student_id, plan_id, session_id, request_type, model, response_id, input_tokens, output_tokens, input_usd_per_million, output_usd_per_million, estimated_cost_usd, success, error_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$studentId, $planId, $sessionId, $requestType, $model, isset($response['id']) ? mb_substr((string)$response['id'], 0, 100) : null, $inputTokens, $outputTokens, $inputRate, $outputRate, $cost, $success ? 1 : 0, $errorCode ? mb_substr($errorCode, 0, 100) : null]);
    return (int)$pdo->lastInsertId();
}

function weekly_review_call_api(array $config, string $instructions, array $input, array $schema, string $schemaName, int $maxTokens): array {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。');
    if (!function_exists('curl_init')) throw new RuntimeException('サーバーでcURLが利用できません。');
    $payload = json_encode(['model' => $config['ai_model'], 'instructions' => $instructions, 'input' => $input, 'text' => ['format' => ['type' => 'json_schema', 'name' => $schemaName, 'strict' => true, 'schema' => $schema]], 'max_output_tokens' => $maxTokens], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('Responses API ' . ($status ?: 'network') . ($curlError ? ':' . $curlError : ''));
    $response = json_decode($raw, true);
    if (!is_array($response)) throw new RuntimeException('Responses API returned invalid JSON.');
    $text = '';
    foreach (($response['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) {
        if (($part['type'] ?? '') === 'refusal') throw new RuntimeException('model_refusal');
        if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
    }
    $decoded = json_decode($text, true);
    if (!is_array($decoded)) throw new RuntimeException('Responses API returned invalid structured output.');
    return ['data' => $decoded, 'response' => $response];
}

function weekly_review_fallback_recommendation(array $candidate): array {
    $reasons = [];
    if (!empty($candidate['not_understood'])) $reasons[] = '振り返りで「' . mb_substr((string)$candidate['not_understood'], 0, 70) . '」が次の確認事項に残っています';
    foreach (($candidate['problems'] ?? []) as $problem) {
        if (in_array($problem['result'] ?? '', ['incorrect','partially_correct'], true)) {
            $mark = ($problem['source'] ?? '') === 'student_mark' ? '写真の生徒の印' : ((($problem['source'] ?? '') === 'ai_estimate') ? '写真解析の推定' : '記録');
            $reasons[] = $mark . 'で' . (($problem['result'] ?? '') === 'incorrect' ? '×' : '△') . 'が付いた問題があります';
            break;
        }
    }
    foreach (($candidate['questions'] ?? []) as $question) if (in_array($question['status'] ?? '', ['unanswered','unsure','skipped','hint_given'], true)) { $reasons[] = 'AIの確認質問で、もう一度思い出したい内容があります'; break; }
    if (!empty($candidate['previous_review_status'])) $reasons[] = '前回の復習状態は「' . $candidate['previous_review_status'] . '」で、続けて確認できます';
    if (!$reasons) $reasons[] = '今週学んだ内容を、忘れないうちに思い出す練習をします';
    return ['reason' => implode('。', array_slice(array_unique($reasons), 0, 2)) . '。', 'how_to_review' => '教材を見ずに、まず覚えていることを自分の言葉で説明してみよう。', 'estimated_minutes' => 10];
}

function weekly_review_plan(PDO $pdo, array $config, int $studentId, string $weekStart, string $weekEnd, bool $force = false): ?array {
    $candidates = weekly_review_collect_candidates($pdo, $studentId, $weekStart, $weekEnd, 10);
    $sourceHash = hash('sha256', json_encode([$candidates, (string)($config['ai_model'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $existing = weekly_review_load_plan($pdo, $studentId, $weekStart);
    if ($existing && hash_equals((string)$existing['source_hash'], $sourceHash)) return $existing;
    if ($existing) {
        $started = $pdo->prepare('SELECT 1 FROM weekly_review_sessions WHERE student_id = ? AND week_start = ? LIMIT 1');
        $started->execute([$studentId, $weekStart]);
        if ($started->fetchColumn()) return $existing;
    }
    if (!$candidates) {
        if ($existing) return $existing;
        $stmt = $pdo->prepare("INSERT INTO weekly_review_plans (student_id, week_start, week_end, source_hash, status, generation_source, recommendations_json, model, generated_at) VALUES (?, ?, ?, ?, 'proposed', 'none', '[]', NULL, NOW()) ON DUPLICATE KEY UPDATE source_hash = VALUES(source_hash), week_end = VALUES(week_end), generation_source = 'none', recommendations_json = '[]', model = NULL, generated_at = NOW()");
        $stmt->execute([$studentId, $weekStart, $weekEnd, $sourceHash]);
        return weekly_review_load_plan($pdo, $studentId, $weekStart);
    }

    $lockName = 'mnrw_' . substr(hash('sha256', $studentId . ':' . $weekStart), 0, 48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) return $existing;
    try {
        $existing = weekly_review_load_plan($pdo, $studentId, $weekStart);
        if ($existing && hash_equals((string)$existing['source_hash'], $sourceHash)) return $existing;
        if ($existing) {
            $started = $pdo->prepare('SELECT 1 FROM weekly_review_sessions WHERE student_id = ? AND week_start = ? LIMIT 1');
            $started->execute([$studentId, $weekStart]);
            if ($started->fetchColumn()) return $existing;
        }

        $generationSource = 'rules';
        $model = null;
        $recommendations = [];
        $usageResponse = null;
        $generationError = null;
        if (!empty($config['ai_api_key']) && !empty($config['ai_model'])) {
            $allowedKeys = array_values(array_map(static fn(array $item): string => (string)$item['candidate_key'], $candidates));
            $candidateData = array_map(static function (array $item): array {
                return ['candidate_key' => (string)$item['candidate_key'], 'subject' => $item['subject'] ?? null, 'unit' => $item['unit'] ?? null, 'summary' => $item['summary'] ?? null, 'understanding' => $item['understanding'] ?? null, 'not_understood' => $item['not_understood'] ?? null, 'next_check' => $item['next_check'] ?? null, 'problems' => $item['problems'] ?? [], 'questions' => $item['questions'] ?? [], 'photos' => $item['photos'] ?? [], 'carried_from_week' => $item['carried_from_week'] ?? null, 'previous_review_status' => $item['previous_review_status'] ?? null, 'priority' => $item['priority'] ?? 0];
            }, $candidates);
            $schema = ['type' => 'object', 'properties' => ['recommendations' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'properties' => ['candidate_key' => ['type' => 'string', 'enum' => $allowedKeys], 'reason' => ['type' => 'string'], 'how_to_review' => ['type' => 'string'], 'estimated_minutes' => ['type' => 'integer']], 'required' => ['candidate_key','reason','how_to_review','estimated_minutes'], 'additionalProperties' => false]]], 'required' => ['recommendations'], 'additionalProperties' => false];
            try {
                $called = weekly_review_call_api($config, 'あなたは生徒の週末復習を選ぶ学習支援者です。与えられた記録だけを根拠に、2〜3項目まで選びます。理解度が低い、本人が分からないと申告、確認質問に詰まった、問題に×や△が付いた内容と前週からの未完了を優先します。一度正解しただけで単元全体を理解したと判断しません。教材名・ページ・問題番号は入力中に存在するときだけ根拠として選び、理由や復習方法に架空の情報を入れません。提案は生徒がまず自分で思い出すための短い言葉にします。candidate_keyは入力値をそのまま使います。', [['role' => 'user', 'content' => json_encode($candidateData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]], $schema, 'manalog_weekly_review_plan', 1200);
                $usageResponse = $called['response'];
                $model = (string)($called['response']['model'] ?? $config['ai_model']);
                $recommendations = is_array($called['data']['recommendations'] ?? null) ? $called['data']['recommendations'] : [];
                $generationSource = 'ai';
            } catch (Throwable $e) {
                $generationError = preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $e->getMessage()) ? $e->getMessage() : 'api_error';
                error_log('ManaLog weekly review proposal failed: ' . $e->getMessage());
            }
        }
        $candidateMap = [];
        foreach ($candidates as $candidate) $candidateMap[(string)$candidate['candidate_key']] = $candidate;
        $clean = [];
        foreach ($recommendations as $entry) {
            if (!is_array($entry)) continue;
            $key = (string)($entry['candidate_key'] ?? '');
            if (!isset($candidateMap[$key])) continue;
            $candidate = $candidateMap[$key];
            $reason = learning_safe_text($entry['reason'] ?? null, 500) ?? weekly_review_fallback_recommendation($candidate)['reason'];
            $how = learning_safe_text($entry['how_to_review'] ?? null, 500) ?? '教材を見ずに、覚えていることを説明してみよう。';
            $minutes = filter_var($entry['estimated_minutes'] ?? null, FILTER_VALIDATE_INT);
            $minutes = $minutes === false ? 10 : max(5, min(30, $minutes));
            $clean[] = ['candidate_key' => $key, 'candidate' => $candidate, 'reason' => $reason, 'how_to_review' => $how, 'estimated_minutes' => $minutes];
            if (count($clean) >= 3) break;
        }
        if (!$clean) {
            $generationSource = 'rules';
            foreach (array_slice($candidates, 0, 3) as $candidate) $clean[] = ['candidate_key' => $candidate['candidate_key'], 'candidate' => $candidate, ...weekly_review_fallback_recommendation($candidate)];
        }
        $pdo->beginTransaction();
        try {
            $upsert = $pdo->prepare("INSERT INTO weekly_review_plans (student_id, week_start, week_end, source_hash, status, generation_source, recommendations_json, model, generated_at) VALUES (?, ?, ?, ?, 'proposed', ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE week_end = VALUES(week_end), source_hash = VALUES(source_hash), status = 'proposed', generation_source = VALUES(generation_source), recommendations_json = VALUES(recommendations_json), model = VALUES(model), generated_at = NOW(), id = LAST_INSERT_ID(id)");
            $upsert->execute([$studentId, $weekStart, $weekEnd, $sourceHash, $generationSource, json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $model]);
            $planId = (int)$pdo->lastInsertId();
            $pdo->prepare('DELETE FROM weekly_review_items WHERE plan_id = ?')->execute([$planId]);
            $insert = $pdo->prepare("INSERT INTO weekly_review_items (plan_id, student_id, item_key, position, learning_record_id, details_json, status) VALUES (?, ?, ?, ?, ?, ?, 'proposed')");
            foreach ($clean as $index => $item) {
                $recordId = isset($item['candidate']['record_id']) ? (int)$item['candidate']['record_id'] : null;
                $insert->execute([$planId, $studentId, hash('sha256', (string)$item['candidate_key']), $index + 1, $recordId ?: null, json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
            }
            if ($usageResponse) weekly_review_log_usage($pdo, $studentId, $planId, null, 'proposal', $model ?: (string)$config['ai_model'], $usageResponse, true, null, $config);
            elseif ($generationError) weekly_review_log_usage($pdo, $studentId, $planId, null, 'proposal', (string)$config['ai_model'], null, false, $generationError, $config);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return weekly_review_load_plan($pdo, $studentId, $weekStart);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

function weekly_review_ai_reply(PDO $pdo, array $config, int $studentId, int $sessionId, int $planId, array $history, array $item, string $message, int $turn, bool $photoSubmitted): array {
    $model = (string)($config['ai_model'] ?? '');
    $candidate = $item['candidate'] ?? [];
    $facts = ['subject' => $candidate['subject'] ?? null, 'unit' => $candidate['unit'] ?? null, 'summary' => $candidate['summary'] ?? null, 'next_check' => $candidate['next_check'] ?? null, 'problems' => $candidate['problems'] ?? [], 'previous_review_outcome' => $candidate['previous_review_outcome'] ?? null, 'photo_submitted' => $photoSubmitted];
    $schema = ['type' => 'object', 'properties' => ['reply' => ['type' => 'string'], 'answer_status' => ['type' => 'string', 'enum' => ['recalled','hinted','unsure','unable','reported_done','photo_submitted','unclear']], 'understanding_level' => ['type' => ['string','null'], 'enum' => ['not_understood','needs_help','partial','mostly_understood','confident',null]], 'understanding_note' => ['type' => 'string']], 'required' => ['reply','answer_status','understanding_level','understanding_note'], 'additionalProperties' => false];
    $input = $history;
    $input[] = ['role' => 'user', 'content' => "今回の復習対象（記録にある事実のみ）: " . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n復習方法: " . (string)($item['how_to_review'] ?? '') . "\n今回の生徒の発言・写真解析: " . $message];
    try {
        $result = weekly_review_call_api($config, 'あなたは学習を思い出す聞き役です。答えを先に説明せず、生徒が自分で思い出すよう短く自然な質問を一つだけします。分からないときは、まず小さなヒントを出して待ちます。単発の正答で単元を理解済みと断定しません。生徒が「復習した」「やった」と明確に言ったときだけ answer_status=reported_done にします。画像が送られた場合は photo_submitted とします。画像解析結果や会話にない教材・問題情報を作りません。理解度は回答の具体性を控えめに推定し、根拠がなければnullにします。会話は4回以内で終わり、turn=' . $turn . 'です。最後の回答では質問を追加せず、短く締めてください。出力JSONのreplyだけを生徒に表示します。', $input, $schema, 'manalog_weekly_review_reply', 500);
        $data = $result['data'];
        $status = in_array($data['answer_status'] ?? '', ['recalled','hinted','unsure','unable','reported_done','photo_submitted','unclear'], true) ? $data['answer_status'] : 'unclear';
        if ($photoSubmitted) $status = 'photo_submitted';
        $level = in_array($data['understanding_level'] ?? null, learning_understanding_values(), true) ? $data['understanding_level'] : null;
        $usageId = weekly_review_log_usage($pdo, $studentId, $planId, $sessionId, 'conversation', (string)($result['response']['model'] ?? $model), $result['response'], true, null, $config);
        return ['reply' => learning_safe_text($data['reply'] ?? null, 1500) ?? 'そうなんだ。もう少し思い出せそうなところはある？', 'answer_status' => $status, 'understanding_level' => $level, 'understanding_note' => learning_safe_text($data['understanding_note'] ?? null, 1000), 'usage_id' => $usageId];
    } catch (Throwable $e) {
        weekly_review_log_usage($pdo, $studentId, $planId, $sessionId, 'conversation', $model ?: 'unknown', null, false, 'api_error', $config);
        error_log('ManaLog weekly review reply failed: ' . $e->getMessage());
        throw new RuntimeException('復習の返事を作れませんでした。もう一度お試しください。');
    }
}

function weekly_review_save_response(PDO $pdo, int $studentId, int $sessionId, int $planId, array $item, array $reply, int $userMessageId, int $assistantMessageId, bool $photoSubmitted): void {
    $status = $photoSubmitted ? 'photo_submitted' : (string)($reply['answer_status'] ?? 'unclear');
    if (!in_array($status, ['recalled','hinted','unsure','unable','reported_done','photo_submitted','unclear'], true)) $status = 'unclear';
    $itemStatus = in_array($status, ['recalled','reported_done'], true) ? 'completed' : (in_array($status, ['hinted','unsure','unable','photo_submitted'], true) ? 'needs_review' : 'in_progress');
    $itemId = (int)$item['id'];
    $level = in_array($reply['understanding_level'] ?? null, learning_understanding_values(), true) ? $reply['understanding_level'] : null;
    $pdo->prepare('UPDATE weekly_review_items SET status = ?, last_outcome = ?, understanding_level = COALESCE(?, understanding_level), last_reviewed_at = NOW() WHERE id = ? AND plan_id = ? AND student_id = ?')->execute([$itemStatus, $status, $level, $itemId, $planId, $studentId]);
    $stmt = $pdo->prepare('INSERT INTO weekly_review_responses (student_id, plan_id, item_id, session_id, user_message_id, assistant_message_id, answer_status, understanding_level, understanding_note, usage_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$studentId, $planId, $itemId, $sessionId, $userMessageId, $assistantMessageId, $status, $level, $reply['understanding_note'] ?? null, $reply['usage_id'] ?? null]);
    $recordId = (int)($item['candidate']['record_id'] ?? 0);
    if ($level && $recordId) {
        $unit = $pdo->prepare('SELECT learning_unit_id, student_id FROM learning_records WHERE id = ? LIMIT 1');
        $unit->execute([$recordId]);
        $row = $unit->fetch();
        if ($row && (int)$row['student_id'] === $studentId && $row['learning_unit_id']) {
            $note = learning_safe_text($reply['understanding_note'] ?? null, 1000) ?? '週末復習の回答からのAI推定。単元全体の理解を断定しない。';
            $pdo->prepare("INSERT INTO learning_understanding_history (student_id, learning_unit_id, learning_record_id, observation_source, understanding_level, evidence_note, confidence, observed_at) VALUES (?, ?, ?, 'later_review', ?, ?, NULL, NOW())")->execute([$studentId, (int)$row['learning_unit_id'], $recordId, $level, $note]);
        }
    }
}

function weekly_review_finish(PDO $pdo, int $studentId, int $sessionId): void {
    $stmt = $pdo->prepare('SELECT w.plan_id FROM weekly_review_sessions w JOIN chat_sessions s ON s.id = w.session_id WHERE w.session_id = ? AND w.student_id = ? AND s.ended_at IS NOT NULL');
    $stmt->execute([$sessionId, $studentId]);
    $planId = (int)$stmt->fetchColumn();
    if (!$planId) return;
    $pdo->prepare("UPDATE weekly_review_items SET status = CASE WHEN status = 'proposed' THEN 'not_done' WHEN status = 'in_progress' THEN 'needs_review' ELSE status END WHERE plan_id = ? AND student_id = ?")->execute([$planId, $studentId]);
    $counts = $pdo->prepare("SELECT SUM(status = 'completed') AS completed_count, COUNT(*) AS total_count, SUM(status IN ('not_done','needs_review')) AS remaining_count FROM weekly_review_items WHERE plan_id = ? AND student_id = ?");
    $counts->execute([$planId, $studentId]);
    $row = $counts->fetch();
    $planStatus = (int)$row['completed_count'] === (int)$row['total_count'] ? 'completed' : ((int)$row['completed_count'] > 0 ? 'partial' : 'not_done');
    $pdo->prepare('UPDATE weekly_review_plans SET status = ? WHERE id = ? AND student_id = ?')->execute([$planStatus, $planId, $studentId]);
}

function weekly_review_notification_collision(PDO $pdo, int $studentId, DateTimeImmutable $weeklyDue): bool {
    $date = $weeklyDue->format('Y-m-d');
    $stmt = $pdo->prepare("SELECT 1 FROM notification_deliveries WHERE student_id = ? AND due_at BETWEEN ? AND ? AND notification_type IN ('after_school','night') AND status IN ('pending','sending','sent') LIMIT 1");
    $stmt->execute([$studentId, $weeklyDue->modify('-2 hours')->format('Y-m-d H:i:s'), $weeklyDue->modify('+2 hours')->format('Y-m-d H:i:s')]);
    if ($stmt->fetchColumn()) return true;
    $day = (int)$weeklyDue->format('N');
    $stmt = $pdo->prepare('SELECT day_of_week, after_school_time, after_school_enabled, night_time, night_enabled FROM notification_schedule WHERE student_id = ? AND day_of_week IN (?, ?)');
    $stmt->execute([$studentId, $day, $day === 1 ? 7 : $day - 1]);
    foreach ($stmt->fetchAll() as $row) foreach (['after_school','night'] as $type) {
        $time = $row[$type . '_time'];
        if (!(int)$row[$type . '_enabled'] || !$time) continue;
        try { $dailyDue = notification_due_at((int)$row['day_of_week'] === $day ? $date : (new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo')))->modify('-1 day')->format('Y-m-d'), (string)$time); }
        catch (Throwable) { continue; }
        if (abs($dailyDue->getTimestamp() - $weeklyDue->getTimestamp()) <= 7200) return true;
    }
    return false;
}

function queue_due_weekly_review_notifications(PDO $pdo, DateTimeImmutable $now): int {
    $now = $now->setTimezone(new DateTimeZone('Asia/Tokyo'));
    $day = (int)$now->format('N');
    if (!in_array($day, [6, 7], true)) return 0;
    $stmt = $pdo->prepare("SELECT p.student_id, p.weekly_review_time FROM notification_preferences p JOIN users u ON u.id = p.student_id WHERE u.role = 'student' AND u.status = 'active' AND p.enabled = 1 AND p.paused = 0 AND p.weekly_review_enabled = 1 AND p.weekly_review_day = ? AND EXISTS (SELECT 1 FROM push_subscriptions sub WHERE sub.student_id = p.student_id AND sub.revoked_at IS NULL)");
    $stmt->execute([$day]);
    [$weekStart, $weekEnd] = weekly_review_range($now);
    $insert = $pdo->prepare("INSERT IGNORE INTO weekly_review_notifications (student_id, week_start, due_at) VALUES (?, ?, ?)");
    $skipCollision = $pdo->prepare("INSERT IGNORE INTO weekly_review_notifications (student_id, week_start, due_at, status, last_error) VALUES (?, ?, ?, 'skipped', '通常の振り返り通知と2時間以内のため省略しました。')");
    $queued = 0;
    foreach ($stmt->fetchAll() as $row) {
        $due = notification_due_at($now->format('Y-m-d'), (string)$row['weekly_review_time']);
        if ($due > $now || $due < $now->modify('-8 hours')) continue;
        if (weekly_review_notification_collision($pdo, (int)$row['student_id'], $due)) {
            $skipCollision->execute([(int)$row['student_id'], $weekStart, $due->format('Y-m-d H:i:s')]);
            continue;
        }
        $insert->execute([(int)$row['student_id'], $weekStart, $due->format('Y-m-d H:i:s')]);
        $queued += $insert->rowCount();
    }
    return $queued;
}

function send_due_weekly_review_notifications(PDO $pdo, array $config, DateTimeImmutable $now, ?callable $send = null): array {
    $queued = queue_due_weekly_review_notifications($pdo, $now);
    $send ??= push_sender($config);
    $nowText = $now->format('Y-m-d H:i:s');
    $select = $pdo->prepare("SELECT id FROM weekly_review_notifications WHERE due_at BETWEEN ? AND ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?)) ORDER BY due_at, id LIMIT 100");
    $select->execute([$now->modify('-8 hours')->format('Y-m-d H:i:s'), $nowText, $nowText]);
    $counts = ['queued' => $queued, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    $claim = $pdo->prepare("UPDATE weekly_review_notifications SET status = 'sending', attempts = attempts + 1, lease_until = ? WHERE id = ? AND attempts < 3 AND (status IN ('pending','failed') OR (status = 'sending' AND lease_until < ?))");
    foreach ($select->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $claim->execute([$now->modify('+5 minutes')->format('Y-m-d H:i:s'), $id, $nowText]);
        if ($claim->rowCount() !== 1) continue;
        try {
            $stmt = $pdo->prepare('SELECT * FROM weekly_review_notifications WHERE id = ?');
            $stmt->execute([(int)$id]);
            $delivery = $stmt->fetch();
            $pref = $pdo->prepare("SELECT p.enabled, p.paused, p.weekly_review_enabled, u.status FROM notification_preferences p JOIN users u ON u.id = p.student_id WHERE p.student_id = ? AND u.role = 'student'");
            $pref->execute([(int)$delivery['student_id']]);
            $settings = $pref->fetch();
            if (!$settings || $settings['status'] !== 'active' || !(int)$settings['enabled'] || (int)$settings['paused'] || !(int)$settings['weekly_review_enabled']) throw new DomainException('通知設定が無効です。');
            $due = new DateTimeImmutable((string)$delivery['due_at'], new DateTimeZone('Asia/Tokyo'));
            if (weekly_review_notification_collision($pdo, (int)$delivery['student_id'], $due)) throw new DomainException('通常の振り返り通知と近いため、週末通知を省略しました。');
            [$weekStart, $weekEnd] = [(string)$delivery['week_start'], (new DateTimeImmutable((string)$delivery['week_start'], new DateTimeZone('Asia/Tokyo')))->modify('sunday this week')->format('Y-m-d')];
            $sessionId = weekly_review_session($pdo, (int)$delivery['student_id'], $weekStart, $weekEnd, $config);
            $pdo->prepare('UPDATE weekly_review_notifications SET session_id = ? WHERE id = ?')->execute([$sessionId, (int)$id]);
            $started = $pdo->prepare("SELECT s.ended_at, EXISTS (SELECT 1 FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') AS answered FROM chat_sessions s WHERE s.id = ? AND s.student_id = ?");
            $started->execute([$sessionId, (int)$delivery['student_id']]);
            $session = $started->fetch();
            if (!$session || $session['ended_at'] !== null || (int)$session['answered']) throw new DomainException('週末復習はすでに開始されています。');
            $subs = $pdo->prepare('SELECT endpoint_hash, endpoint, p256dh, auth, content_encoding FROM push_subscriptions WHERE student_id = ? AND revoked_at IS NULL');
            $subs->execute([(int)$delivery['student_id']]);
            $subscriptions = $subs->fetchAll();
            if (!$subscriptions) throw new DomainException('有効な購読端末がありません。');
            $baseUrl = rtrim((string)($config['app_base_url'] ?? ''), '/') . '/';
            if (!preg_match('#^https://#', $baseUrl) && !str_starts_with($baseUrl, 'http://localhost/')) throw new RuntimeException('APP_BASE_URL must use HTTPS.');
            $payload = json_encode(['title' => '今週のおすすめ復習', 'body' => '今週の学びを思い出してみよう。', 'url' => $baseUrl . '?session=' . $sessionId, 'tag' => 'manalog-weekly-' . $weekStart], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $sentCount = 0;
            $errors = [];
            foreach ($subscriptions as $subscription) {
                try {
                    $result = $send($subscription, $payload);
                    if (!empty($result['success'])) $sentCount++;
                    else {
                        $errors[] = (string)($result['error'] ?? 'Push送信に失敗しました。');
                        if (!empty($result['expired'])) $pdo->prepare('UPDATE push_subscriptions SET revoked_at = CURRENT_TIMESTAMP WHERE endpoint_hash = ?')->execute([$subscription['endpoint_hash']]);
                    }
                } catch (Throwable $e) { $errors[] = $e->getMessage(); }
            }
            if ($sentCount) {
                $pdo->prepare("UPDATE weekly_review_notifications SET status = 'sent', sent_at = ?, lease_until = NULL, last_error = NULL WHERE id = ?")->execute([$nowText, (int)$id]);
                $counts['sent']++;
            } else throw new RuntimeException(mb_substr(implode(' / ', $errors) ?: 'Push送信に失敗しました。', 0, 1000));
        } catch (DomainException $e) {
            $pdo->prepare("UPDATE weekly_review_notifications SET status = 'skipped', lease_until = NULL, last_error = ? WHERE id = ?")->execute([$e->getMessage(), (int)$id]);
            $counts['skipped']++;
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE weekly_review_notifications SET status = 'failed', lease_until = NULL, last_error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 1000), (int)$id]);
            $counts['failed']++;
        }
    }
    return $counts;
}
