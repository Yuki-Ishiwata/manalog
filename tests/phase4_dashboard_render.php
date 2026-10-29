<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

function phase4_render_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$view = (string)($argv[1] ?? 'teacher');
if (!in_array($view, ['admin', 'teacher', 'parent'], true)) throw new InvalidArgumentException('View must be admin, teacher, or parent.');
$pdo = db();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $stmt = $pdo->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)');
    $stmt->execute(['Phase 4 rendering test', 'phase4-render-' . $suffix]);
    $tenantB = (int)$pdo->lastInsertId();
    $insertUser = $pdo->prepare('INSERT INTO users (organization_id, login_id, password_hash, role, admin_scope) VALUES (?, ?, ?, ?, ?)');
    $makeUser = static function (int $org, string $role, ?string $scope = null) use ($insertUser, $suffix, $pdo): int {
        static $n = 0;
        $insertUser->execute([$org, 'phase4_render_' . $suffix . '_' . (++$n), password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $role, $scope]);
        return (int)$pdo->lastInsertId();
    };
    $adminId = $makeUser(1, 'admin', 'tenant');
    $teacherId = $makeUser(1, 'teacher');
    $parentId = $makeUser(1, 'parent');
    $studentA = $makeUser(1, 'student');
    $studentB = $makeUser($tenantB, 'student');
    $pdo->prepare('INSERT INTO student_profiles (user_id, display_name) VALUES (?, ?)')->execute([$studentA, 'Phase4 visible student']);
    $pdo->prepare('INSERT INTO student_profiles (user_id, display_name) VALUES (?, ?)')->execute([$studentB, 'Phase4 hidden student']);
    $pdo->prepare('INSERT INTO parent_student_links (parent_user_id, student_user_id) VALUES (?, ?)')->execute([$parentId, $studentA]);
    $pdo->prepare('INSERT INTO parent_student_links (parent_user_id, student_user_id) VALUES (?, ?)')->execute([$parentId, $studentB]);

    session_save_path('/private/tmp');
    session_name('manalog_session');
    session_id('phase4' . $suffix);
    session_start();
    $actorId = ['admin' => $adminId, 'teacher' => $teacherId, 'parent' => $parentId][$view];
    $_SESSION = ['user_id' => $actorId, 'role' => $view, 'organization_id' => 1];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $view === 'parent' ? ['child_id' => (string)$studentA] : [];
    $_POST = [];
    ob_start();
    require dirname(__DIR__) . '/public/index.php';
    $html = (string)ob_get_clean();
    phase4_render_assert(str_contains($html, 'Phase4 visible student'), 'The same-organization student was missing from the dashboard.');
    phase4_render_assert(!str_contains($html, 'Phase4 hidden student'), 'A different-organization student leaked into the dashboard.');
    phase4_render_assert(!str_contains($html, '関連する会話（'), 'The staff dashboard should not expose full chat transcript controls.');
    session_destroy();
    echo 'Phase 4 ' . $view . ' dashboard rendering checks passed.' . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
