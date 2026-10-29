<?php
declare(strict_types=1);

function review_subject_question(int $period, string $subject): string {
    return $period . '時間目の' . $subject . 'は、何を勉強した？';
}

/** Create one durable conversation per student, school day, and review type. */
function daily_review_session(PDO $pdo, int $studentId, string $reviewDate, string $type): ?int {
    if (!in_array($type, ['after_school', 'night'], true)) throw new InvalidArgumentException('Unknown review type.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewDate, new DateTimeZone('Asia/Tokyo'));
    if (!$date || $date->format('Y-m-d') !== $reviewDate) throw new InvalidArgumentException('Invalid review date.');

    $pdo->beginTransaction();
    try {
        // The student row serializes concurrent web requests and cron runs.
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND status = 'active' FOR UPDATE");
        $stmt->execute([$studentId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('生徒アカウントを利用できません。');

        $stmt = $pdo->prepare('SELECT session_id FROM daily_review_sessions WHERE student_id = ? AND review_date = ? AND review_type = ?');
        $stmt->execute([$studentId, $reviewDate, $type]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $pdo->commit();
            return (int)$existing;
        }

        // Map an existing Phase 1 conversation rather than asking its questions again.
        $legacyMatch = $type === 'after_school'
            ? "(s.session_type = 'after_school' OR (s.session_type = 'general' AND (SELECT m.content FROM chat_messages m WHERE m.session_id = s.id ORDER BY m.id LIMIT 1) = '今日は学校で何を勉強した？'))"
            : "s.session_type = 'night'";
        $stmt = $pdo->prepare("SELECT s.id FROM chat_sessions s WHERE s.student_id = ? AND s.session_date = ? AND $legacyMatch ORDER BY (s.ended_at IS NOT NULL) DESC, (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id = s.id AND m.role = 'user') DESC, s.id DESC LIMIT 1");
        $stmt->execute([$studentId, $reviewDate]);
        $sessionId = (int)($stmt->fetchColumn() ?: 0);
        if (!$sessionId) {
            $subjects = [];
            $stmt = $pdo->prepare('SELECT timetable_unavailable FROM student_profiles WHERE user_id = ?');
            $stmt->execute([$studentId]);
            $timetableUnavailable = (bool)$stmt->fetchColumn();
            if ($type === 'after_school') {
                $scheduled = [];
                if (!$timetableUnavailable) {
                    $stmt = $pdo->prepare('SELECT e.period_number, e.subject FROM timetables t JOIN timetable_entries e ON e.timetable_id = t.id WHERE t.student_id = ? AND t.is_active = 1 AND e.day_of_week = ? ORDER BY e.period_number');
                    $stmt->execute([$studentId, (int)$date->format('N')]);
                    $scheduled = $stmt->fetchAll();
                }
                $stmt = $pdo->prepare('SELECT subject FROM review_excluded_subjects WHERE student_id = ?');
                $stmt->execute([$studentId]);
                $excluded = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
                $subjects = array_values(array_filter($scheduled, static fn($subject) => !isset($excluded[$subject['subject']])));
                if ($scheduled && !$subjects) {
                    $pdo->commit();
                    return null;
                }
            }

            $initialStage = $type === 'night' || ($type === 'after_school' && $timetableUnavailable) ? 'activity' : 'recall';
            $stmt = $pdo->prepare('INSERT INTO chat_sessions (student_id, session_date, session_type, questions_per_subject, review_stage) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$studentId, $reviewDate, $type, $subjects ? 2 : 1, $initialStage]);
            $sessionId = (int)$pdo->lastInsertId();
            if ($subjects) {
                $insert = $pdo->prepare('INSERT INTO chat_session_subjects (session_id, position, period_number, subject) VALUES (?, ?, ?, ?)');
                foreach ($subjects as $index => $subject) $insert->execute([$sessionId, $index + 1, $subject['period_number'], $subject['subject']]);
            }
            $opening = $type === 'night'
                ? '今日、家でどんな勉強をした？ していなければ、そのまま教えてね。'
                : ($subjects ? review_subject_question((int)$subjects[0]['period_number'], (string)$subjects[0]['subject']) : ($timetableUnavailable ? '1時間目は何の教科だった？' : '今日は学校で何を勉強した？'));
            $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
            $stmt->execute([$sessionId, $opening]);
        }

        $stmt = $pdo->prepare('INSERT INTO daily_review_sessions (student_id, review_date, review_type, session_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$studentId, $reviewDate, $type, $sessionId]);
        $pdo->commit();
        return $sessionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Create one weekly review chat per student and week from the cached proposal. */
function weekly_review_session(PDO $pdo, int $studentId, string $weekStart, string $weekEnd, array $config = []): int {
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, new DateTimeZone('Asia/Tokyo'));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $weekEnd, new DateTimeZone('Asia/Tokyo'));
    if (!$start || !$end || $start->format('Y-m-d') !== $weekStart || $end->format('Y-m-d') !== $weekEnd || $start->format('N') !== '1' || $end->format('N') !== '7' || $end < $start) {
        throw new InvalidArgumentException('Invalid weekly review range.');
    }
    $existingStmt = $pdo->prepare('SELECT session_id FROM weekly_review_sessions WHERE student_id = ? AND week_start = ?');
    $existingStmt->execute([$studentId, $weekStart]);
    $existingSession = $existingStmt->fetchColumn();
    if ($existingSession) return (int)$existingSession;
    $plan = weekly_review_plan($pdo, $config, $studentId, $weekStart, $weekEnd);
    if (!$plan || empty($plan['items'])) throw new DomainException('今週と前週に復習できる学習記録がありません。記録ができると週末に復習できます。');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND status = 'active' FOR UPDATE");
        $stmt->execute([$studentId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('生徒アカウントを利用できません。');

        $stmt = $pdo->prepare('SELECT session_id FROM weekly_review_sessions WHERE student_id = ? AND week_start = ?');
        $stmt->execute([$studentId, $weekStart]);
        $existing = $stmt->fetchColumn();
        if ($existing) { $pdo->commit(); return (int)$existing; }
        $stmt = $pdo->prepare("INSERT INTO chat_sessions (student_id, session_date, session_type, review_stage) VALUES (?, ?, 'weekly_review', 'recall')");
        $stmt->execute([$studentId, (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')]);
        $sessionId = (int)$pdo->lastInsertId();
        $snapshotItems = array_map(static fn(array $item): array => [
            'id' => (int)$item['id'], 'item_key' => (string)$item['item_key'], 'candidate' => $item['candidate'] ?? [],
            'reason' => (string)($item['reason'] ?? ''), 'how_to_review' => (string)($item['how_to_review'] ?? ''),
            'estimated_minutes' => (int)($item['estimated_minutes'] ?? 10), 'status' => (string)$item['status'],
        ], $plan['items']);
        $stmt = $pdo->prepare('INSERT INTO weekly_review_sessions (student_id, week_start, week_end, session_id, plan_id, selected_items_json) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$studentId, $weekStart, $weekEnd, $sessionId, (int)$plan['id'], json_encode($snapshotItems, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        $top = array_slice($snapshotItems, 0, 3);
        $lines = array_map(static function (array $item): string {
            $candidate = $item['candidate'] ?? [];
            $title = trim(implode('・', array_filter([(string)($candidate['subject'] ?? ''), (string)($candidate['unit'] ?? '')]))) ?: '今週の学習';
            $material = '';
            foreach (($candidate['problems'] ?? []) as $problem) {
                $parts = array_filter([(string)($problem['material'] ?? ''), !empty($problem['page']) ? $problem['page'] . 'ページ' : '', !empty($problem['number']) ? $problem['number'] . '番' : '']);
                if ($parts) { $material = '（' . implode(' · ', $parts) . '）'; break; }
            }
            return '・' . $title . $material . '：' . mb_substr((string)($item['reason'] ?? ''), 0, 70) . '（目安' . (int)($item['estimated_minutes'] ?? 10) . '分）';
        }, $top);
        $firstCandidate = $top[0]['candidate'] ?? [];
        $firstTitle = trim(implode('・', array_filter([(string)($firstCandidate['subject'] ?? ''), (string)($firstCandidate['unit'] ?? '')]))) ?: 'この内容';
        $opening = "今週の記録から、復習する内容を選んだよ。\n" . implode("\n", $lines) . "\n\nまずは「" . $firstTitle . '」について、どんなことを覚えている？';
        $stmt = $pdo->prepare("INSERT INTO chat_messages (session_id, role, content) VALUES (?, 'assistant', ?)");
        $stmt->execute([$sessionId, $opening]);
        $pdo->prepare("UPDATE weekly_review_plans SET status = 'in_progress' WHERE id = ? AND student_id = ?")->execute([(int)$plan['id'], $studentId]);
        if ($top) $pdo->prepare("UPDATE weekly_review_items SET status = 'in_progress' WHERE id = ? AND plan_id = ? AND student_id = ? AND status = 'proposed'")->execute([(int)$top[0]['id'], (int)$plan['id'], $studentId]);
        $pdo->commit();
        return $sessionId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
