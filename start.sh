#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# FacultyIS v2.12 — Start (local development)
# ═══════════════════════════════════════════════════════════
# Copy this folder → run this script → it works.
#
#   ./start.sh          — start Flask + PHP
#   ./start.sh --reset  — stop services, reinitialize database, start
# ═══════════════════════════════════════════════════════════
set -uo pipefail

R='\033[0m'; B='\033[1m'; D='\033[2m'; G='\033[32m'; Y='\033[33m'; C='\033[36m'; RED='\033[31m'; GR='\033[90m'

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_ROOT"

PID_DIR="$PROJECT_ROOT/.pids"
LOG_DIR="$PROJECT_ROOT/logs"
DB_PATH="$PROJECT_ROOT/data/facultyis.sqlite3"

FLASK_HOST="${FLASK_HOST:-127.0.0.1}"
FLASK_PORT="${FLASK_PORT:-5000}"
PHP_HOST="${PHP_HOST:-127.0.0.1}"
PHP_PORT="${PHP_PORT:-8080}"

# ── Parse options ───────────────────────────────────────────
RESET=0
for arg in "$@"; do
  case "$arg" in
    --reset|-r) RESET=1 ;;
  esac
done

# ═══════════════════════════════════════════════════════════
# Step 0: Load environment
# ═══════════════════════════════════════════════════════════
if [ -f "$PROJECT_ROOT/.env" ]; then
  ENV_PERM=$(stat -c '%a' "$PROJECT_ROOT/.env" 2>/dev/null || stat -f '%Lp' "$PROJECT_ROOT/.env" 2>/dev/null)
  if [ "$ENV_PERM" != "600" ]; then
    chmod 600 "$PROJECT_ROOT/.env"
  fi
  set -a
  # shellcheck disable=SC1090
  source "$PROJECT_ROOT/.env"
  set +a
fi

printf "\n  ${B}FacultyIS${R} ${D}v2.12${R}\n"
printf "  ${GR}──────────────────────────────${R}\n\n"

# ═══════════════════════════════════════════════════════════
# Step 1: Check prerequisites
# ═══════════════════════════════════════════════════════════
printf "  ${B}[1/5]${R} ${B}Checking environment${R}\n"

if command -v uv &>/dev/null; then
  printf "        ${G}✓${R} uv\n"
else
  printf "        ${RED}✗${R} uv is not installed\n"
  printf "        ${D}Install: curl -LsSf https://astral.sh/uv/install.sh | sh${R}\n"
  exit 1
fi

if uv run python3 -c "pass" &>/dev/null; then
  PY_VERSION=$(uv run python3 -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')" 2>/dev/null)
  printf "        ${G}✓${R} Python %s\n" "$PY_VERSION"
else
  printf "        ${RED}✗${R} Python 3.12+ not found\n"
  exit 1
fi

if command -v php &>/dev/null; then
  PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
  printf "        ${G}✓${R} PHP %s\n" "$PHP_VERSION"
else
  printf "        ${RED}✗${R} PHP is not installed\n"
  printf "        ${D}Install: sudo apt install php php-sqlite3 php-curl (or equivalent)${R}\n"
  exit 1
fi

PHP_EXT_FAIL=0
for ext in pdo_sqlite curl fileinfo openssl session; do
  if php -m 2>/dev/null | grep -qi "$ext"; then
    printf "        ${G}✓${R} PHP %s extension\n" "$ext"
  else
    printf "        ${RED}✗${R} PHP %s extension not loaded\n" "$ext"
    PHP_EXT_FAIL=1
  fi
done

if [ $PHP_EXT_FAIL -eq 1 ]; then
  printf "        ${D}Install missing extensions (Debian/Ubuntu example):\n"
  printf "        sudo apt install php-sqlite3 php-curl${R}\n"
  exit 1
fi

PDO_DRIVERS=$(php -r "echo implode(',', PDO::getAvailableDrivers());" 2>/dev/null)
if echo "$PDO_DRIVERS" | grep -qi "sqlite"; then
  printf "        ${G}✓${R} PDO SQLite driver\n"
else
  printf "        ${RED}✗${R} PDO SQLite driver not available (drivers: %s)\n" "${PDO_DRIVERS:-none}"
  printf "        ${D}Install: sudo apt install php-sqlite3${R}\n"
  exit 1
fi

# ── Validate JWT_SECRET (don't silently accept insecure default) ──
export JWT_SECRET="${JWT_SECRET:-}"
if [ -z "$JWT_SECRET" ] || [ "$JWT_SECRET" = "dev-insecure-please-change-me" ]; then
  if [ "${DEV_MODE:-}" = "1" ]; then
    export JWT_SECRET="dev-insecure-please-change-me"
    printf "        ${Y}⚠${R} JWT_SECRET is insecure default (DEV_MODE=1)\n"
  else
    printf "        ${RED}✗${R} JWT_SECRET is missing or still the insecure default\n"
    printf "        ${D}Set a strong secret in .env, or set DEV_MODE=1 for local dev:${R}\n"
    printf "        ${D}  echo 'JWT_SECRET=\$(python3 -c \"import secrets; print(secrets.token_urlsafe(48))\")' >> .env${R}\n"
    printf "        ${D}  — or —${R}\n"
    printf "        ${D}  echo 'DEV_MODE=1' >> .env  (accepts insecure default)${R}\n"
    exit 1
  fi
else
  printf "        ${G}✓${R} JWT_SECRET is set (not the default)\n"
fi

# ═══════════════════════════════════════════════════════════
# Step 2: Sync Python environment
# ═══════════════════════════════════════════════════════════
printf "\n  ${B}[2/5]${R} ${D}Syncing Python environment${R}\n"

UV_OUTPUT=$(uv sync 2>&1)
UV_EXIT=$?
if [ $UV_EXIT -eq 0 ]; then
  printf "        ${G}✓${R} Dependencies ready\n"
else
  printf "        ${RED}✗${R} uv sync failed\n"
  printf "        %s\n" "$UV_OUTPUT" | head -5 | sed 's/^/        /'
  exit 1
fi

# ═══════════════════════════════════════════════════════════
# Step 3: Reset database (delegate to reset-db.sh)
# ═══════════════════════════════════════════════════════════
mkdir -p "$PID_DIR"
mkdir -p "$LOG_DIR"

if [ $RESET -eq 1 ]; then
  # Stop services first, then delegate to reset-db.sh
  if [ -f "$PROJECT_ROOT/stop.sh" ]; then
    bash "$PROJECT_ROOT/stop.sh" 2>/dev/null
  fi
  # reset-db.sh will handle backup + drop + recreate
  bash "$PROJECT_ROOT/reset-db.sh" --force
  if [ $? -ne 0 ]; then
    printf "        ${RED}✗${R} Database reset failed\n"
    exit 1
  fi
fi

# ═══════════════════════════════════════════════════════════
# Step 4: Initialize database (if not already present)
# ═══════════════════════════════════════════════════════════
printf "\n  ${B}[3/5]${R} ${D}Checking database${R}\n"

mkdir -p "$PROJECT_ROOT/data"
mkdir -p "$PROJECT_ROOT/data/uploads"

if [ ! -f "$DB_PATH" ]; then
  printf "        ${D}Initializing SQLite database...${R}\n"
  uv run python init_db.py 2>/dev/null
  if [ $? -eq 0 ]; then
    printf "        ${G}✓${R} SQLite database created\n"
  else
    printf "        ${RED}✗${R} Database initialization failed\n"
    exit 1
  fi
else
  if uv run python3 -c "import sqlite3; sqlite3.connect('$DB_PATH').execute('SELECT 1')" &>/dev/null; then
    printf "        ${G}✓${R} SQLite ready\n"
  else
    printf "        ${RED}✗${R} Database file exists but is corrupt\n"
    printf "        ${D}Run: ./start.sh --reset${R}\n"
    exit 1
  fi
fi

# ═══════════════════════════════════════════════════════════
# Step 5: Start services
# ═══════════════════════════════════════════════════════════
printf "\n  ${B}[4/5]${R} ${D}Starting services${R}\n"

# ── Stop any previous instance (graceful) ──────────────────
for pidfile in flask.pid php.pid; do
  if [ -f "$PID_DIR/$pidfile" ]; then
    OLD_PID=$(cat "$PID_DIR/$pidfile" 2>/dev/null)
    if kill -0 "$OLD_PID" 2>/dev/null; then
      kill -TERM "$OLD_PID" 2>/dev/null
      # Wait up to 3s for graceful exit
      w=0
      while [ $w -lt 30 ] && kill -0 "$OLD_PID" 2>/dev/null; do
        sleep 0.1
        w=$((w + 1))
      done
      # If still alive, force kill
      if kill -0 "$OLD_PID" 2>/dev/null; then
        kill -KILL "$OLD_PID" 2>/dev/null
      fi
    fi
    rm -f "$PID_DIR/$pidfile"
  fi
done

# ── Start Flask API ─────────────────────────────────────────
export JWT_TTL_SECONDS="${JWT_TTL_SECONDS:-3600}"
export RATE_LIMIT_PER_MINUTE="${RATE_LIMIT_PER_MINUTE:-30}"
export LOG_LEVEL="${LOG_LEVEL:-WARNING}"
export DATABASE_URL="sqlite:///$DB_PATH"
export FLASK_API_URL="http://$FLASK_HOST:$FLASK_PORT"

uv run gunicorn \
  --bind "$FLASK_HOST:$FLASK_PORT" \
  --workers 1 \
  --timeout 30 \
  --log-level warning \
  --chdir "$PROJECT_ROOT/flask-api" \
  app:app \
  >> "$LOG_DIR/flask.log" 2>&1 &
FLASK_PID=$!
echo "$FLASK_PID" > "$PID_DIR/flask.pid"

# Wait for Flask to pass HTTP readiness check
FLASK_READY=0
i=0
while [ $i -lt 15 ]; do
  HEALTH_STATUS=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "http://$FLASK_HOST:$FLASK_PORT/api/health/live" 2>/dev/null)
  if [ "$HEALTH_STATUS" = "200" ]; then
    FLASK_READY=1
    break
  fi
  sleep 0.5
  i=$((i + 1))
done

if [ $FLASK_READY -eq 1 ]; then
  printf "        ${G}✓${R} Flask API → %s:%s\n" "$FLASK_HOST" "$FLASK_PORT"
else
  if kill -0 "$FLASK_PID" 2>/dev/null; then
    kill -TERM "$FLASK_PID" 2>/dev/null
  fi
  printf "        ${RED}✗${R} Flask failed readiness check\n"
  printf "        ${D}Last log lines:${R}\n"
  tail -5 "$LOG_DIR/flask.log" 2>/dev/null | sed 's/^/          /'
  rm -f "$PID_DIR/flask.pid"
  exit 1
fi

# ── Start PHP built-in server ───────────────────────────────
export DB_DRIVER=sqlite
export SQLITE_PATH="$DB_PATH"
export FLASK_API_URL="http://$FLASK_HOST:$FLASK_PORT"
export UPLOAD_DIR="$PROJECT_ROOT/data/uploads"

php -S "$PHP_HOST:$PHP_PORT" -t "$PROJECT_ROOT/php-app/public" \
  >> "$LOG_DIR/php.log" 2>&1 &
PHP_PID=$!
echo "$PHP_PID" > "$PID_DIR/php.pid"

# Wait for PHP to pass HTTP readiness check
PHP_READY=0
i=0
while [ $i -lt 10 ]; do
  PHP_STATUS=$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "http://$PHP_HOST:$PHP_PORT/" 2>/dev/null)
  if [ "$PHP_STATUS" = "200" ] || [ "$PHP_STATUS" = "302" ]; then
    PHP_READY=1
    break
  fi
  sleep 0.5
  i=$((i + 1))
done

if [ $PHP_READY -eq 1 ]; then
  printf "        ${G}✓${R} PHP frontend → %s:%s\n" "$PHP_HOST" "$PHP_PORT"
else
  if kill -0 "$PHP_PID" 2>/dev/null; then
    kill -TERM "$PHP_PID" 2>/dev/null
  fi
  printf "        ${RED}✗${R} PHP failed readiness check\n"
  printf "        ${D}Last log lines:${R}\n"
  tail -5 "$LOG_DIR/php.log" 2>/dev/null | sed 's/^/          /'
  kill -TERM "$FLASK_PID" 2>/dev/null
  rm -f "$PID_DIR/flask.pid" "$PID_DIR/php.pid"
  exit 1
fi

# ═══════════════════════════════════════════════════════════
# Step 6: Verify
# ═══════════════════════════════════════════════════════════
printf "\n  ${B}[5/5]${R} ${D}Verifying${R}\n"

HEALTH=$(curl -s "http://$FLASK_HOST:$FLASK_PORT/api/health" 2>/dev/null)
if echo "$HEALTH" | grep -q '"ok"'; then
  printf "        ${G}✓${R} API healthy\n"
else
  printf "        ${Y}⚠${R} API started but DB may still be initializing\n"
fi

# ═══════════════════════════════════════════════════════════
# Ready
# ═══════════════════════════════════════════════════════════
printf "\n"
printf "  ${GR}──────────────────────────────${R}\n"
printf "  ${G}READY${R}\n"
printf "  ${B}http://%s:%s${R}\n" "$PHP_HOST" "$PHP_PORT"
printf "  ${GR}──────────────────────────────${R}\n"
printf "\n"
printf "  ${D}API:${R}      http://%s:%s/api/health\n" "$FLASK_HOST" "$FLASK_PORT"
printf "  ${D}Logs:${R}     %s/\n" "$LOG_DIR"
printf "  ${D}Stop:${R}     ./stop.sh  or Ctrl+C\n"
printf "  ${D}Reset DB:${R} ./start.sh --reset\n"
printf "\n"

# ── Wait for Ctrl+C (graceful shutdown) ───────────────────
cleanup() {
  printf "\n\n  ${D}Stopping FacultyIS...${R}\n"
  for pidfile in flask.pid php.pid; do
    if [ -f "$PID_DIR/$pidfile" ]; then
      PID=$(cat "$PID_DIR/$pidfile" 2>/dev/null)
      if kill -0 "$PID" 2>/dev/null; then
        kill -TERM "$PID" 2>/dev/null
        # Wait up to 2s for graceful exit
        w=0
        while [ $w -lt 20 ] && kill -0 "$PID" 2>/dev/null; do
          sleep 0.1
          w=$((w + 1))
        done
        if kill -0 "$PID" 2>/dev/null; then
          kill -KILL "$PID" 2>/dev/null
        fi
      fi
    fi
  done
  rm -f "$PID_DIR/flask.pid" "$PID_DIR/php.pid"
  printf "  ${G}✓${R} Stopped\n\n"
  exit 0
}
trap cleanup SIGINT SIGTERM

wait "$FLASK_PID" "$PHP_PID" 2>/dev/null
cleanup
