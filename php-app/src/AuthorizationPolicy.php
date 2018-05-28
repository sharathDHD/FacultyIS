<?php
// Version: v2.12


/**
 * AuthorizationPolicy — Centralized write-side authorization.
 *
 * Every mutation (create, update, delete, role change) must go through
 * this policy. This prevents authorization logic from being scattered
 * across individual page handlers, which has historically been the
 * source of cross-department privilege escalation bugs.
 *
 * Fundamental rule: "When updating an object by ID, always authorize
 * both the existing object AND the requested new state."
 *
 * Usage:
 *   $policy = AuthorizationPolicy::forUser($user, $pdo);
 *
 *   // Write-side checks
 *   $policy->canCreateFaculty($departmentId);
 *   $policy->canEditFaculty($facultyId, $newDepartmentId);
 *   $policy->canDeleteFaculty($facultyId);
 *   $policy->canManageRole($targetUserId, $newRole);
 *
 *   $policy->canCreateCourse($departmentId);
 *   $policy->canEditCourse($courseId, $newDepartmentId);
 *   $policy->canDeleteCourse($courseId);
 *
 *   $policy->canCreateSection($courseId, $facultyId);
 *   $policy->canEditSection($sectionId, $newCourseId, $newFacultyId);
 *   $policy->canDeleteSection($sectionId);
 *
 *   $policy->canRecordAttendance($courseSectionId);
 *   $policy->canDeleteAttendance($attendanceId);
 *
 *   $policy->canCreateTimetable($departmentId);
 *   $policy->canEditTimetable($entryId, $newDepartmentId);
 *   $policy->canDeleteTimetable($entryId);
 *
 *   $policy->canUploadDocument($departmentId);
 *   $policy->canDeleteDocument($documentId);
 *
 * Each method either returns true or throws a 403 Forbidden.
 *
 * v2.15: Added non-throwing check methods (isAllowedXxx) for template
 * conditionals, eliminating the need for inline role/department checks
 * in view code. The throwing canXxx() methods remain for enforcement.
 */
class AuthorizationPolicy
{
    private array $user;
    private PDO $pdo;
    private bool $isAdmin;
    private bool $isHod;
    private int $departmentId;

    private function __construct(array $user, PDO $pdo)
    {
        $this->user = $user;
        $this->pdo = $pdo;
        $this->isAdmin = ($user['role'] ?? '') === 'admin';
        $this->isHod = ($user['role'] ?? '') === 'hod';
        $this->departmentId = (int) ($user['department_id'] ?? 0);
    }

    public static function forUser(array $user, PDO $pdo): self
    {
        return new self($user, $pdo);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function deny(string $msg): void
    {
        http_response_code(403);
        // Render a minimal but navigable error page instead of bare die().
        // This gives the user a way back to the application.
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>403 Forbidden</title>
<style>
body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5}
.card{background:#fff;border-radius:8px;padding:2rem 2.5rem;box-shadow:0 1px 3px rgba(0,0,0,.12);max-width:420px;text-align:center}
h1{color:#dc2626;margin:0 0 .5rem}
p{color:#6b7280;margin:0 0 1.5rem}
a{color:#2563eb;text-decoration:none}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="card">
<h1>403 Forbidden</h1>
<p>HTML;
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
</p>
<a href="/dashboard.php">&larr; Back to Dashboard</a>
</div>
</body>
</html>
HTML;
        exit;
    }

    /**
     * Non-throwing permission check — returns true/false.
     * Used in templates to conditionally show/hide UI elements.
     * For enforcement, use the throwing canXxx() methods instead.
     */
    public function isAllowedToDeleteDocument(int $documentId): bool
    {
        $stmt = $this->pdo->prepare('SELECT user_id, department_id FROM documents WHERE id = :id');
        $stmt->execute(['id' => $documentId]);
        $doc = $stmt->fetch();

        if (!$doc) return false;
        if ($this->isAdmin) return true;
        if ((int) $doc['user_id'] === (int) $this->user['id']) return true;
        if ($this->isHod && $this->isOwnDepartment((int) $doc['department_id'])) return true;
        return false;
    }

    private function isOwnDepartment(int $deptId): bool
    {
        return $deptId === $this->departmentId;
    }

    // ── Faculty ───────────────────────────────────────────────

    public function canCreateFaculty(int $departmentId): void
    {
        if ($this->isAdmin) return;
        if ($this->isHod && $this->isOwnDepartment($departmentId)) return;
        $this->deny('You can only create faculty in your own department.');
    }

    public function canEditFaculty(int $facultyId, int $newDepartmentId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Faculty cannot edit other faculty.');

        // Authorize existing object
        $stmt = $this->pdo->prepare('SELECT department_id FROM users WHERE id = :id AND role = \'faculty\'');
        $stmt->execute(['id' => $facultyId]);
        $existingDeptId = $stmt->fetchColumn();
        if ($existingDeptId === false || !$this->isOwnDepartment((int) $existingDeptId)) {
            $this->deny('You can only modify faculty in your own department.');
        }

        // Authorize requested new state
        if (!$this->isOwnDepartment($newDepartmentId)) {
            $this->deny('You can only assign faculty to your own department.');
        }
    }

    public function canDeleteFaculty(int $facultyId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can delete faculty.');

        $stmt = $this->pdo->prepare('SELECT department_id FROM users WHERE id = :id AND role = \'faculty\'');
        $stmt->execute(['id' => $facultyId]);
        $deptId = $stmt->fetchColumn();
        if ($deptId !== false && !$this->isOwnDepartment((int) $deptId)) {
            $this->deny('You can only delete faculty in your own department.');
        }
    }

    public function canManageRole(int $targetUserId, string $newRole): void
    {
        // Role changes are ADMIN-ONLY — prevents HOD → admin privilege escalation
        if (!$this->isAdmin) {
            $this->deny('Only administrators can change user roles.');
        }

        // Prevent demoting the last admin
        $stmt = $this->pdo->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $targetUserId]);
        $oldRole = $stmt->fetchColumn();
        if ($oldRole === 'admin' && $newRole !== 'admin') {
            $adminCount = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                $this->deny('Cannot demote the last administrator.');
            }
        }
    }

    // ── Courses ───────────────────────────────────────────────

    public function canCreateCourse(int $departmentId): void
    {
        if ($this->isAdmin) return;
        if ($this->isHod && $this->isOwnDepartment($departmentId)) return;
        $this->deny('You can only create courses in your own department.');
    }

    public function canEditCourse(int $courseId, int $newDepartmentId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can edit courses.');

        // Authorize existing object
        $stmt = $this->pdo->prepare('SELECT department_id FROM courses WHERE id = :id');
        $stmt->execute(['id' => $courseId]);
        $existingDeptId = $stmt->fetchColumn();
        if ($existingDeptId === false || !$this->isOwnDepartment((int) $existingDeptId)) {
            $this->deny('You can only modify courses in your own department.');
        }

        // Authorize requested new state
        if (!$this->isOwnDepartment($newDepartmentId)) {
            $this->deny('You can only assign courses to your own department.');
        }
    }

    public function canDeleteCourse(): void
    {
        // Course deletion is ADMIN-ONLY
        if (!$this->isAdmin) {
            $this->deny('Only administrators can delete courses.');
        }
    }

    // ── Sections ──────────────────────────────────────────────

    public function canCreateSection(int $courseId, int $facultyId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can create sections.');

        // Verify course belongs to own department
        $stmt = $this->pdo->prepare('SELECT department_id FROM courses WHERE id = :id');
        $stmt->execute(['id' => $courseId]);
        $deptId = $stmt->fetchColumn();
        if ($deptId === false || !$this->isOwnDepartment((int) $deptId)) {
            $this->deny('You can only manage sections in your own department.');
        }

        // Verify faculty exists and belongs to same department as course
        $facStmt = $this->pdo->prepare('SELECT department_id FROM users WHERE id = :id AND role = \'faculty\'');
        $facStmt->execute(['id' => $facultyId]);
        $facDeptId = $facStmt->fetchColumn();
        if ($facDeptId === false) {
            $this->deny('The selected faculty member does not exist.');
        }
        if ((int) $facDeptId !== (int) $deptId) {
            $this->deny('Faculty must belong to the same department as the course.');
        }
    }

    public function canEditSection(int $sectionId, int $newCourseId, int $newFacultyId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can edit sections.');

        // Authorize existing object
        $stmt = $this->pdo->prepare(
            'SELECT c.department_id FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id'
        );
        $stmt->execute(['id' => $sectionId]);
        $existingDeptId = $stmt->fetchColumn();
        if ($existingDeptId === false || !$this->isOwnDepartment((int) $existingDeptId)) {
            $this->deny('You can only modify sections in your own department.');
        }

        // Authorize requested new course
        $courseStmt = $this->pdo->prepare('SELECT department_id FROM courses WHERE id = :id');
        $courseStmt->execute(['id' => $newCourseId]);
        $newCourseDeptId = $courseStmt->fetchColumn();
        if ($newCourseDeptId === false || !$this->isOwnDepartment((int) $newCourseDeptId)) {
            $this->deny('You can only assign sections to courses in your own department.');
        }

        // Verify faculty exists and belongs to same department as new course
        $facStmt = $this->pdo->prepare('SELECT department_id FROM users WHERE id = :id AND role = \'faculty\'');
        $facStmt->execute(['id' => $newFacultyId]);
        $facDeptId = $facStmt->fetchColumn();
        if ($facDeptId === false) {
            $this->deny('The selected faculty member does not exist.');
        }
        if ((int) $facDeptId !== (int) $newCourseDeptId) {
            $this->deny('Faculty must belong to the same department as the course.');
        }
    }

    public function canDeleteSection(int $sectionId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can delete sections.');

        $stmt = $this->pdo->prepare(
            'SELECT c.department_id FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :id'
        );
        $stmt->execute(['id' => $sectionId]);
        $deptId = $stmt->fetchColumn();
        if ($deptId !== false && !$this->isOwnDepartment((int) $deptId)) {
            $this->deny('You can only delete sections in your own department.');
        }
    }

    // ── Attendance ────────────────────────────────────────────

    public function canRecordAttendance(int $courseSectionId): void
    {
        if ($this->isAdmin) return;

        if (($this->user['role'] ?? '') === 'faculty') {
            // Faculty can only record for their own sections
            $stmt = $this->pdo->prepare(
                'SELECT id FROM course_sections WHERE id = :section_id AND faculty_id = :faculty_id'
            );
            $stmt->execute(['section_id' => $courseSectionId, 'faculty_id' => $this->user['id']]);
            if (!$stmt->fetch()) {
                $this->deny('You can only record attendance for your own course sections.');
            }
            return;
        }

        if ($this->isHod) {
            $stmt = $this->pdo->prepare(
                'SELECT cs.id FROM course_sections cs
                 JOIN courses c ON c.id = cs.course_id
                 WHERE cs.id = :section_id AND c.department_id = :dept_id'
            );
            $stmt->execute(['section_id' => $courseSectionId, 'dept_id' => $this->departmentId]);
            if (!$stmt->fetch()) {
                $this->deny('You can only record attendance for your own department.');
            }
            return;
        }

        $this->deny('You are not authorized to record attendance.');
    }

    public function canDeleteAttendance(int $attendanceId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can delete attendance.');

        $stmt = $this->pdo->prepare(
            'SELECT c.department_id FROM attendance a
             JOIN course_sections cs ON cs.id = a.course_section_id
             JOIN courses c ON c.id = cs.course_id
             WHERE a.id = :id'
        );
        $stmt->execute(['id' => $attendanceId]);
        $deptId = $stmt->fetchColumn();
        if ($deptId !== false && !$this->isOwnDepartment((int) $deptId)) {
            $this->deny('You can only delete attendance in your own department.');
        }
    }

    // ── Timetable ─────────────────────────────────────────────

    public function canCreateTimetable(int $departmentId): void
    {
        if ($this->isAdmin) return;
        if ($this->isHod && $this->isOwnDepartment($departmentId)) return;
        $this->deny('You can only manage timetable entries in your own department.');
    }

    public function canEditTimetable(int $entryId, int $newDepartmentId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can edit timetable entries.');

        // Authorize existing object
        $stmt = $this->pdo->prepare('SELECT department_id FROM timetable_entries WHERE id = :id');
        $stmt->execute(['id' => $entryId]);
        $existingDeptId = $stmt->fetchColumn();
        if ($existingDeptId === false || !$this->isOwnDepartment((int) $existingDeptId)) {
            $this->deny('You can only manage timetable entries in your own department.');
        }

        // Authorize requested new state
        if (!$this->isOwnDepartment($newDepartmentId)) {
            $this->deny('You can only assign timetable entries to your own department.');
        }
    }

    public function canDeleteTimetable(int $entryId): void
    {
        if ($this->isAdmin) return;
        if (!$this->isHod) $this->deny('Only HOD or admin can delete timetable entries.');

        $stmt = $this->pdo->prepare('SELECT department_id FROM timetable_entries WHERE id = :id');
        $stmt->execute(['id' => $entryId]);
        $deptId = $stmt->fetchColumn();
        if ($deptId !== false && !$this->isOwnDepartment((int) $deptId)) {
            $this->deny('You can only delete timetable entries in your own department.');
        }
    }

    // ── Documents ─────────────────────────────────────────────

    public function canUploadDocument(int $departmentId): void
    {
        if ($this->isAdmin) return;
        // Faculty/HOD can only upload to their own department
        if (!$this->isOwnDepartment($departmentId)) {
            $this->deny('You can only upload documents to your own department.');
        }
    }

    /**
     * Delete authorization: explicit 3-branch policy.
     *   admin      → can delete any document
     *   hod        → can delete documents in own department only
     *   faculty    → can delete own documents only (uploader check)
     */
    public function canDeleteDocument(int $documentId): void
    {
        $stmt = $this->pdo->prepare('SELECT user_id, department_id FROM documents WHERE id = :id');
        $stmt->execute(['id' => $documentId]);
        $doc = $stmt->fetch();

        if (!$doc) {
            // Document doesn't exist — silently deny (no information leak)
            $this->deny('Document not found.');
        }

        if ($this->isAdmin) return;
        if ((int) $doc['user_id'] === (int) $this->user['id']) return; // own upload
        if ($this->isHod && $this->isOwnDepartment((int) $doc['department_id'])) return;

        $this->deny('You can only delete documents in your own department.');
    }

    // ── Departments ───────────────────────────────────────────

    public function canManageDepartment(): void
    {
        if (!$this->isAdmin) {
            $this->deny('Only administrators can manage departments.');
        }
    }

    // ── Audit Logs ────────────────────────────────────────────

    public function canViewAuditLogs(): void
    {
        if (($this->user['role'] ?? '') === 'faculty') {
            $this->deny('Faculty cannot view audit logs.');
        }
    }

    public function canPurgeAuditLogs(): void
    {
        if (!$this->isAdmin) {
            $this->deny('Only administrators can purge audit logs.');
        }
    }
}
