#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_NAME="$(basename "$PLUGIN_DIR")"
ENV_FILE="$PLUGIN_DIR/.env"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "error: .env not found. Copy .env.example and fill it in." >&2
  exit 1
fi

# shellcheck source=../.env
source "$ENV_FILE"

: "${DEV_SSH_HOST:?DEV_SSH_HOST is not set in .env}"
: "${DEV_WP_PATH:?DEV_WP_PATH is not set in .env}"

REMOTE_PLUGIN_PATH="${DEV_WP_PATH%/}/wp-content/plugins/${PLUGIN_NAME}"

# Build ssh options array
SSH_OPTS=(-o StrictHostKeyChecking=accept-new)
[[ -n "${DEV_SSH_PORT:-}" ]] && SSH_OPTS+=(-p "$DEV_SSH_PORT")
[[ -n "${DEV_SSH_KEY:-}"  ]] && SSH_OPTS+=(-i "$DEV_SSH_KEY")

RSYNC_OPTS=(-az)
[[ "${DEV_RSYNC_DELETE:-false}" == "true" ]] && RSYNC_OPTS+=(--delete)
[[ -n "${DEV_LINUX_USER:-}" ]]               && RSYNC_OPTS+=(--chown="${DEV_LINUX_USER}:${DEV_LINUX_USER}")

RSYNC_EXCLUDES=(
  --exclude='.git/'
  --exclude='.direnv/'
  --exclude='.env'
  --exclude='vendor/'
  --exclude='node_modules/'
  --exclude='bin/'
)

do_sync() {
  rsync "${RSYNC_OPTS[@]}" \
    "${RSYNC_EXCLUDES[@]}" \
    -e "ssh ${SSH_OPTS[*]}" \
    "$PLUGIN_DIR/" \
    "${DEV_SSH_HOST}:${REMOTE_PLUGIN_PATH}/"
}

# When called by watchexec (--sync-only flag), just sync and exit
if [[ "${1:-}" == "--sync-only" ]]; then
  echo "[$(date '+%H:%M:%S')] syncing…"
  do_sync
  echo "[$(date '+%H:%M:%S')] done"
  exit 0
fi

echo "Initial sync → ${DEV_SSH_HOST}:${REMOTE_PLUGIN_PATH}"
do_sync
echo "Watching for changes (Ctrl-C to stop)…"

watchexec \
  --watch "$PLUGIN_DIR" \
  --ignore '.git/**' \
  --ignore '.direnv/**' \
  --ignore '.env' \
  --ignore 'vendor/**' \
  --ignore 'node_modules/**' \
  --ignore 'bin/**' \
  -- "$SCRIPT_DIR/sync-dev.sh" --sync-only
