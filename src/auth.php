<?php
declare(strict_types=1);

function auth_provider_id(array $config): string {
    $provider = trim((string)($config['school_provider_id'] ?? ''));
    return $provider !== '' ? $provider : 'ishiwata_juku';
}

function auth_attempt_hash(string $value, array $config): string {
    $key = (string)($config['app_key'] ?? '');
    if (strlen($key) < 32 || str_contains(strtolower($key), 'replace-with')) {
        throw new RuntimeException('APP_KEYに32文字以上のランダムな値を設定してください。');
    }
    return hash_hmac('sha256', mb_strtolower(trim($value), 'UTF-8'), $key);
}

function auth_attempt_is_limited(PDO $pdo, string $loginId, array $config): bool {
    $loginHash = auth_attempt_hash($loginId, $config);
    $ipHash = auth_attempt_hash((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $config);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE login_hash = ? AND success = 0 AND created_at >= NOW() - INTERVAL 15 MINUTE');
    $stmt->execute([$loginHash]);
    if ((int)$stmt->fetchColumn() >= 5) return true;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM auth_login_attempts WHERE ip_hash = ? AND success = 0 AND created_at >= NOW() - INTERVAL 15 MINUTE');
    $stmt->execute([$ipHash]);
    return (int)$stmt->fetchColumn() >= 20;
}

function auth_record_attempt(PDO $pdo, string $loginId, array $config, bool $success): void {
    $loginHash = auth_attempt_hash($loginId, $config);
    $ipHash = auth_attempt_hash((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $config);
    $stmt = $pdo->prepare('INSERT INTO auth_login_attempts (login_hash, ip_hash, success) VALUES (?, ?, ?)');
    $stmt->execute([$loginHash, $ipHash, $success ? 1 : 0]);
    if ((int)$pdo->lastInsertId() % 100 === 0) {
        $pdo->exec('DELETE FROM auth_login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
    }
}

function auth_school_student_by_login(string $loginId): ?array {
    $school = school_db();
    $stmt = $school->prepare('SELECT id, login_id, simei, password, delete_flg FROM kaiin WHERE login_id = ? LIMIT 1');
    $stmt->execute([$loginId]);
    $student = $stmt->fetch();
    return $student ?: null;
}

function auth_school_student_by_id(int $schoolStudentId, bool $includePassword = true): ?array {
    $school = school_db();
    $fields = $includePassword ? 'id, login_id, simei, password, delete_flg' : 'id, login_id, simei, delete_flg';
    $stmt = $school->prepare('SELECT ' . $fields . ' FROM kaiin WHERE id = ? LIMIT 1');
    $stmt->execute([$schoolStudentId]);
    $student = $stmt->fetch();
    return $student ?: null;
}

function auth_school_student_is_active(int $schoolStudentId): bool {
    $school = school_db();
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
    $stmt = $school->prepare("SELECT 1 FROM kaiin_syubetsu ks JOIN syubetsu s ON s.id = ks.syubetsu_id WHERE ks.kaiin_id = ? AND ks.kikan_start <= ? AND (ks.kikan_end >= ? OR ks.kikan_end = '') AND s.system_flg = 1 AND s.zaiseki_flg = 1 AND s.delete_flg = 0 LIMIT 1");
    $stmt->execute([$schoolStudentId, $today, $today]);
    return (bool)$stmt->fetchColumn();
}

function auth_revalidate_school_user(PDO $pdo, int $userId, array $config): bool {
    $stmt = $pdo->prepare("SELECT u.status, l.school_student_id FROM users u JOIN student_school_links l ON l.user_id = u.id WHERE u.id = ? AND u.auth_source = 'school' AND l.school_provider = ? LIMIT 1");
    $stmt->execute([$userId, auth_provider_id($config)]);
    $link = $stmt->fetch();
    if (!$link || $link['status'] !== 'active') return false;
    $student = auth_school_student_by_id((int)$link['school_student_id'], false);
    if (!$student || (int)$student['delete_flg'] !== 0 || !auth_school_student_is_active((int)$student['id'])) return false;
    $pdo->prepare('UPDATE student_school_links SET last_verified_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$userId]);
    return true;
}

function auth_finish_school_login(PDO $pdo, array $config, array $schoolStudent, ?array $linkedUser, bool $forcedSchoolAlias): array {
    $schoolStudentId = (int)$schoolStudent['id'];
    if ((int)$schoolStudent['delete_flg'] !== 0 || !auth_school_student_is_active($schoolStudentId)) {
        throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
    }
    if ($linkedUser) {
        if ($linkedUser['auth_source'] !== 'school' || $linkedUser['role'] !== 'student' || $linkedUser['status'] !== 'active') {
            throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
        }
        return $linkedUser;
    }

    $rawLoginId = (string)$schoolStudent['login_id'];
    $loginId = $forcedSchoolAlias ? 'school:' . $rawLoginId : $rawLoginId;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, auth_source, role, status FROM users WHERE login_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$loginId]);
        $collision = $stmt->fetch();
        if ($collision) {
            if (!$forcedSchoolAlias) {
                throw new RuntimeException('このログインIDは別のアカウントで使われています。塾生はログインIDの前に school: を付けてください。');
            }
            throw new RuntimeException('この塾アカウントは管理者による連携が必要です。');
        }

        $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (login_id, password_hash, role, auth_source) VALUES (?, ?, 'student', 'school')");
        $stmt->execute([$loginId, $placeholderHash]);
        $userId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO student_profiles (user_id, display_name, school_name, grade) VALUES (?, ?, ?, NULL)');
        $stmt->execute([$userId, (string)$schoolStudent['simei'], '石渡塾']);
        $stmt = $pdo->prepare('INSERT INTO notification_preferences (student_id) VALUES (?)');
        $stmt->execute([$userId]);
        $stmt = $pdo->prepare('INSERT INTO student_school_links (user_id, school_provider, school_student_id, last_verified_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)');
        $stmt->execute([$userId, auth_provider_id($config), $schoolStudentId]);
        $pdo->commit();
        return ['id' => $userId, 'role' => 'student', 'auth_source' => 'school', 'status' => 'active'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof RuntimeException) throw $e;
        if ($e instanceof PDOException && $e->getCode() === '23000') {
            throw new RuntimeException('この塾アカウントはすでに連携されています。ログインIDを確認してください。');
        }
        throw $e;
    }
}

function auth_login(PDO $pdo, string $submittedLoginId, string $password, array $config): array {
    $submittedLoginId = trim($submittedLoginId);
    $forcedSchoolAlias = str_starts_with($submittedLoginId, 'school:');
    $schoolLoginId = $forcedSchoolAlias ? substr($submittedLoginId, 7) : $submittedLoginId;
    if ($submittedLoginId === '' || $schoolLoginId === '' || strlen($submittedLoginId) > 120 || $password === '' || strlen($password) > 1024) {
        throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
    }
    try {
        $limited = auth_attempt_is_limited($pdo, $submittedLoginId, $config);
    } catch (RuntimeException $e) {
        error_log('ManaLog auth configuration error: ' . $e->getMessage());
        throw new RuntimeException('ログイン設定を確認してください。');
    }
    if ($limited) {
        throw new RuntimeException('ログイン試行が上限に達しました。15分ほど待ってから再度お試しください。');
    }

    $success = false;
    try {
        $lookupLoginId = $forcedSchoolAlias ? 'school:' . $schoolLoginId : $submittedLoginId;
        $stmt = $pdo->prepare("SELECT id, login_id, password_hash, role, status, auth_source FROM users WHERE login_id = ? LIMIT 1");
        $stmt->execute([$lookupLoginId]);
        $user = $stmt->fetch() ?: null;
        if ($forcedSchoolAlias && $user && $user['auth_source'] === 'local') $user = null;

        if ($user && $user['auth_source'] === 'local') {
            if ($user['status'] !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
                throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
            }
            $success = true;
            return $user;
        }

        if ($user && $user['auth_source'] === 'school') {
            $link = $pdo->prepare('SELECT school_student_id FROM student_school_links WHERE user_id = ? AND school_provider = ? LIMIT 1');
            $link->execute([(int)$user['id'], auth_provider_id($config)]);
            $schoolStudentId = (int)$link->fetchColumn();
            $schoolStudent = $schoolStudentId ? auth_school_student_by_id($schoolStudentId) : null;
            if (!$schoolStudent || !password_verify($password, (string)$schoolStudent['password'])) {
                throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
            }
            if ((int)$schoolStudent['delete_flg'] !== 0) {
                throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
            }
            $verified = auth_finish_school_login($pdo, $config, $schoolStudent, $user, false);
            $pdo->prepare('UPDATE student_school_links SET last_verified_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([(int)$user['id']]);
            $success = true;
            return $verified;
        }

        $schoolStudent = auth_school_student_by_login($schoolLoginId);
        if (!$schoolStudent || !password_verify($password, (string)$schoolStudent['password'])) {
            throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
        }
        $link = $pdo->prepare('SELECT u.id, u.login_id, u.role, u.status, u.auth_source FROM student_school_links l JOIN users u ON u.id = l.user_id WHERE l.school_provider = ? AND l.school_student_id = ? LIMIT 1');
        $link->execute([auth_provider_id($config), (int)$schoolStudent['id']]);
        $linkedUser = $link->fetch() ?: null;
        $verified = auth_finish_school_login($pdo, $config, $schoolStudent, $linkedUser, $forcedSchoolAlias);
        if ($linkedUser) $pdo->prepare('UPDATE student_school_links SET last_verified_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([(int)$linkedUser['id']]);
        $success = true;
        return $verified;
    } catch (PDOException $e) {
        error_log('ManaLog auth database error: ' . $e->getMessage());
        throw new RuntimeException('認証サービスに接続できません。時間をおいて再度お試しください。');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '塾DBの接続設定')) {
            error_log('ManaLog auth: school database settings are missing.');
            throw new RuntimeException('ログインIDまたはパスワードを確認してください。');
        }
        throw $e;
    } finally {
        auth_record_attempt($pdo, $submittedLoginId, $config, $success);
    }
}
