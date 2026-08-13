<?php

/**
 * DepartmentScope — Centralized read-side department authorization helper.
 *
 * Every PHP listing/dropdown query for non-admin users should use this
 * to enforce the same department boundary consistently. This prevents
 * cross-department information disclosure on read paths.
 *
 * v2.12: Extended with attendance(), documents(), timetable(), dashboardStats()
 * to cover ALL read-side queries. Every page now uses DepartmentScope
 * instead of manual if/else branches.
 *
 * Fundamental rule: "Every read goes through a scope."
 *
 * Usage:
 *   $scope = DepartmentScope::forUser($user, $pdo);
 *   $departments   = $scope->departments();
 *   $faculty       = $scope->facultyList();
 *   $facultyDD     = $scope->facultyDropdown();
 *   $courses       = $scope->courses();
 *   $sections      = $scope->sections();
 *   $attendance    = $scope->attendance($filterSectionId, $filterDate);
 *   $documents     = $scope->documents($filterCat, $filterCourseId);
 *   $timetable     = $scope->timetable($filterDeptId);
 *   $stats         = $scope->dashboardStats();
 */
class DepartmentScope
{
    private PDO $pdo;
    private bool $isAdmin;
    private int $departmentId;

    private function __construct(PDO $pdo, bool $isAdmin, int $departmentId)
    {
        $this->pdo = $pdo;
        $this->isAdmin = $isAdmin;
        $this->departmentId = $departmentId;
    }

    /**
     * Create a scope instance for the given user.
     */
    public static function forUser(array $user, PDO $pdo): self
    {
        $isAdmin = ($user['role'] ?? '') === 'admin';
        $deptId = (int) ($user['department_id'] ?? 0);
        return new self($pdo, $isAdmin, $deptId);
    }

    /** Whether the current user is an admin (sees everything). */
    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    /** The current user's department_id (0 if admin with no department). */
    public function departmentId(): int
    {
        return $this->departmentId;
    }

    /**
     * Derive the effective department_id for a request.
     *
     * Admin can access any department (returns the requested ID, or their own).
     * Non-admin users are always scoped to their own department (ignores requested ID).
     *
     * This eliminates the duplicated pattern:
     *   if ($user['role'] === 'hod') {
     *       $departmentId = $user['department_id'];
     *   }
     * that appears in reports.php, export.php, etc.
     *
     * @param int|null $requestedDeptId  The department the request is asking for.
     * @return int|null  The effective department_id (null = all/unscoped for admin).
     */
    public function effectiveDepartmentId(?int $requestedDeptId = null): ?int
    {
        if ($this->isAdmin) {
            return $requestedDeptId;
        }
        return $this->departmentId > 0 ? $this->departmentId : null;
    }

    /**
     * Departments visible to this user.
     * Admin: all departments. Others: own department only.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function departments(): array
    {
        if ($this->isAdmin) {
            return $this->pdo->query(
                'SELECT id, name FROM departments ORDER BY name'
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM departments WHERE id = :dept ORDER BY name'
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Faculty records visible to this user.
     * Admin: all faculty. Others: own department's faculty only.
     *
     * @return array
     */
    public function facultyList(): array
    {
        if ($this->isAdmin) {
            return $this->pdo->query(
                "SELECT u.*, d.name AS department_name FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.role = 'faculty' ORDER BY u.name"
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            "SELECT u.*, d.name AS department_name FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.role = 'faculty' AND u.department_id = :dept
             ORDER BY u.name"
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Faculty list for dropdowns (id, name, user_id only).
     * Admin: all faculty. Others: own department's faculty only.
     *
     * @return array
     */
    public function facultyDropdown(): array
    {
        if ($this->isAdmin) {
            return $this->pdo->query(
                "SELECT u.id, u.name, u.user_id, d.name AS department_name FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.role = 'faculty' ORDER BY u.name"
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.name, u.user_id, d.name AS department_name FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.role = 'faculty' AND u.department_id = :dept
             ORDER BY u.name"
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Courses visible to this user.
     * Admin: all courses. Others: own department's courses only.
     *
     * @return array
     */
    public function courses(): array
    {
        if ($this->isAdmin) {
            return $this->pdo->query(
                'SELECT c.*, d.name AS department_name FROM courses c
                 JOIN departments d ON d.id = c.department_id
                 ORDER BY d.name, c.code'
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.*, d.name AS department_name FROM courses c
             JOIN departments d ON d.id = c.department_id
             WHERE c.department_id = :dept
             ORDER BY d.name, c.code'
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Courses for dropdowns (id, code, name only).
     * Admin: all courses. Others: own department's courses only.
     *
     * @return array
     */
    public function coursesDropdown(): array
    {
        if ($this->isAdmin) {
            return $this->pdo->query(
                'SELECT id, code, name FROM courses ORDER BY code'
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name FROM courses WHERE department_id = :dept ORDER BY code'
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Course sections visible to this user.
     * Admin: all sections. Others: own department's sections only.
     *
     * @return array
     */
    public function sections(): array
    {
        if ($this->isAdmin) {
            return $this->pdo->query(
                "SELECT cs.*, c.code AS course_code, c.name AS course_name,
                        u.name AS faculty_name, u.user_id AS faculty_user_id
                 FROM course_sections cs
                 JOIN courses c ON c.id = cs.course_id
                 JOIN users u ON u.id = cs.faculty_id
                 ORDER BY c.code, cs.year, cs.semester, cs.section_number"
            )->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            "SELECT cs.*, c.code AS course_code, c.name AS course_name,
                    u.name AS faculty_name, u.user_id AS faculty_user_id
             FROM course_sections cs
             JOIN courses c ON c.id = cs.course_id
             JOIN users u ON u.id = cs.faculty_id
             WHERE c.department_id = :dept
             ORDER BY c.code, cs.year, cs.semester, cs.section_number"
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Sections for attendance dropdown (includes faculty name for HOD/admin).
     * Faculty: only own sections. Others: department-scoped.
     *
     * @param string $role  Current user's role
     * @param int    $userId Current user's id
     * @return array
     */
    public function sectionsForAttendance(string $role, int $userId): array
    {
        if ($role === 'faculty') {
            $stmt = $this->pdo->prepare(
                "SELECT cs.id, cs.section_number, cs.semester, cs.year, c.code, c.name AS course_name
                 FROM course_sections cs
                 JOIN courses c ON c.id = cs.course_id
                 WHERE cs.faculty_id = :uid
                 ORDER BY c.code, cs.year DESC, cs.semester"
            );
            $stmt->execute(['uid' => $userId]);
            return $stmt->fetchAll();
        }
        if ($this->isAdmin) {
            return $this->pdo->query(
                "SELECT cs.id, cs.section_number, cs.semester, cs.year, c.code, c.name AS course_name, u.name AS faculty_name
                 FROM course_sections cs
                 JOIN courses c ON c.id = cs.course_id
                 JOIN users u ON u.id = cs.faculty_id
                 ORDER BY c.code, cs.year DESC, cs.semester"
            )->fetchAll();
        }
        // HOD: own department only
        $stmt = $this->pdo->prepare(
            "SELECT cs.id, cs.section_number, cs.semester, cs.year, c.code, c.name AS course_name, u.name AS faculty_name
             FROM course_sections cs
             JOIN courses c ON c.id = cs.course_id
             JOIN users u ON u.id = cs.faculty_id
             WHERE c.department_id = :dept
             ORDER BY c.code, cs.year DESC, cs.semester"
        );
        $stmt->execute(['dept' => $this->departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Attendance records visible to this user.
     * Faculty: own sections only. HOD: own department. Admin: all.
     *
     * @param string $role
     * @param int    $userId
     * @param int|null $filterSectionId
     * @param string   $filterDate
     * @return array
     */
    public function attendance(string $role, int $userId, ?int $filterSectionId = null, string $filterDate = ''): array
    {
        $query =
            "SELECT a.*, c.code AS course_code, c.name AS course_name, cs.section_number, cs.semester, cs.year,
                    u.name AS recorded_by_name
             FROM attendance a
             JOIN course_sections cs ON cs.id = a.course_section_id
             JOIN courses c ON c.id = cs.course_id
             LEFT JOIN users u ON u.id = a.faculty_id
             WHERE 1=1";
        $params = [];

        if ($role === 'faculty') {
            $query .= " AND a.faculty_id = :uid";
            $params['uid'] = $userId;
        } elseif (!$this->isAdmin) {
            // HOD: own department only
            $query .= " AND c.department_id = :dept";
            $params['dept'] = $this->departmentId;
        }

        if ($filterSectionId) {
            $query .= " AND a.course_section_id = :sid";
            $params['sid'] = $filterSectionId;
        }
        if ($filterDate) {
            $query .= " AND a.date = :date";
            $params['date'] = $filterDate;
        }
        $query .= " ORDER BY a.date DESC, c.code";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Documents visible to this user.
     * Faculty/HOD: own department only. Admin: all.
     *
     * @param string   $filterCat
     * @param int|null $filterCourseId
     * @return array
     */
    public function documents(string $filterCat = '', ?int $filterCourseId = null): array
    {
        $query = "SELECT d.*, u.name AS uploader_name, c.code AS course_code, dept.name AS department_name
                  FROM documents d
                  JOIN users u ON u.id = d.user_id
                  LEFT JOIN courses c ON c.id = d.course_id
                  LEFT JOIN departments dept ON dept.id = d.department_id
                  WHERE 1=1";
        $params = [];

        if (!$this->isAdmin) {
            $query .= " AND d.department_id = :user_dept";
            $params['user_dept'] = $this->departmentId;
        }

        if ($filterCat) {
            $query .= " AND d.category = :cat";
            $params['cat'] = $filterCat;
        }
        if ($filterCourseId) {
            $query .= " AND d.course_id = :cid";
            $params['cid'] = $filterCourseId;
        }
        $query .= " ORDER BY d.created_at DESC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Timetable entries visible to this user.
     * Faculty/HOD: own department only. Admin: all, or filtered by dept.
     *
     * @param int|null $filterDeptId  Department filter (admin can pick any; others forced to own)
     * @return array
     */
    public function timetable(?int $filterDeptId = null): array
    {
        // Non-admin: always filter to own department
        if (!$this->isAdmin) {
            $effectiveDept = $this->departmentId;
        } else {
            $effectiveDept = $filterDeptId;
        }

        if ($effectiveDept) {
            $stmt = $this->pdo->prepare(
                "SELECT te.*, d.name AS department_name, c.code AS course_code, c.name AS course_name,
                        cs.section_number, u.name AS faculty_name
                 FROM timetable_entries te
                 JOIN departments d ON d.id = te.department_id
                 LEFT JOIN course_sections cs ON cs.id = te.course_section_id
                 LEFT JOIN courses c ON c.id = cs.course_id
                 LEFT JOIN users u ON u.id = cs.faculty_id
                 WHERE te.department_id = :dept
                 ORDER BY te.day_of_week, te.start_time"
            );
            $stmt->execute(['dept' => $effectiveDept]);
            return $stmt->fetchAll();
        }

        // Admin with no department filter: show all
        return $this->pdo->query(
            "SELECT te.*, d.name AS department_name, c.code AS course_code, c.name AS course2_name,
                    cs.section_number, u.name AS faculty_name
             FROM timetable_entries te
             JOIN departments d ON d.id = te.department_id
             LEFT JOIN course_sections cs ON cs.id = te.course_section_id
             LEFT JOIN courses c ON c.id = cs.course_id
             LEFT JOIN users u ON u.id = cs.faculty_id
             ORDER BY d.name, te.day_of_week, te.start_time"
        )->fetchAll();
    }

    /**
     * Dashboard statistics scoped to the user's department.
     * Admin: global counts. Others: own department only.
     *
     * @return array{faculty: int, departments: int, courses: int, sections: int, documents: int, attendance_today: int}
     */
    public function dashboardStats(): array
    {
        if ($this->isAdmin) {
            return [
                'faculty'         => $this->safeCount("SELECT COUNT(*) c FROM users WHERE role = 'faculty'"),
                'departments'     => $this->safeCount("SELECT COUNT(*) c FROM departments"),
                'courses'         => $this->safeCount("SELECT COUNT(*) c FROM courses"),
                'sections'        => $this->safeCount("SELECT COUNT(*) c FROM course_sections"),
                'documents'       => $this->safeCount("SELECT COUNT(*) c FROM documents"),
                'attendance_today' => $this->safeCount("SELECT COUNT(*) c FROM attendance WHERE date = date('now')"),
            ];
        }

        $dept = $this->departmentId;
        return [
            'faculty'         => $this->safeCountPrepared(
                "SELECT COUNT(*) c FROM users WHERE role = 'faculty' AND department_id = :dept",
                ['dept' => $dept]
            ),
            'departments'     => $this->safeCountPrepared(
                "SELECT COUNT(*) c FROM departments WHERE id = :dept",
                ['dept' => $dept]
            ),
            'courses'         => $this->safeCountPrepared(
                "SELECT COUNT(*) c FROM courses WHERE department_id = :dept",
                ['dept' => $dept]
            ),
            'sections'        => $this->safeCountPrepared(
                "SELECT COUNT(*) c FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE c.department_id = :dept",
                ['dept' => $dept]
            ),
            'documents'       => $this->safeCountPrepared(
                "SELECT COUNT(*) c FROM documents WHERE department_id = :dept",
                ['dept' => $dept]
            ),
            'attendance_today' => $this->safeCountPrepared(
                "SELECT COUNT(*) c FROM attendance a JOIN course_sections cs ON cs.id = a.course_section_id JOIN courses c ON c.id = cs.course_id WHERE a.date = date('now') AND c.department_id = :dept",
                ['dept' => $dept]
            ),
        ];
    }

    /**
     * Recent audit log activity for the current user.
     * Faculty/HOD: only own department's logs. Admin: own activity only (personal).
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function recentActivity(int $userId, int $limit = 5): array
    {
        if ($this->isAdmin) {
            $stmt = $this->pdo->prepare(
                'SELECT action, detail, created_at FROM audit_log WHERE user_id = :uid ORDER BY created_at DESC LIMIT :limit'
            );
            $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        }
        // HOD/faculty: only own department's logs
        $stmt = $this->pdo->prepare(
            'SELECT action, detail, created_at FROM audit_log WHERE user_id = :uid AND department_id = :dept ORDER BY created_at DESC LIMIT :limit'
        );
        $stmt->bindValue('uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue('dept', $this->departmentId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── Internal helpers ──────────────────────────────────────

    private function safeCount(string $sql): int
    {
        try {
            return (int) $this->pdo->query($sql)->fetch()['c'];
        } catch (Throwable $e) {
            error_log('DepartmentScope::safeCount failed: ' . $e->getMessage());
            return -1; // -1 signals DB error; caller can display "unavailable"
        }
    }

    private function safeCountPrepared(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetch()['c'];
        } catch (Throwable $e) {
            error_log('DepartmentScope::safeCountPrepared failed: ' . $e->getMessage());
            return -1; // -1 signals DB error; caller can display "unavailable"
        }
    }
}
