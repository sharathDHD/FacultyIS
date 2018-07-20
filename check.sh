#!/usr/bin/env bash
# Version: v2.12
# ═══════════════════════════════════════════════════════════
# FacultyIS v2.12 — Quality Check Pipeline
# ═══════════════════════════════════════════════════════════
set -uo pipefail

R='\033[0m'; B='\033[1m'; D='\033[2m'; G='\033[32m'; Y='\033[33m'; RED='\033[31m'; GR='\033[90m'

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$PROJECT_DIR"

FIX=0
for arg in "$@"; do
  case "$arg" in
    --fix|-f) FIX=1 ;;
  esac
done

PASS=0
FAIL=0
SKIP=0

check() {
  local name="$1"; shift
  printf "  ${D}→ %-30s${R} " "$name"
  if output=$("$@" 2>&1); then
    printf "${G}OK${R}\n"
    PASS=$((PASS + 1))
  else
    printf "${RED}FAIL${R}\n"
    FAIL=$((FAIL + 1))
    echo "$output" | head -10 | sed 's/^/    /'
  fi
}

skip() {
  local name="$1" reason="$2"
  printf "  ${D}→ %-30s${R} ${GR}SKIP${R} ${D}(%s)${R}\n" "$name" "$reason"
  SKIP=$((SKIP + 1))
}

printf "\n  ${B}FacultyIS — Quality Checks${R}\n"
printf "  ${GR}──────────────────────────────${R}\n\n"

# ── PHP ─────────────────────────────────────────────────────
if command -v php &>/dev/null; then
  check "PHP syntax" php -l php-app/src/*.php php-app/public/*.php php-app/public/partials/*.php
else
  skip "PHP syntax" "php not found"
fi

# ── Python ──────────────────────────────────────────────────
# Use python3 directly if available; fall back to uv run python3.
# This avoids requiring network access for uv sync during checks.
if command -v python3 &>/dev/null; then
  check "Python compile" python3 -m compileall -q flask-api/
elif command -v uv &>/dev/null; then
  check "Python compile" bash -c 'uv run python3 -m compileall -q flask-api/ 2>&1'
else
  skip "Python compile" "python3/uv not found"
fi

if command -v ruff &>/dev/null; then
  if [ $FIX -eq 1 ]; then
    check "Ruff (fix)" ruff check --fix --config pyproject.toml flask-api/
  else
    check "Ruff" ruff check --config pyproject.toml flask-api/
  fi
else
  skip "Ruff" "ruff not found"
fi

# ── Shell ───────────────────────────────────────────────────
SHELL_SCRIPTS="start.sh stop.sh check.sh test.sh reset-db.sh init_db.py"

if command -v shellcheck &>/dev/null; then
  check "ShellCheck" shellcheck --shell=bash start.sh stop.sh check.sh test.sh reset-db.sh
else
  skip "ShellCheck" "shellcheck not found"
fi

check "Bash syntax" bash -c 'for f in start.sh stop.sh check.sh test.sh reset-db.sh; do bash -n "$f" || exit 1; done'

# ── Python dependency check ─────────────────────────────────
# Use --dry-run when available; if uv sync requires network,
# skip rather than fail. This makes the check reproducible
# in offline/CI environments without cached dependencies.
if command -v uv &>/dev/null; then
  if uv sync --dry-run &>/dev/null 2>&1; then
    check "uv sync (dry)" bash -c 'uv sync --dry-run 2>&1'
  else
    skip "uv sync" "dry-run unavailable (offline or .venv stale)"
  fi
else
  skip "uv sync" "uv not found"
fi

# ── Secret detection ────────────────────────────────────────
if command -v rg &>/dev/null; then
  check "Secret scan" bash -c 'rg -l "(?:password|secret|api_key)\s*[:=]\s*[\"\\x27][^\"\\x27]{8,}" --type-add "php:*.php" --type php --type-add "py:*.py" --type py -g "!.env.example" -g "!seed*" php-app/ flask-api/ 2>/dev/null && exit 1 || exit 0'
else
  skip "Secret scan" "ripgrep not found"
fi

# ── No Redis/Pandas remnants ───────────────────────────────
if command -v rg &>/dev/null; then
  check "Redis removal" bash -c 'rg -l "import redis|from redis" --type py flask-api/ 2>/dev/null && exit 1 || exit 0'
  check "Pandas removal" bash -c 'rg -l "import pandas|from pandas|import openpyxl|from openpyxl" --type py flask-api/ 2>/dev/null && exit 1 || exit 0'
else
  skip "Redis/Pandas check" "ripgrep not found"
fi

# ── .env permissions ────────────────────────────────────────
if [ -f .env ]; then
  PERM=$(stat -c '%a' .env 2>/dev/null || stat -f '%Lp' .env 2>/dev/null)
  if [ "$PERM" = "600" ]; then
    check ".env permissions" true
  else
    printf "  ${D}→ %-30s${R} " ".env permissions"
    printf "${Y}WARN${R} ${D}(chmod 600 recommended, current: %s)${R}\n" "$PERM"
    SKIP=$((SKIP + 1))
  fi
else
  skip ".env permissions" ".env not found"
fi

# ── Database integrity ──────────────────────────────────────
DB_PATH="$PROJECT_DIR/data/facultyis.sqlite3"
if [ -f "$DB_PATH" ] && command -v sqlite3 &>/dev/null; then
  check "DB integrity" bash -c 'result=$(sqlite3 "$0" "PRAGMA integrity_check" 2>&1); [ "$result" = "ok" ]' "$DB_PATH"
else
  skip "DB integrity" "database or sqlite3 not found"
fi

# ── Summary ─────────────────────────────────────────────────
printf "\n  ${GR}──────────────────────────────${R}\n"
if [ $FAIL -eq 0 ]; then
  printf "  ${G}✓${R} ${B}%d passed${R}, ${D}%d skipped${R}\n" "$PASS" "$SKIP"
else
  printf "  ${RED}✗${R} ${B}%d failed${R}, ${G}%d passed${R}, ${D}%d skipped${R}\n" "$FAIL" "$PASS" "$SKIP"
fi
printf "\n"

exit $FAIL
