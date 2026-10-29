<?php
declare(strict_types=1);

function learning_photo_schema(): array {
    $nullableText = ['type' => ['string', 'null']];
    $nullableNumber = ['type' => ['number', 'null']];
    $imageSchema = [
        'type' => 'object',
        'properties' => [
            'subject' => $nullableText, 'unit' => $nullableText, 'material' => $nullableText,
            'page' => $nullableText,
            'text_rotation' => ['type' => 'integer', 'enum' => [0, 90, 180, 270]],
            'rotation_confidence' => $nullableNumber,
            'kind' => ['type' => 'string', 'enum' => ['problem_page', 'notebook', 'other']],
            'problems' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'number' => $nullableText, 'text' => $nullableText, 'answer' => $nullableText,
                    'mark' => ['type' => 'string', 'enum' => ['correct', 'partially_correct', 'incorrect', 'ungraded']],
                    'confidence' => $nullableNumber,
                    'matched_prior_problem_id' => ['type' => ['integer', 'null']],
                ],
                'required' => ['number', 'text', 'answer', 'mark', 'confidence', 'matched_prior_problem_id'], 'additionalProperties' => false,
            ]],
            'notebook_summary' => $nullableText,
        ],
        'required' => ['subject', 'unit', 'material', 'page', 'text_rotation', 'rotation_confidence', 'kind', 'problems', 'notebook_summary'],
        'additionalProperties' => false,
    ];
    return ['type' => 'object', 'properties' => ['image_results' => ['type' => 'array', 'items' => $imageSchema]], 'required' => ['image_results'], 'additionalProperties' => false];
}

function learning_photo_api_copy(array $image): array {
    $bytes = (string)file_get_contents($image['path']);
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) return ['bytes' => $bytes, 'mime' => $image['mime'], 'temporary' => null];
    $source = @imagecreatefromstring($bytes);
    if (!$source) return ['bytes' => $bytes, 'mime' => $image['mime'], 'temporary' => null];
    if ($image['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($image['path']);
        $orientation = (int)($exif['Orientation'] ?? 1);
        $degrees = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($degrees !== 0) { $rotated = imagerotate($source, $degrees, 0); if ($rotated) { imagedestroy($source); $source = $rotated; } }
    }
    $width = imagesx($source); $height = imagesy($source); $maxEdge = 2400;
    if (max($width, $height) > $maxEdge) {
        $ratio = $maxEdge / max($width, $height);
        $resized = imagecreatetruecolor((int)round($width * $ratio), (int)round($height * $ratio));
        imagecopyresampled($resized, $source, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
        imagedestroy($source); $source = $resized;
    }
    $temporary = tempnam(sys_get_temp_dir(), 'manalog-photo-');
    if (!$temporary || !imagejpeg($source, $temporary, 86)) {
        imagedestroy($source);
        if ($temporary && is_file($temporary)) unlink($temporary);
        return ['bytes' => $bytes, 'mime' => $image['mime'], 'temporary' => null];
    }
    imagedestroy($source);
    $optimized = file_get_contents($temporary);
    return ['bytes' => $optimized === false ? $bytes : $optimized, 'mime' => 'image/jpeg', 'temporary' => $temporary];
}

/** Normalize camera orientation and save a readable, metadata-free JPEG. */
function learning_photo_normalize_upload(string $path, string $mime): array {
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException('写真を読み込めませんでした。');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new RuntimeException('写真の向きを補正できません。サーバーの画像処理設定を確認してください。');
    }
    $image = @imagecreatefromstring($bytes);
    if (!$image) throw new RuntimeException('写真を画像として読み込めませんでした。');

    $orientation = 1;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int)($exif['Orientation'] ?? 1);
    }
    $flip = static function (GdImage &$image, int $mode): void {
        if (function_exists('imageflip')) imageflip($image, $mode);
    };
    switch ($orientation) {
        case 2: $flip($image, IMG_FLIP_HORIZONTAL); break;
        case 3: $rotated = imagerotate($image, 180, 0); if ($rotated) { imagedestroy($image); $image = $rotated; } break;
        case 4: $flip($image, IMG_FLIP_VERTICAL); break;
        case 5:
            $flip($image, IMG_FLIP_HORIZONTAL);
            $rotated = imagerotate($image, 90, 0); if ($rotated) { imagedestroy($image); $image = $rotated; }
            break;
        case 6: $rotated = imagerotate($image, -90, 0); if ($rotated) { imagedestroy($image); $image = $rotated; } break;
        case 7:
            $flip($image, IMG_FLIP_HORIZONTAL);
            $rotated = imagerotate($image, -90, 0); if ($rotated) { imagedestroy($image); $image = $rotated; }
            break;
        case 8: $rotated = imagerotate($image, 90, 0); if ($rotated) { imagedestroy($image); $image = $rotated; } break;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $maxEdge = 3200;
    if (max($width, $height) > $maxEdge) {
        $ratio = $maxEdge / max($width, $height);
        $resized = imagecreatetruecolor((int)round($width * $ratio), (int)round($height * $ratio));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
        imagedestroy($image);
        $image = $resized;
    }
    ob_start();
    $saved = imagejpeg($image, null, 92);
    $normalized = ob_get_clean();
    $width = imagesx($image);
    $height = imagesy($image);
    imagedestroy($image);
    if (!$saved || !is_string($normalized) || $normalized === '') throw new RuntimeException('写真を読みやすい向きに変換できませんでした。');
    return ['bytes' => $normalized, 'mime' => 'image/jpeg', 'extension' => 'jpg', 'width' => $width, 'height' => $height];
}

function learning_photo_apply_text_rotation(string $path, int $clockwiseDegrees): ?array {
    $degrees = (($clockwiseDegrees % 360) + 360) % 360;
    if ($degrees === 0) return null;
    $image = @imagecreatefromstring((string)file_get_contents($path));
    if (!$image) throw new RuntimeException('写真の向きを補正できませんでした。');
    $rotated = imagerotate($image, -$degrees, 0);
    imagedestroy($image);
    if (!$rotated) throw new RuntimeException('写真の向きを補正できませんでした。');
    $temporary = tempnam(dirname($path), '.rotate-');
    if (!$temporary || !imagejpeg($rotated, $temporary, 92)) {
        imagedestroy($rotated);
        if ($temporary && is_file($temporary)) unlink($temporary);
        throw new RuntimeException('向きを補正した写真を保存できませんでした。');
    }
    $dimensions = [imagesx($rotated), imagesy($rotated)];
    imagedestroy($rotated);
    chmod($temporary, 0600);
    if (!rename($temporary, $path)) { unlink($temporary); throw new RuntimeException('向きを補正した写真を保存できませんでした。'); }
    return ['width' => $dimensions[0], 'height' => $dimensions[1], 'bytes' => (int)filesize($path)];
}

function learning_photo_analyze(array $config, array $images, array $context): array {
    if (empty($config['ai_api_key']) || empty($config['ai_model'])) throw new RuntimeException('AI APIの設定がありません。');
    $content = [[
        'type' => 'input_text',
        'text' => '学習記録のため画像を解析してください。各画像の順序を保ち、印刷された問題と手書きの解答を区別します。まず主要な日本語の文章が正立するために画像全体を時計回りに何度回転すべきか判定し、text_rotationに0/90/180/270を設定します。向きが明確な場合のみrotation_confidenceを0.85以上にします。赤い○はcorrect、△はpartially_correct、×はincorrect。印がない・読み取れない場合はungradedにします。正誤を答えや解答だけから推測しません。problemごとのmatched_prior_problem_idは、候補一覧の番号・ページ・教材名・問題文と十分一致するときだけ候補の数値IDを返し、不明なら必ずnullです。読めない項目はnullまたは空配列にします。既知の文脈: ' . json_encode($context, JSON_UNESCAPED_UNICODE),
    ]];
    $temporaryFiles = [];
    foreach ($images as $image) {
        $apiImage = learning_photo_api_copy($image);
        if ($apiImage['temporary']) $temporaryFiles[] = $apiImage['temporary'];
        $content[] = ['type' => 'input_text', 'text' => '画像 ' . $image['position']];
        $content[] = ['type' => 'input_image', 'image_url' => 'data:' . $apiImage['mime'] . ';base64,' . base64_encode($apiImage['bytes']), 'detail' => 'high'];
    }
    $payload = json_encode([
        'model' => $config['ai_model'],
        'instructions' => '画像ごとに1個のimage_results要素を、入力順で返してください。まず日本語の本文が正立する回転方向を判定し、その向きで画像を読むものとして抽出してください。回転方向を確信できないときはtext_rotation=0、rotation_confidence=0にします。画像に明示された情報だけを構造化してください。問題番号は枝問を区別し、問題番号のない設問は近くの見出し番号を慎重に補います。複数の小問は別々に出します。科目・単元・教材名・ページが読み取れない場合はnullです。赤い採点記号の出所（生徒・先生）は断定できないため出力対象外です。',
        'input' => [['role' => 'user', 'content' => $content]],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'manalog_photo_analysis', 'strict' => true, 'schema' => learning_photo_schema()]],
        'max_output_tokens' => 5000,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $payload, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    foreach ($temporaryFiles as $temporary) if (is_file($temporary)) unlink($temporary);
    if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('写真を解析できませんでした。時間をおいてもう一度お試しください。');
    $response = json_decode($raw, true);
    $output = '';
    foreach (($response['output'] ?? []) as $item) foreach (($item['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $output .= (string)($part['text'] ?? '');
    $decoded = json_decode($output, true);
    if (!is_array($decoded)) throw new RuntimeException('写真の解析結果を読み取れませんでした。');
    return $decoded;
}

function learning_photo_uploads(PDO $pdo, int $studentId, int $sessionId, array $files, string $activity, ?string $subject, string $date, array $config): array {
    if (empty($files['name']) || !is_array($files['name'])) return ['photos' => [], 'summary' => ''];
    $count = count($files['name']);
    if ($count > 4) throw new RuntimeException('写真は一度に4枚まで送信できます。');
    $root = dirname(__DIR__) . '/storage/uploads/learning/' . $studentId;
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('写真の保存先を作成できませんでした。');
    $items = [];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('写真を受け取れませんでした。選び直してください。');
        $tmp = (string)$files['tmp_name'][$i];
        if (!is_uploaded_file($tmp) || (int)$files['size'][$i] > 8 * 1024 * 1024) throw new RuntimeException('写真は1枚8MB以内にしてください。');
        $info = @getimagesize($tmp);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!$info || !isset($allowed[$mime])) throw new RuntimeException('JPEG・PNG・WebPの写真を選択してください。');
        if ($info[0] > 16000 || $info[1] > 16000 || $info[0] * $info[1] > 60000000) throw new RuntimeException('画像の解像度が大きすぎます。カメラ設定を変えるか、画像を縮小してください。');
        $normalized = learning_photo_normalize_upload($tmp, $mime);
        $bytes = $normalized['bytes'];
        $mime = $normalized['mime'];
        $info = [$normalized['width'], $normalized['height']];
        $extension = $normalized['extension'];
        $hash = hash('sha256', $bytes);
        $stmt = $pdo->prepare('SELECT id, storage_key, analysis_status, analysis_result FROM learning_photos WHERE student_id = ? AND sha256 = ?');
        $stmt->execute([$studentId, $hash]);
        $existing = $stmt->fetch();
        if ($existing) {
            $key = (string)$existing['storage_key'];
            $photoId = (int)$existing['id'];
            $analysis = $existing['analysis_status'] === 'completed' ? json_decode((string)$existing['analysis_result'], true) : null;
        } else {
            $key = 'learning/' . $studentId . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
            $path = dirname(__DIR__) . '/storage/uploads/' . $key;
            if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('写真を保存できませんでした。');
            chmod($path, 0600);
            $stmt = $pdo->prepare("INSERT INTO learning_photos (student_id, storage_key, sha256, mime_type, byte_size, width, height, analysis_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$studentId, $key, $hash, $mime, strlen($bytes), $info[0], $info[1]]);
            $photoId = (int)$pdo->lastInsertId();
            $analysis = null;
        }
        $items[] = ['id' => $photoId, 'key' => $key, 'mime' => $mime, 'path' => dirname(__DIR__) . '/storage/uploads/' . $key, 'position' => $i + 1, 'analysis' => $analysis];
    }
    $pending = array_values(array_filter($items, static fn($item) => !is_array($item['analysis'])));
    if ($pending) {
        foreach ($pending as $item) $pdo->prepare("UPDATE learning_photos SET analysis_status = 'processing', analysis_error = NULL WHERE id = ? AND student_id = ?")->execute([$item['id'], $studentId]);
        try {
            $candidateStmt = $pdo->prepare("SELECT p.id, p.problem_number, p.problem_text, p.page_number, p.material_name FROM learning_problems p JOIN learning_records r ON r.id = p.learning_record_id WHERE r.student_id = ? AND p.answer_result = 'ungraded' AND r.learning_date >= DATE_SUB(?, INTERVAL 30 DAY) ORDER BY r.learning_date DESC LIMIT 30");
            $candidateStmt->execute([$studentId, $date]);
            $candidateProblems = array_map(static fn($row) => ['id' => (int)$row['id'], 'number' => $row['problem_number'], 'text' => mb_substr((string)$row['problem_text'], 0, 220), 'page' => $row['page_number'], 'material' => $row['material_name']], $candidateStmt->fetchAll());
            $analyses = learning_photo_analyze($config, $pending, ['session_type' => $activity, 'subject' => $subject, 'candidate_problems' => $candidateProblems]);
            foreach ($pending as $index => $item) {
                $analysis = $analyses['image_results'][$index] ?? ['subject' => null, 'unit' => null, 'material' => null, 'page' => null, 'kind' => 'other', 'problems' => [], 'notebook_summary' => null];
                $rotation = (int)($analysis['text_rotation'] ?? 0);
                $confidence = (float)($analysis['rotation_confidence'] ?? 0);
                if (in_array($rotation, [90, 180, 270], true) && $confidence >= 0.85) {
                    $storedPath = dirname(__DIR__) . '/storage/uploads/' . $item['key'];
                    $dimensions = learning_photo_apply_text_rotation($storedPath, $rotation);
                    if ($dimensions) $pdo->prepare('UPDATE learning_photos SET width = ?, height = ?, byte_size = ? WHERE id = ? AND student_id = ?')->execute([$dimensions['width'], $dimensions['height'], $dimensions['bytes'], $item['id'], $studentId]);
                }
                $items[array_search($item['id'], array_column($items, 'id'), true)]['analysis'] = $analysis;
                $pdo->prepare("UPDATE learning_photos SET analysis_status = 'completed', analysis_provider = 'openai', analysis_model = ?, analysis_version = 'phase2-photo-v1', analysis_result = ?, analyzed_at = CURRENT_TIMESTAMP WHERE id = ? AND student_id = ?")
                    ->execute([$config['ai_model'], json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $item['id'], $studentId]);
            }
        } catch (Throwable $e) {
            foreach ($pending as $item) $pdo->prepare("UPDATE learning_photos SET analysis_status = 'failed', analysis_error = ? WHERE id = ? AND student_id = ?")->execute([mb_substr($e->getMessage(), 0, 900), $item['id'], $studentId]);
            throw $e;
        }
    }
    $savedPhotoIds = [];
    $recordIds = [];
    $problemSummary = [];
    foreach ($items as $position => $item) {
        $a = is_array($item['analysis']) ? $item['analysis'] : [];
        $photoId = (int)$item['id'];
        $savedPhotoIds[] = $photoId;
        $subj = trim((string)($a['subject'] ?? $subject ?? ''));
        $unit = trim((string)($a['unit'] ?? ''));
        if ($subj === '' && $subject) $subj = $subject;
        $unitId = null;
        if ($subj !== '' && $unit !== '') {
            $unitKey = hash('sha256', mb_strtolower($subj) . "\0" . mb_strtolower($unit));
            $stmt = $pdo->prepare('INSERT INTO learning_units (student_id, subject, unit_name, unit_key) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
            $stmt->execute([$studentId, $subj, $unit, $unitKey]);
            $unitId = (int)$pdo->lastInsertId();
        }
        $normalize = static fn(string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
        $recordKey = hash('sha256', implode("\0", [(string)$sessionId, $normalize($subj), $normalize($unit), $activity]));
        $summary = trim((string)($a['notebook_summary'] ?? ''));
        if ($summary === '' && !empty($a['problems'])) $summary = '写真から問題・解答を記録';
        $stmt = $pdo->prepare("INSERT INTO learning_records (student_id, learning_date, learning_unit_id, subject, unit_name, activity_type, summary, source_session_id, idempotency_key, extraction_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'phase2-photo-v1') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), learning_unit_id = COALESCE(VALUES(learning_unit_id), learning_unit_id), subject = COALESCE(VALUES(subject), subject), unit_name = COALESCE(VALUES(unit_name), unit_name), summary = COALESCE(NULLIF(VALUES(summary), ''), summary)");
        $stmt->execute([$studentId, $date, $unitId, $subj ?: null, $unit ?: null, $activity, $summary ?: null, $sessionId, $recordKey]);
        $recordId = (int)$pdo->lastInsertId();
        $recordIds[] = $recordId;
        $pdo->prepare("INSERT INTO learning_record_photos (learning_record_id, photo_id, relation_status, relation_reason, confidence, position) VALUES (?, ?, 'confirmed', '同じ送信で登録した写真', 1, ?) ON DUPLICATE KEY UPDATE position = VALUES(position)")->execute([$recordId, $photoId, $position + 1]);
        foreach (array_slice($a['problems'] ?? [], 0, 100, true) as $problemIndex => $problem) {
            if (!is_array($problem)) continue;
            $number = trim((string)($problem['number'] ?? ''));
            $text = trim((string)($problem['text'] ?? ''));
            $answer = trim((string)($problem['answer'] ?? ''));
            $mark = in_array($problem['mark'] ?? '', ['correct','partially_correct','incorrect'], true) ? $problem['mark'] : 'ungraded';
            if ($number === '' && $text === '' && $answer === '') continue;
            // Re-uploaded/overlapping images update the same record item instead of multiplying it.
            $sourceItemKey = hash('sha256', $photoId . "\0" . (string)$problemIndex);
            $stmt = $pdo->prepare('SELECT id FROM learning_problems WHERE source_item_key = ? LIMIT 1');
            $stmt->execute([$sourceItemKey]);
            $problemId = (int)($stmt->fetchColumn() ?: 0);
            if (!$problemId && $number !== '') {
                $stmt = $pdo->prepare("SELECT id FROM learning_problems WHERE learning_record_id = ? AND COALESCE(problem_number, '') = ? AND COALESCE(page_number, '') = ? LIMIT 1");
                $stmt->execute([$recordId, $number, (string)($a['page'] ?? '')]);
                $problemId = (int)($stmt->fetchColumn() ?: 0);
            }
            if ($problemId) {
                $pdo->prepare("UPDATE learning_problems SET problem_text = COALESCE(NULLIF(?, ''), problem_text), student_answer = COALESCE(NULLIF(?, ''), student_answer), answer_result = IF(? = 'ungraded', answer_result, ?), result_source = IF(? = 'ungraded', result_source, 'unknown'), analysis_confidence = ? WHERE id = ?")->execute([$text, $answer, $mark, $mark, $mark, $problem['confidence'] ?? null, $problemId]);
            } else {
                $pdo->prepare("INSERT INTO learning_problems (source_item_key, learning_record_id, material_name, page_number, problem_number, problem_text, student_answer, answer_result, result_source, analysis_confidence) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unknown', ?)")
                    ->execute([$sourceItemKey, $recordId, $a['material'] ?? null, $a['page'] ?? null, $number ?: null, $text ?: null, $answer ?: null, $mark, $problem['confidence'] ?? null]);
                $problemId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('INSERT IGNORE INTO learning_problem_photos (problem_id, photo_id) VALUES (?, ?)')->execute([$problemId, $photoId]);
            // Link a later notebook to earlier workbook questions only when strong identifiers agree.
            if (($a['kind'] ?? '') === 'notebook' && $mark !== 'ungraded') {
                $candidates = $pdo->prepare("SELECT p.id, p.learning_record_id, p.problem_number, p.problem_text, p.page_number, p.material_name, pp.photo_id FROM learning_problems p JOIN learning_records r ON r.id = p.learning_record_id LEFT JOIN learning_problem_photos pp ON pp.problem_id = p.id WHERE r.student_id = ? AND p.id <> ? AND p.answer_result = 'ungraded' AND r.learning_date >= DATE_SUB(?, INTERVAL 30 DAY) ORDER BY r.learning_date DESC LIMIT 300");
                $candidates->execute([$studentId, $problemId, $date]);
                $matches = [];
                $needleNumber = mb_strtolower(preg_replace('/[\s　]/u', '', $number) ?? $number);
                $needleText = mb_strtolower(preg_replace('/[\s　、。，．]/u', '', $text) ?? $text);
                foreach ($candidates->fetchAll() as $candidate) {
                    $candidateNumber = mb_strtolower(preg_replace('/[\s　]/u', '', (string)$candidate['problem_number']) ?? (string)$candidate['problem_number']);
                    $candidateText = mb_strtolower(preg_replace('/[\s　、。，．]/u', '', (string)$candidate['problem_text']) ?? (string)$candidate['problem_text']);
                    $sameNumber = $needleNumber !== '' && $candidateNumber !== '' && $needleNumber === $candidateNumber;
                    $sameText = $needleText !== '' && $candidateText !== '' && ($needleText === $candidateText || (mb_strlen($needleText) >= 12 && (str_contains($needleText, $candidateText) || str_contains($candidateText, $needleText))));
                    $sameMaterial = trim((string)($candidate['material_name'] ?? '')) !== '' && trim((string)($candidate['material_name'] ?? '')) === trim((string)($a['material'] ?? ''));
                    $samePage = trim((string)($candidate['page_number'] ?? '')) !== '' && trim((string)$candidate['page_number']) === trim((string)($a['page'] ?? ''));
                    if ($sameText || ($sameNumber && ($samePage || $sameMaterial))) $matches[] = $candidate;
                }
                $suggestedId = filter_var($problem['matched_prior_problem_id'] ?? null, FILTER_VALIDATE_INT);
                $suggested = $suggestedId ? array_values(array_filter($matches, static fn($candidate) => (int)$candidate['id'] === $suggestedId)) : [];
                if (count($suggested) === 1 || count($matches) === 1) {
                    $target = $suggested[0] ?? $matches[0];
                    $pdo->prepare("UPDATE learning_problems SET student_answer = COALESCE(NULLIF(?, ''), student_answer), answer_result = ?, result_source = 'unknown', analysis_confidence = ? WHERE id = ? AND answer_result = 'ungraded'")->execute([$answer, $mark, $problem['confidence'] ?? null, (int)$target['id']]);
                    $pdo->prepare('INSERT IGNORE INTO learning_problem_photos (problem_id, photo_id) VALUES (?, ?)')->execute([(int)$target['id'], $photoId]);
                    $pdo->prepare("INSERT INTO learning_record_photos (learning_record_id, photo_id, relation_status, relation_reason, confidence, position) VALUES (?, ?, 'confirmed', '問題番号とページ・教材または問題文が一致', 0.9500, 1) ON DUPLICATE KEY UPDATE relation_status = 'confirmed', relation_reason = VALUES(relation_reason), confidence = VALUES(confidence)")->execute([(int)$target['learning_record_id'], $photoId]);
                    if ($target['photo_id']) $pdo->prepare("UPDATE learning_record_photos SET relation_status = 'confirmed', relation_reason = '問題番号・ページ・教材が一致', confidence = 0.9500 WHERE photo_id = ?")->execute([(int)$target['photo_id']]);
                }
            }
            if ($number !== '' || $mark !== 'ungraded' || $text !== '') $problemSummary[] = mb_substr(($subj ? $subj . ' ' : '') . ($number ? $number . '番' : '') . ($text ? '「' . $text . '」' : '') . match ($mark) {'correct' => '○', 'partially_correct' => '△', 'incorrect' => '×', default => '（正誤未判定）'}, 0, 180);
        }
    }
    $problemSummary = array_slice($problemSummary, 0, 12);
    return ['photos' => $savedPhotoIds, 'records' => array_values(array_unique($recordIds)), 'summary' => $problemSummary ? implode('、', $problemSummary) . (count($problemSummary) >= 12 ? ' ほか' : '') . '。写真の問題番号・ページ・問題文で確実に一致する過去の問題だけ更新しました。曖昧な正誤は未判定にしています。' : '写真を保存しました。問題の正誤は読み取れた印だけ記録し、曖昧なものは未判定にしています。'];
}
