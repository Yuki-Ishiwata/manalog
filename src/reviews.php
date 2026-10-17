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
            if ($type === 'after_school') {
                $stmt = $pdo->prepare('SELECT e.period_number, e.subject FROM timetables t JOIN timetable_entries e ON e.timetable_id = t.id WHERE t.student_id = ? AND t.is_active = 1 AND e.day_of_week = ? ORDER BY e.period_number');
                $stmt->execute([$studentId, (int)$date->format('N')]);
                $scheduled = $stmt->fetchAll();
                $stmt = $pdo->prepare('SELECT subject FROM review_excluded_subjects WHERE student_id = ?');
                $stmt->execute([$studentId]);
                $excluded = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
                $subjects = array_values(array_filter($scheduled, static fn($subject) => !isset($excluded[$subject['subject']])));
                if ($scheduled && !$subjects) {
                    $pdo->commit();
                    return null;
                }
            }

            $stmt = $pdo->prepare('INSERT INTO chat_sessions (student_id, session_date, session_type, questions_per_subject) VALUES (?, ?, ?, ?)');
            $stmt->execute([$studentId, $reviewDate, $type, $subjects ? 2 : 1]);
            $sessionId = (int)$pdo->lastInsertId();
            if ($subjects) {
                $insert = $pdo->prepare('INSERT INTO chat_session_subjects (session_id, position, period_number, subject) VALUES (?, ?, ?, ?)');
                foreach ($subjects as $index => $subject) $insert->execute([$sessionId, $index + 1, $subject['period_number'], $subject['subject']]);
            }
            $opening = $type === 'night'
                ? '今日、家でどんな勉強をした？ していなければ、そのまま教えてね。'
                : ($subjects ? review_subject_question((int)$subjects[0]['period_number'], (string)$subjects[0]['subject']) : '今日は学校で何を勉強した？');
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
