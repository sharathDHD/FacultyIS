<?php
// Version: v2.12


/**
 * Audit logging — records every significant mutation for traceability.
 *
 * Two methods:
 *   record()         — simple action log (backward compatible)
 *   recordEntity()   — structured log with entity_type, entity_id, JSON diffs
 *
 * Both methods capture request context (IP address, User-Agent) automatically.
 * Both never throw — audit logging must never break the request it's logging.
 *
 * v2.13: AuditLog::recordEntity() now auto-derives department_id from the
 * entity itself when not explicitly provided. This removes an entire class
 * of logging mistakes where a caller forgets to pass $departmentId.
 *
 * Derivation map:
 *   department     → departments.id → departments.id
 *   user           → users.id → users.department_id
 *   course         → courses.id → courses.department_id
 *   course_section → course_sections.id → course (via JOIN) → courses.department_id
 *   attendance     → attendance.id → course_section (via JOIN) → course → courses.department_id
 *   document       → documents.id → documents.department_id
 *   timetable_entry → timetable_entries.id → timetable_entries.department_id
 *
 * Global/system events (AUTH_*, AUDIT_PURGE) may have department_id = NULL
 * since they are account-scoped, not entity-scoped.
 *
 * Action vocabulary (centralized to prevent spelling drift):
 *   AUTH_LOGIN, AUTH_LOGIN_FAILED, AUTH_LOGOUT, AUTH_REGISTER,
 *   AUTH_PASSWORD_RESET_REQUEST, AUTH_PASSWORD_RESET_COMPLETE
 *   FACULTY_CREATE, FACULTY_UPDATE, FACULTY_DELETE, FACULTY_ROLE_CHANGE
 *   DEPARTMENT_CREATE, DEPARTMENT_UPDATE, DEPARTMENT_ENABLE, DEPARTMENT_DISABLE, DEPARTMENT_DELETE
 *   COURSE_CREATE, COURSE_UPDATE, COURSE_ENABLE, COURSE_DISABLE, COURSE_DELETE
 *   SECTION_CREATE, SECTION_UPDATE, SECTION_DELETE
 *   ATTENDANCE_RECORD, ATTENDANCE_DELETE
 *   TIMETABLE_CREATE, TIMETABLE_UPDATE, TIMETABLE_DELETE
 *   DOCUMENT_UPLOAD, DOCUMENT_DOWNLOAD, DOCUMENT_DELETE
 *   AUDIT_PURGE
 *   EXPORT_FACULTY
 */

class AuditLog
{
    /**
     * Entity type → SQL to derive department_id from the entity.
     * Each query takes :entity_id and returns a single department_id column.
     * If the entity doesn't exist or has no department, returns NULL.
     */
    private static array $departmentDerivationMap = [
        'department'      => 'SELECT id AS department_id FROM departments WHERE id = :entity_id',
        'user'            => 'SELECT department_id FROM users WHERE id = :entity_id',
        'course'          => 'SELECT department_id FROM courses WHERE id = :entity_id',
        'course_section'  => 'SELECT c.department_id FROM course_sections cs JOIN courses c ON c.id = cs.course_id WHERE cs.id = :entity_id',
        'attendance'      => 'SELECT c.department_id FROM attendance a JOIN course_sections cs ON cs.id = a.course_section_id JOIN courses c ON c.id = cs.course_id WHERE a.id = :entity_id',
        'document'        => 'SELECT department_id FROM documents WHERE id = :entity_id',
        'timetable_entry' => 'SELECT department_id FROM timetable_entries WHERE id = :entity_id',
    ];

    /**
     * Simple action log (backward compatible).
     * Used for actions that aren't tied to a specific entity row
     * (login, logout, audit_purge, etc.)
     *
     * @param int|null $userId        Who performed the action
     * @param string   $action        Action name from vocabulary
     * @param string   $detail        Human-readable summary
     * @param int|null $departmentId  Department context for HOD-scoped queries
     */
    public static function record(?int $userId, string $action, string $detail, ?int $departmentId = null): void
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (user_id, action, department_id, detail, ip_address, user_agent,
                                        request_id, session_id_hash, source)
                 VALUES (:uid, :action, :dept, :detail, :ip, :ua, :rid, :sid, :src)'
            );
            $stmt->execute([
                'uid'     => $userId,
                'action'  => $action,
                'dept'    => $departmentId,
                'detail'  => $detail,
                'ip'      => self::clientIp(),
                'ua'      => self::clientUserAgent(),
                'rid'     => self::requestId(),
                'sid'     => self::sessionIdHash(),
                'src'     => 'php',
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog failed: ' . $e->getMessage());
        }
    }

    /**
     * Structured entity log with before/after values.
     *
     * v2.13: department_id is auto-derived from the entity when not provided.
     * Callers may still pass $departmentId explicitly to override derivation
     * (e.g., when the entity hasn't been persisted yet and has no ID).
     *
     * @param int|null    $userId        Who performed the action
     * @param string      $action        Action name from vocabulary (e.g. 'COURSE_DISABLE')
     * @param string      $entityType    Entity table name (e.g. 'department', 'course', 'user')
     * @param int|null    $entityId      PK of the affected row
     * @param string      $detail        Human-readable summary
     * @param array|null  $oldValues     Previous state (key-value pairs of changed columns)
     * @param array|null  $newValues     New state (key-value pairs of changed columns)
     * @param int|null    $departmentId  Department context (auto-derived if null)
     */
    public static function recordEntity(
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId,
        string $detail,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $departmentId = null
    ): void {
        try {
            // ── Auto-derive department_id from entity if not provided ──
            // This prevents the common mistake of logging an entity event
            // without department context, which would break HOD-scoped queries.
            if ($departmentId === null && $entityId !== null && isset(self::$departmentDerivationMap[$entityType])) {
                $departmentId = self::deriveDepartmentFromEntity($entityType, $entityId);
            }

            $pdo = Database::connection();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (user_id, action, entity_type, entity_id, department_id, detail,
                                        old_values, new_values, ip_address, user_agent,
                                        request_id, session_id_hash, source)
                 VALUES (:uid, :action, :etype, :eid, :dept, :detail, :old, :new, :ip, :ua,
                         :rid, :sid, :src)'
            );
            $stmt->execute([
                'uid'     => $userId,
                'action'  => $action,
                'etype'   => $entityType,
                'eid'     => $entityId,
                'dept'    => $departmentId,
                'detail'  => $detail,
                'old'     => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                'new'     => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                'ip'      => self::clientIp(),
                'ua'      => self::clientUserAgent(),
                'rid'     => self::requestId(),
                'sid'     => self::sessionIdHash(),
                'src'     => 'php',
            ]);
        } catch (Throwable $e) {
            error_log('AuditLog::recordEntity failed: ' . $e->getMessage());
        }
    }

    /**
     * Derive department_id from an entity by querying its actual DB row.
     * Returns null if the entity doesn't exist or has no department.
     */
    private static function deriveDepartmentFromEntity(string $entityType, int $entityId): ?int
    {
        try {
            $pdo = Database::connection();
            $sql = self::$departmentDerivationMap[$entityType];
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['entity_id' => $entityId]);
            $result = $stmt->fetchColumn();
            return $result !== false ? (int) $result : null;
        } catch (Throwable $e) {
            error_log("AuditLog: failed to derive department_id for $entityType/$entityId: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Request correlation ID — from X-Request-ID header or generated.
     */
    private static function requestId(): ?string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? substr(bin2hex(random_bytes(6)), 0, 12);
    }

    /**
     * SHA-256 hash of the session ID — for traceability without exposing the session.
     */
    private static function sessionIdHash(): ?string
    {
        $sid = session_id();
        if ($sid === '' || $sid === false) {
            return null;
        }
        return hash('sha256', $sid);
    }

    /**
     * Client IP address — uses REMOTE_ADDR directly.
     * X-Forwarded-For is not trusted without an explicit proxy configuration.
     */
    private static function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Client User-Agent — truncated to 500 chars to fit column.
     */
    private static function clientUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua !== null && strlen($ua) > 500) {
            $ua = substr($ua, 0, 500);
        }
        return $ua;
    }
}
