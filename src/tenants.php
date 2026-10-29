<?php
declare(strict_types=1);

function tenant_staff_student_access(PDO $pdo, int $actorId, int $studentId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM users a JOIN organizations o ON o.id = a.organization_id JOIN users s ON s.organization_id = a.organization_id WHERE a.id = ? AND a.role IN ('admin','teacher') AND a.status = 'active' AND o.status = 'active' AND (a.role = 'teacher' OR a.admin_scope = 'tenant') AND s.id = ? AND s.role = 'student' AND s.status = 'active' LIMIT 1");
    $stmt->execute([$actorId, $studentId]);
    return (bool)$stmt->fetchColumn();
}

function tenant_parent_student_access(PDO $pdo, int $parentId, int $studentId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM parent_student_links l JOIN users p ON p.id = l.parent_user_id JOIN users s ON s.id = l.student_user_id JOIN organizations o ON o.id = p.organization_id WHERE p.id = ? AND p.role = 'parent' AND p.status = 'active' AND s.id = ? AND s.role = 'student' AND s.status = 'active' AND p.organization_id = s.organization_id AND o.status = 'active' LIMIT 1");
    $stmt->execute([$parentId, $studentId]);
    return (bool)$stmt->fetchColumn();
}

function tenant_admin_manages_user(PDO $pdo, int $adminId, int $userId, array $allowedRoles): bool {
    $roles = array_values(array_intersect($allowedRoles, ['student', 'teacher', 'parent', 'admin']));
    if (!$roles) return false;
    $marks = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $pdo->prepare("SELECT 1 FROM users a JOIN organizations o ON o.id = a.organization_id JOIN users target ON target.organization_id = a.organization_id WHERE a.id = ? AND a.role = 'admin' AND a.admin_scope = 'tenant' AND a.status = 'active' AND o.status = 'active' AND target.id = ? AND target.role IN ($marks) LIMIT 1");
    $stmt->execute([$adminId, $userId, ...$roles]);
    return (bool)$stmt->fetchColumn();
}
