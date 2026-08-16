#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# FacultyIS — Stop
# ═══════════════════════════════════════════════════════════
# Kills only the FacultyIS processes started by ./start.sh.
# Verifies PID belongs to FacultyIS before sending signals.
# Uses graceful shutdown: SIGTERM → wait → SIGKILL if needed.
# ═══════════════════════════════════════════════════════════
set -uo pipefail

R='\033[0m'; G='\033[32m'; Y='\033[33m'; D='\033[2m'; B='\033[1m'; GR='\033[90m'; RED='\033[31m'

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_DIR="$PROJECT_ROOT/.pids"

# ── Derive version from pyproject.toml ──────────────────────
# shellcheck disable=SC2312
APP_VERSION="${APP_VERSION:-$(grep -m1 '^version' "$PROJECT_ROOT/pyproject.toml" 2>/dev/null | sed 's/.*=.*"\(.*\)"/\1/')}"
APP_VERSION="${APP_VERSION:-unknown}"

STOPPED=0

printf "  ${D}Stopping FacultyIS...${R}\n"

# ── Helper: verify PID belongs to FacultyIS ────────────────
# Checks that the process command line contains a FacultyIS-
# related keyword (gunicorn, flask, php -S) to avoid killing
# an unrelated process that reused the PID.
pid_belongs_to_facultyis() {
  local pid=$1
  # /proc/PID/cmdline is null-delimited; tr replaces \0 with spaces
  local cmdline
  cmdline=$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null) || return 1
  # Match any of: gunicorn, app:app (Flask), php -S (built-in server)
  if echo "$cmdline" | grep -qiE 'gunicorn|app:app|php.*-S'; then
    return 0
  fi
  return 1
}

# ── Helper: graceful stop (TERM → wait up to 3s → KILL) ───
graceful_stop() {
  local pid=$1
  local name=$2

  # Verify PID belongs to FacultyIS
  if ! pid_belongs_to_facultyis "$pid"; then
    printf "  ${Y}⚠${R} PID %s does not belong to FacultyIS — skipping${R}\n" "$pid"
    return 1
  fi

  # Send SIGTERM
  kill -TERM "$pid" 2>/dev/null

  # Wait up to 3 seconds for graceful exit
  local w=0
  while [ $w -lt 30 ] && kill -0 "$pid" 2>/dev/null; do
    sleep 0.1
    w=$((w + 1))
  done

  if kill -0 "$pid" 2>/dev/null; then
    # Still alive — force kill
    kill -KILL "$pid" 2>/dev/null
    printf "  ${Y}⚠${R} %s force-killed (PID %s did not exit gracefully)\n" "$name" "$pid"
  else
    printf "  ${G}✓${R} %s stopped (PID %s)\n" "$name" "$pid"
  fi
  return 0
}

# Kill Flask process
if [ -f "$PID_DIR/flask.pid" ]; then
  PID=$(cat "$PID_DIR/flask.pid" 2>/dev/null)
  if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
    if graceful_stop "$PID" "Flask"; then
      STOPPED=1
    fi
  else
    printf "  ${GR}·${R} Flask not running\n"
  fi
  rm -f "$PID_DIR/flask.pid"
fi

# Kill PHP process
if [ -f "$PID_DIR/php.pid" ]; then
  PID=$(cat "$PID_DIR/php.pid" 2>/dev/null)
  if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
    if graceful_stop "$PID" "PHP"; then
      STOPPED=1
    fi
  else
    printf "  ${GR}·${R} PHP not running\n"
  fi
  rm -f "$PID_DIR/php.pid"
fi

if [ $STOPPED -eq 0 ]; then
  printf "  ${D}No FacultyIS processes found.${R}\n"
fi

printf "\n"
