#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# FacultyIS v2.12 — Reset Database (local dev)
# ═══════════════════════════════════════════════════════════
# Drops all FacultyIS tables and recreates with seed data.
# Safe: creates a consistent SQLite backup before destroying anything.
#
#   ./reset-db.sh              — interactive (asks for confirmation)
#   ./reset-db.sh --force      — skip confirmation (for scripts)
#   ./reset-db.sh --keep-backup — only show backup location, don't reset
# ═══════════════════════════════════════════════════════════
set -uo pipefail

R='\033[0m'; B='\033[1m'; D='\033[2m'; G='\033[32m'; Y='\033[33m'; RED='\033[31m'; GR='\033[90m'

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_ROOT"

DB_PATH="$PROJECT_ROOT/data/facultyis.sqlite3"
BACKUP_DIR="$PROJECT_ROOT/data/backups"
PID_DIR="$PROJECT_ROOT/.pids"

FORCE=0
for arg in "$@"; do
  case "$arg" in
    --force|-f) FORCE=1 ;;
    --keep-backup) FORCE=0 ;;
  esac
done

printf "\n  ${B}FacultyIS — Reset Database${R}\n"
printf "  ${GR}──────────────────────────────${R}\n\n"

# ── Require sqlite3 CLI for consistent backup ──────────────
# The .backup API captures WAL data; a raw cp fallback does not.
if ! command -v sqlite3 &>/dev/null; then
  printf "  ${RED}✗${R} sqlite3 CLI is required for safe backup/reset.\n"
  printf "  ${D}Install: sudo apt install sqlite3 (or equivalent)${R}\n"
  printf "  ${D}The .backup API captures WAL data; raw cp does not.${R}\n\n"
  exit 1
fi

# ── Stop FacultyIS services first ──────────────────────────
# A running Flask/PHP process with an open WAL handle will
# corrupt the database if we drop tables underneath it.
STOPPED=0
for pidfile in flask.pid php.pid; do
  if [ -f "$PID_DIR/$pidfile" ]; then
    PID=$(cat "$PID_DIR/$pidfile" 2>/dev/null)
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
      printf "  ${Y}⚠${R} Stopping running service (PID %s)...${R}\n" "$PID"
      kill -TERM "$PID" 2>/dev/null
      # Wait up to 3s for graceful exit
      w=0
      while [ $w -lt 30 ] && kill -0 "$PID" 2>/dev/null; do
        sleep 0.1
        w=$((w + 1))
      done
      if kill -0 "$PID" 2>/dev/null; then
        kill -KILL "$PID" 2>/dev/null
      fi
      STOPPED=1
    fi
    rm -f "$PID_DIR/$pidfile"
  fi
done
if [ $STOPPED -eq 1 ]; then
  printf "  ${G}✓${R} Services stopped\n\n"
fi

# ── Check database exists ───────────────────────────────────
if [ ! -f "$DB_PATH" ]; then
  printf "  ${D}No database file found at:${R}\n"
  printf "  ${D}%s${R}\n" "$DB_PATH"
  printf "\n  ${G}Run ./start.sh instead — it will create a fresh database.${R}\n\n"
  exit 0
fi

# ── Show current state ──────────────────────────────────────
TABLES=$(sqlite3 "$DB_PATH" "SELECT COUNT(*) FROM sqlite_master WHERE type='table'" 2>/dev/null || echo "0")
SIZE=$(du -h "$DB_PATH" 2>/dev/null | cut -f1)
printf "  Current database: ${B}%s${R} (${GR}%s tables${R}, %s)\n\n" "$DB_PATH" "$TABLES" "$SIZE"

# ── Create consistent backup using SQLite backup API ────────
# This ensures WAL data is included and the backup is a
# consistent snapshot, unlike a raw file copy.
mkdir -p "$BACKUP_DIR"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/facultyis_${TIMESTAMP}.sqlite3"

sqlite3 "$DB_PATH" ".backup '$BACKUP_FILE'" 2>/dev/null
if [ $? -ne 0 ] || [ ! -f "$BACKUP_FILE" ]; then
  printf "  ${RED}✗${R} SQLite .backup failed — cannot create safe backup\n"
  printf "  ${D}Refusing to reset without a consistent backup.${R}\n\n"
  exit 1
fi

BACKUP_SIZE=$(du -h "$BACKUP_FILE" 2>/dev/null | cut -f1)
printf "  ${G}✓${R} Backup created: ${D}%s${R} (%s)\n" "$BACKUP_FILE" "$BACKUP_SIZE"

# ── Confirmation ────────────────────────────────────────────
if [ $FORCE -eq 0 ]; then
  printf "\n  ${RED}⚠  This will DELETE all data and recreate with seed data.${R}\n"
  printf "  ${Y}Type 'RESET' to confirm:${R} "
  read -r CONFIRM
  if [ "$CONFIRM" != "RESET" ]; then
    printf "\n  ${D}Cancelled. Your data is safe.${R}\n"
    printf "  ${D}Backup is still available at: %s${R}\n\n" "$BACKUP_FILE"
    exit 0
  fi
fi

# ── Drop all tables ─────────────────────────────────────────
printf "\n  ${D}Dropping all tables...${R}\n"

TABLE_NAMES=$(sqlite3 "$DB_PATH" "SELECT GROUP_CONCAT(name) FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" 2>/dev/null)

if [ -n "$TABLE_NAMES" ]; then
  DROP_SQL=""
  IFS=',' read -ra TBL_ARRAY <<< "$TABLE_NAMES"
  for tbl in "${TBL_ARRAY[@]}"; do
    DROP_SQL="${DROP_SQL}DROP TABLE IF EXISTS \"${tbl}\"; "
  done
  sqlite3 "$DB_PATH" "$DROP_SQL" 2>/dev/null
  printf "  ${G}✓${R} Dropped %d tables\n" "${#TBL_ARRAY[@]}"
fi

sqlite3 "$DB_PATH" "SELECT 'DROP INDEX IF EXISTS \"' || name || '\";' FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_%'" 2>/dev/null | sqlite3 "$DB_PATH" 2>/dev/null

# ── Recreate with schema + seed ─────────────────────────────
printf "  ${D}Recreating schema...${R}\n"

if command -v uv &>/dev/null; then
  uv run python init_db.py 2>/dev/null
else
  python3 init_db.py 2>/dev/null
fi

if [ $? -eq 0 ]; then
  NEW_TABLES=$(sqlite3 "$DB_PATH" "SELECT COUNT(*) FROM sqlite_master WHERE type='table'" 2>/dev/null)
  printf "  ${G}✓${R} Database recreated (${NEW_TABLES} tables)\n"
else
  printf "  ${RED}✗${R} Failed to recreate database\n"
  printf "  ${Y}Restore from backup: cp '%s' '%s'${R}\n" "$BACKUP_FILE" "$DB_PATH"
  exit 1
fi

# ── Verify integrity ───────────────────────────────────────
INTEGRITY=$(sqlite3 "$DB_PATH" "PRAGMA integrity_check" 2>/dev/null)
if [ "$INTEGRITY" = "ok" ]; then
  printf "  ${G}✓${R} Database integrity verified\n"
else
  printf "  ${RED}✗${R} Integrity check failed: %s\n" "$INTEGRITY"
  printf "  ${Y}Restore from backup: cp '%s' '%s'${R}\n" "$BACKUP_FILE" "$DB_PATH"
  exit 1
fi

# ── Done ────────────────────────────────────────────────────
printf "\n"
printf "  ${GR}──────────────────────────────${R}\n"
printf "  ${G}✓ Database reset complete${R}\n"
printf "  ${GR}──────────────────────────────${R}\n"
printf "  ${D}Backup: %s${R}\n" "$BACKUP_FILE"
printf "\n"
