<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/tenants.php';

function phase4_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $stmt = $pdo->prepare("INSERT INTO organizations (name, slug) VALUES (?, ?)");
    $stmt->execute(['Phase 4 test tenant', 'phase4-' . $suffix]);
    $tenantB = (int)$pdo->lastInsertId();
    $insertUser = $pdo->prepare("INSERT INTO users (organization_id, login_id, password_hash, role, admin_scope) VALUES (?, ?, ?, ?, ?)");
    $makeUser = static function (int $org, string $role, ?string $scope = null) use ($insertUser, $suffix, $pdo): int {
        static $n = 0;
        $insertUser->execute([$org, 'phase4_' . $suffix . '_' . (++$n), password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $role, $scope]);
        return (int)$pdo->lastInsertId();
    };
    $tenantA = 1;
    $adminA = $makeUser($tenantA, 'admin', 'tenant');
    $teacherA = $makeUser($tenantA, 'teacher');
    $serviceAdmin = $makeUser($tenantA, 'admin', 'service');
    $parentA = $makeUser($tenantA, 'parent');
    $studentA = $makeUser($tenantA, 'student');
    $teacherB = $makeUser($tenantB, 'teacher');
    $studentB = $makeUser($tenantB, 'student');
    $pdo->prepare('INSERT INTO student_profiles (user_id, display_name) VALUES (?, ?)')->execute([$studentA, 'Phase4 tenant A student']);
    $pdo->prepare('INSERT INTO student_profiles (user_id, display_name) VALUES (?, ?)')->execute([$studentB, 'Phase4 tenant B student']);
    $links = $pdo->prepare('INSERT INTO parent_student_links (parent_user_id, student_user_id) VALUES (?, ?)');
    $links->execute([$parentA, $studentA]);
    $links->execute([$parentA, $studentB]); // Simulate a corrupted/manual cross-tenant link; access must still be denied.

    phase4_assert(tenant_staff_student_access($pdo, $teacherA, $studentA), 'Teacher should see a student in their own organization.');
    phase4_assert(!tenant_staff_student_access($pdo, $teacherA, $studentB), 'Teacher must not see a student in another organization.');
    phase4_assert(tenant_staff_student_access($pdo, $adminA, $studentA), 'Tenant admin should see students in their organization.');
    phase4_assert(!tenant_staff_student_access($pdo, $serviceAdmin, $studentA), 'Service admin must not inherit tenant dashboard access.');
    phase4_assert(!tenant_staff_student_access($pdo, $teacherB, $studentA), 'Other tenant teacher must not see this organization student.');
    phase4_assert(tenant_parent_student_access($pdo, $parentA, $studentA), 'Parent should see a linked child in their organization.');
    phase4_assert(!tenant_parent_student_access($pdo, $parentA, $studentB), 'Parent must not see a cross-organization child, even with a bad link row.');
    phase4_assert(tenant_admin_manages_user($pdo, $adminA, $teacherA, ['teacher']), 'Tenant admin should manage local staff accounts.');
    phase4_assert(!tenant_admin_manages_user($pdo, $adminA, $teacherB, ['teacher']), 'Tenant admin must not manage another organization staff account.');
    echo "Phase 4 tenant dashboard scope checks passed.\n";
} finally {
    $pdo->rollBack();
}
