#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════
# FacultyIS v2.16 — Release Packaging
# ═══════════════════════════════════════════════════════════
# Creates a clean release archive excluding development-only
# artifacts (.git, .venv, data, logs, .pids, .env).
#
#   ./package.sh                    — creates FacultyIS-v2.16.tar.gz
#   ./package.sh --output /tmp/out  — custom output directory
# ═══════════════════════════════════════════════════════════
set -uo pipefail

R='\033[0m'; B='\033[1m'; D='\033[2m'; G='\033[32m'; Y='\033[33m'; RED='\033[31m'; GR='\033[90m'

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_ROOT"

# Read version from pyproject.toml
VERSION=$(grep '^version = ' pyproject.toml | head -1 | sed 's/version = "\(.*\)"/\1/')
if [ -z "$VERSION" ]; then
  printf "  ${RED}✗${R} Could not read version from pyproject.toml\n"
  exit 1
fi

# Strip any trailing .0 for the archive name (2.10.0 → 2.10)
ARCHIVE_VERSION="${VERSION%.0}"
ARCHIVE_NAME="FacultyIS-v${ARCHIVE_VERSION}"
OUTPUT_DIR="${PROJECT_ROOT}/.."

for arg in "$@"; do
  case "$arg" in
    --output|-o) shift; OUTPUT_DIR="$1" ;;
  esac
done

printf "\n  ${B}FacultyIS — Release Packaging${R}\n"
printf "  ${GR}──────────────────────────────${R}\n\n"
printf "  Version:  ${B}%s${R}\n" "$VERSION"
printf "  Archive:  ${B}%s.tar.gz${R}\n" "$ARCHIVE_NAME"

# ── Exclusions ─────────────────────────────────────────────
# These are development-only artifacts that should never be
# included in a production release archive.
EXCLUDES=(
  .git
  .gitignore
  .venv
  .pids
  data
  logs
  .env
  '*.sqlite3'
  '__pycache__'
  '.ruff_cache'
  # Note: uv.lock is kept — it's needed for reproducible dependency installation
)

# Build tar --exclude flags
EXCLUDE_FLAGS=()
for excl in "${EXCLUDES[@]}"; do
  EXCLUDE_FLAGS+=(--exclude="$excl")
done

# ── Create archive ─────────────────────────────────────────
# Ensure output directory exists
mkdir -p "$OUTPUT_DIR"

OUTPUT_PATH="${OUTPUT_DIR}/${ARCHIVE_NAME}.tar.gz"

printf "\n  ${D}Excluding from archive:${R}\n"
for excl in "${EXCLUDES[@]}"; do
  printf "    ${GR}·${R} %s\n" "$excl"
done

printf "\n  ${D}Creating archive...${R}\n"

# Use -C with . to make exclusions match relative paths unambiguously.
# This prevents .venv from leaking into the archive when it exists
# as a direct child of the project root.
tar czf "$OUTPUT_PATH" \
  "${EXCLUDE_FLAGS[@]}" \
  -C "$PROJECT_ROOT" \
  .

if [ $? -eq 0 ]; then
  SIZE=$(du -h "$OUTPUT_PATH" 2>/dev/null | cut -f1)
  printf "  ${G}✓${R} Archive created: ${B}%s${R} (%s)\n" "$OUTPUT_PATH" "$SIZE"

  # ── Verify archive contents ──────────────────────────────
  printf "\n  ${D}Verifying archive (no .git or .venv)...${R}\n"

  # Robust verification: match forbidden artifacts at any path position.
  # Uses a single pass with an alternation regex for efficiency.
  FORBIDDEN=$(tar tzf "$OUTPUT_PATH" | grep -Eq '(^|/)(\.venv|\.git|\.env|data|logs|__pycache__)(/|$)' && echo yes || echo no)
  if [ "$FORBIDDEN" = 'yes' ]; then
    printf "  ${RED}✗${R} Archive contains forbidden release artifact (.venv, .git, .env, data, logs, or __pycache__)!\n"
    # Show what matched for debugging
    tar tzf "$OUTPUT_PATH" | grep -E '(^|/)(\.venv|\.git|\.env|data|logs|__pycache__)(/|$)' | head -5 | sed 's/^/    /'
    exit 1
  else
    printf "  ${G}✓${R} No forbidden artifacts (.venv, .git, .env, data, logs, __pycache__)\n"
  fi

  # Count files in archive
  ARCHIVE_COUNT=$(tar tzf "$OUTPUT_PATH" | wc -l)
  printf "  ${G}✓${R} %s entries in archive\n" "$ARCHIVE_COUNT"

else
  printf "  ${RED}✗${R} Failed to create archive\n"
  exit 1
fi

printf "\n  ${GR}──────────────────────────────${R}\n"
printf "  ${G}✓${R} Release archive ready\n"
printf "  ${D}%s${R}\n\n" "$OUTPUT_PATH"
