# Authorization Policy — FacultyIS v2.16

This document defines the **single source of truth** for authorization
decisions in FacultyIS. Both the PHP frontend and Flask API must
conform to this policy.

## Architecture

FacultyIS has exactly **three security primitives** plus audit logging:

```
                   Request
                      │
                      ▼
                Authentication
                      │
                      ▼
                 Current User
                      │
          ┌───────────┴───────────┐
          ▼                       ▼
 DepartmentScope          AuthorizationPolicy
    READ SIDE                 WRITE SIDE
          │                       │
          ▼                       ▼
      SELECTs               mutations
          │                       │
          └───────────┬───────────┘
                      ▼
                    SQLite
                      │
                      ▼
                   AuditLog
                      │
                      ▼
              department_id
             (auto-derived)
```

### DepartmentScope (Read-Side)

**Rule: Every read goes through a scope.**

`DepartmentScope::forUser($user, $pdo)` returns a scope object whose
methods automatically enforce the department boundary. Pages must call
these methods instead of writing manual `if ($user['role'] === 'admin')` branches.

### AuthorizationPolicy (Write-Side)

**Rule: Every write authorizes the existing object AND the requested new state.**

`AuthorizationPolicy::forUser($user, $pdo)` returns a policy object whose
methods either return void (allowed) or throw 403 Forbidden. Pages must
call these methods for every mutation instead of inline role/department checks.

### AuditLog (Observation)

**Rule: Every security-sensitive mutation is logged with department_id.**

`AuditLog::recordEntity()` now **auto-derives** `department_id` from the
entity itself when not explicitly provided. The derivation map:

| Entity Type      | Derivation Path                                       |
|------------------|-------------------------------------------------------|
| department       | `departments.id` → `departments.id`                  |
| user             | `users.id` → `users.department_id`                   |
| course           | `courses.id` → `courses.department_id`               |
| course_section   | `course_sections.id` → `courses.department_id` (JOIN) |
| attendance       | `attendance.id` → `courses.department_id` (JOIN)      |
| document         | `documents.id` → `documents.department_id`            |
| timetable_entry  | `timetable_entries.id` → `timetable_entries.department_id` |

This removes an entire class of logging mistakes where a caller forgets
to pass `$departmentId`.

## Roles

| Role     | Scope                                          |
|----------|------------------------------------------------|
| admin    | Institution-wide: all departments, all resources |
| hod      | Department-scoped: own department only          |
| faculty  | Department-scoped + ownership: own department, own sections/documents |

## Read-Side Policy (DepartmentScope)

| Resource              | admin        | hod                      | faculty                          |
|-----------------------|--------------|--------------------------|----------------------------------|
| Departments list      | All          | Own department only      | Own department only              |
| Faculty list          | All          | Own department only      | Own department only              |
| Courses list          | All          | Own department only      | Own department only              |
| Sections list         | All          | Own department only      | Own department only              |
| Attendance list       | All          | Own department only      | Own sections only                |
| Documents list        | All          | Own department only      | Own department only              |
| Timetable entries     | All          | Own department only      | Own department only              |
| Dashboard statistics  | All (global) | Own department only      | Own department only              |
| Audit logs            | All          | Own department only      | ❌ Forbidden (403)               |
| Workload reports      | All          | Own department only      | ❌ Forbidden (403)               |

## Write-Side Policy (AuthorizationPolicy)

### Faculty

| Operation       | admin | hod                                    | faculty |
|-----------------|-------|----------------------------------------|---------|
| Create faculty  | ✅    | Own department only                    | ❌      |
| Edit faculty    | ✅    | Own department: existing + requested   | ❌      |
| Delete faculty  | ✅    | Own department only                    | ❌      |
| Change role     | ✅    | ❌ (admin-only, prevents escalation)    | ❌      |

### Courses

| Operation       | admin | hod                                    | faculty |
|-----------------|-------|----------------------------------------|---------|
| Create course   | ✅    | Own department only                    | ❌      |
| Edit course     | ✅    | Own department: existing + requested   | ❌      |
| Disable course  | ✅    | Own department only                    | ❌      |
| Delete course   | ✅    | ❌ (admin-only)                         | ❌      |

### Sections

| Operation       | admin | hod                                    | faculty |
|-----------------|-------|----------------------------------------|---------|
| Create section  | ✅    | Own department only (course + faculty) | ❌      |
| Edit section    | ✅    | Own department: existing + requested   | ❌      |
| Delete section  | ✅    | Own department only                    | ❌      |

### Attendance

| Operation          | admin | hod                  | faculty             |
|--------------------|-------|----------------------|---------------------|
| Record attendance  | ✅    | Own department only  | Own sections only   |
| Delete attendance  | ✅    | Own department only  | ❌                  |

### Timetable

| Operation            | admin | hod                                    | faculty |
|----------------------|-------|----------------------------------------|---------|
| Create timetable     | ✅    | Own department only                    | ❌      |
| Edit timetable       | ✅    | Own department: existing + requested   | ❌      |
| Delete timetable     | ✅    | Own department only                    | ❌      |

### Documents

| Operation          | admin | hod                  | faculty                     |
|--------------------|-------|----------------------|-----------------------------|
| Upload document    | ✅    | Own department only  | Own department only         |
| Download document  | ✅    | Own department only  | Own department only         |
| Delete document    | ✅    | Own department only  | Own uploads only            |

### Departments

| Operation            | admin | hod | faculty |
|----------------------|-------|-----|---------|
| Create department    | ✅    | ❌  | ❌      |
| Edit department      | ✅    | ❌  | ❌      |
| Disable department   | ✅    | ❌  | ❌      |
| Delete department    | ✅    | ❌  | ❌      |

### Audit Logs

| Operation          | admin | hod                 | faculty |
|--------------------|-------|---------------------|---------|
| View audit logs    | ✅    | Own department only | ❌      |
| Purge audit logs   | ✅    | ❌                  | ❌      |

## Session & JWT Revocation

- Every mutation that changes a user's role, department, or password
  increments `auth_version` in the `users` table.
- **PHP sessions:** `Auth::requireLogin()` validates `auth_version`
  against DB on every request. Stale sessions are destroyed.
- **Flask JWTs:** `jwt_required()` validates `auth_version`, `role`,
  and `department_id` against DB on every request. Mismatches → 401.
- Deleted-user tokens are rejected immediately.

## Audit Logging

### Department Attribution Policy

Audit log `department_id` is populated according to these rules:

**Department/entity events** (MUST have `department_id != NULL`):
```
FACULTY_*, DEPARTMENT_*, COURSE_*, SECTION_*, ATTENDANCE_*,
TIMETABLE_*, DOCUMENT_*, EXPORT_*
```

**Global/system events** (MAY have `department_id = NULL`):
```
AUTH_LOGIN, AUTH_LOGOUT, AUTH_LOGIN_FAILED, AUTH_PASSWORD_RESET_REQUEST,
AUTH_PASSWORD_RESET_COMPLETE, AUDIT_PURGE
```

These are user/account-scoped, not entity-scoped, so NULL department_id
is acceptable.

### Auto-Derivation (v2.13)

`AuditLog::recordEntity()` automatically derives `department_id` from
the entity when not explicitly provided. Callers may still pass
`$departmentId` to override (e.g., for newly created entities with
no persisted ID yet). This makes it **hard to do incorrectly** — the
default behavior is correct.

### Historical Migration Caveat

For pre-v2.12 audit records, `department_id` was backfilled from
`audit_log.user_id → users.department_id`. This represents the
actor's **current** department at migration time, not guaranteed
historical entity ownership. This is an audit-integrity caveat,
not an authorization vulnerability.

## Cache Security

- Report cache entries are keyed by department scope.
- On 401/403 from Flask, PHP **never** falls back to cache.
- A cache must never weaken the authorization decision made by
  the authoritative service.

## Effective Department Selection

`DepartmentScope::effectiveDepartmentId($requestedDeptId)` provides
a single point of truth for deriving the effective department:

- Admin: returns the requested department (or null for all).
- Non-admin: always returns own department (ignores request).

This eliminates the duplicated `if ($user['role'] === 'hod')` pattern
that was previously scattered across reports.php, export.php, etc.

## Dashboard Error Handling

`DepartmentScope::safeCount()` returns `0` on database failure.
This is a deliberate choice: a failed count is treated as "no data"
rather than an error state, keeping the dashboard functional.

## Consistency Invariants

These should never be violated if all mutations go through
`AuthorizationPolicy`:

1. `course_section.course.department_id == course_section.faculty.department_id`
2. `document.course.department_id == document.department_id` (when both are set)
3. `timetable_entry.department_id == timetable_entry.course_section.course.department_id`
4. `audit_log.department_id` is populated for all entity-type actions (except DEPARTMENT_DELETE, which may reference a now-deleted department)
5. `courses.department_id` is NOT NULL (no orphaned courses)
6. `documents.department_id` is NOT NULL (no orphaned documents)
7. `timetable_entries.department_id` is NOT NULL (no orphaned entries)
8. `course_sections.faculty_id` → user must have `role = 'faculty'`

Run `./check-consistency.sh` to verify these invariants (11 checks in v2.16).
