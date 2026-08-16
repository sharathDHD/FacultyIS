#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# FacultyIS — Database Consistency Checks
# ═══════════════════════════════════════════════════════════
# Verifies that department_id is consistent across related
# records. These invariants should never be violated if all
# mutations go through AuthorizationPolicy, but this script
# catches drift from direct DB edits or bugs.
# ═══════════════════════════════════════════════════════════
set -uo pipefail

R='\033[0m'; B='\033[1m'; D='\033[2m'; G='\033[32m'; Y='\033[33m'; RED='\033[31m'; GR='\033[90m'

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$PROJECT_DIR"

# ── Derive version from pyproject.toml ──────────────────────
# shellcheck disable=SC2312
APP_VERSION="${APP_VERSION:-$(grep -m1 '^version' "$PROJECT_DIR/pyproject.toml" 2>/dev/null | sed 's/.*=.*"\(.*\)"/\1/')}"
APP_VERSION="${APP_VERSION:-unknown}"

DB_PATH="$PROJECT_DIR/data/facultyis.sqlite3"

if [ ! -f "$DB_PATH" ]; then
  printf "  ${RED}✗${R} Database not found at ${DB_PATH}\n"
  exit 1
fi

if ! command -v sqlite3 &>/dev/null; then
  printf "  ${RED}✗${R} sqlite3 CLI required\n"
  exit 1
fi

PASS=0
FAIL=0

check_pass() { printf "  ${G}✓${R} %s\n" "$1"; PASS=$((PASS + 1)); }
check_fail() { printf "  ${RED}✗${R} %s\n" "$1"; FAIL=$((FAIL + 1)); }

printf "\n  ${B}FacultyIS — DB Consistency Checks (v2.16)${R}\n"
printf "  ${GR}──────────────────────────────${R}\n\n"

# ── 1. Section → Course department consistency ─────────────
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM course_sections cs
  JOIN courses c ON c.id = cs.course_id
  JOIN users u ON u.id = cs.faculty_id
  WHERE c.department_id != u.department_id
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All course_section faculty belong to the same department as their course"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check section→faculty department consistency (query error)"
else
  check_fail "$VIOLATIONS section(s) have faculty in a different department than their course"
fi

# ── 2. Document → Course department consistency ────────────
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM documents d
  JOIN courses c ON c.id = d.course_id
  WHERE d.department_id IS NOT NULL
    AND c.department_id != d.department_id
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All document department_id matches their linked course's department"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check document→course department consistency"
else
  check_fail "$VIOLATIONS document(s) have department_id mismatching their course"
fi

# ── 3. Timetable → Section/Course department consistency ───
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM timetable_entries te
  JOIN course_sections cs ON cs.id = te.course_section_id
  JOIN courses c ON c.id = cs.course_id
  WHERE c.department_id != te.department_id
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All timetable entry department_id matches their section's course department"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check timetable→section department consistency"
else
  check_fail "$VIOLATIONS timetable entry/entries have department_id mismatching their section"
fi

# ── 4. Attendance → Section department consistency ─────────
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM attendance a
  JOIN course_sections cs ON cs.id = a.course_section_id
  JOIN courses c ON c.id = cs.course_id
  JOIN users u ON u.id = a.faculty_id
  WHERE u.department_id IS NOT NULL
    AND c.department_id != u.department_id
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All attendance records have faculty in the same department as their section"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check attendance→faculty department consistency"
else
  check_fail "$VIOLATIONS attendance record(s) have faculty in a different department than their section"
fi

# ── 5. Audit log department_id populated for entity actions ─
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM audit_log
  WHERE entity_type IS NOT NULL
    AND department_id IS NULL
    AND action NOT IN ('AUTH_LOGIN', 'AUTH_LOGOUT', 'AUTH_REGISTER', 'AUTH_PASSWORD_RESET', 'AUTH_PASSWORD_RESET_REQUEST', 'AUTH_PASSWORD_RESET_COMPLETE', 'AUDIT_PURGE', 'DEPARTMENT_DELETE')
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All entity audit logs have department_id populated"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check audit log department_id population"
else
  check_fail "$VIOLATIONS entity audit log(s) missing department_id"
fi

# ── 6. Foreign key integrity ──────────────────────────────
FK_RESULT=$(sqlite3 "$DB_PATH" "PRAGMA foreign_key_check" 2>/dev/null)
if [ -z "$FK_RESULT" ]; then
  check_pass "All foreign key constraints satisfied"
else
  FK_COUNT=$(echo "$FK_RESULT" | wc -l)
  check_fail "$FK_COUNT foreign key violation(s) detected"
  echo "$FK_RESULT" | head -5 | sed 's/^/    /'
fi

# ── 7. Course → department_id NOT NULL ────────────────────
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM courses WHERE department_id IS NULL
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All courses have department_id assigned (no orphans)"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check course department_id NOT NULL"
else
  check_fail "$VIOLATIONS course(s) with NULL department_id (orphaned)"
fi

# ── 8. Document → department_id NOT NULL ──────────────────
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM documents WHERE department_id IS NULL
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All documents have department_id assigned (no orphans)"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check document department_id NOT NULL"
else
  check_fail "$VIOLATIONS document(s) with NULL department_id (orphaned)"
fi

# ── 9. Timetable entry → department_id NOT NULL ───────────
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM timetable_entries WHERE department_id IS NULL
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All timetable entries have department_id assigned (no orphans)"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check timetable entry department_id NOT NULL"
else
  check_fail "$VIOLATIONS timetable entry/entries with NULL department_id (orphaned)"
fi

# ── 10. Section → faculty must have role='faculty' ────────
# A course section should never be assigned to an HOD or admin.
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM course_sections cs
  JOIN users u ON u.id = cs.faculty_id
  WHERE u.role != 'faculty'
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All course sections assigned to faculty-role users only"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check section→faculty role consistency"
else
  check_fail "$VIOLATIONS section(s) assigned to non-faculty users (HOD/admin)"
fi

# ── 11. Course → department exists ────────────────────────
# All course department_id references must point to existing departments.
VIOLATIONS=$(sqlite3 "$DB_PATH" "
  SELECT COUNT(*) FROM courses c
  LEFT JOIN departments d ON d.id = c.department_id
  WHERE d.id IS NULL
" 2>/dev/null || echo "-1")

if [ "$VIOLATIONS" = "0" ]; then
  check_pass "All course department_id references point to existing departments"
elif [ "$VIOLATIONS" = "-1" ]; then
  check_fail "Could not check course→department reference integrity"
else
  check_fail "$VIOLATIONS course(s) with department_id pointing to non-existent department"
fi

# ── Summary ────────────────────────────────────────────────
printf "\n  ${GR}──────────────────────────────${R}\n"
if [ $FAIL -eq 0 ]; then
  printf "  ${G}✓${R} ${B}%d passed${R}\n" "$PASS"
else
  printf "  ${RED}✗${R} ${B}%d failed${R}, ${G}%d passed${R}\n" "$FAIL" "$PASS"
fi
printf "\n"

exit $FAIL
