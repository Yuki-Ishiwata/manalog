#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG_FILE="${PROJECT_ROOT}/deploy.config.local"

usage() {
  cat <<'EOF'
Usage: ./deploy.sh [--dry-run | --apply]

Copy deploy.config.example to deploy.config.local and set the ManaLog SSH
destination first. --dry-run shows the planned transfers without changing
the server. --apply transfers files. The default is --dry-run.
EOF
}

MODE="${1:---dry-run}"
case "$MODE" in
  --dry-run|--apply) ;;
  -h|--help) usage; exit 0 ;;
  *) usage >&2; exit 2 ;;
esac
if (( $# > 1 )); then usage >&2; exit 2; fi

if [[ ! -f "$CONFIG_FILE" ]]; then
  echo "Missing deploy.config.local. Copy deploy.config.example and fill in the destination." >&2
  exit 1
fi
# This file stays on the developer's machine and is excluded from Git.
source "$CONFIG_FILE"

: "${DEPLOY_SSH_HOST:?Set DEPLOY_SSH_HOST in deploy.config.local}"
: "${DEPLOY_SSH_USER:?Set DEPLOY_SSH_USER in deploy.config.local}"
: "${DEPLOY_SSH_PORT:?Set DEPLOY_SSH_PORT in deploy.config.local}"
: "${DEPLOY_SSH_KEY:?Set DEPLOY_SSH_KEY in deploy.config.local}"
: "${DEPLOY_ROOT:?Set DEPLOY_ROOT in deploy.config.local}"

[[ "$DEPLOY_SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || { echo 'Invalid DEPLOY_SSH_HOST.' >&2; exit 1; }
[[ "$DEPLOY_SSH_USER" =~ ^[A-Za-z0-9_-]+$ ]] || { echo 'Invalid DEPLOY_SSH_USER.' >&2; exit 1; }
[[ "$DEPLOY_SSH_PORT" =~ ^[0-9]+$ ]] || { echo 'Invalid DEPLOY_SSH_PORT.' >&2; exit 1; }
[[ "$DEPLOY_ROOT" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo 'DEPLOY_ROOT must be a simple absolute path.' >&2; exit 1; }
case "/${DEPLOY_ROOT#/}/" in
  *'/../'*|*'/./'*) echo 'DEPLOY_ROOT cannot contain . or .. path segments.' >&2; exit 1 ;;
esac
[[ "$DEPLOY_ROOT" != '/' ]] || { echo 'DEPLOY_ROOT cannot be /.' >&2; exit 1; }
[[ -f "$DEPLOY_SSH_KEY" ]] || { echo 'SSH key file not found.' >&2; exit 1; }

SSH_TARGET="${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}"
SSH_ARGS=(-p "$DEPLOY_SSH_PORT" -i "$DEPLOY_SSH_KEY" -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10)
RSYNC_SSH="$(printf '%q ' ssh "${SSH_ARGS[@]}")"
RSYNC_ARGS=(-avz --itemize-changes --exclude='.DS_Store' --exclude='.env*' --exclude='*.local.php' --exclude='*.log')

if [[ "$MODE" == '--dry-run' ]]; then
  RSYNC_ARGS+=(--dry-run)
  echo "Dry run: ${SSH_TARGET}:${DEPLOY_ROOT}/"
  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "test -d '${DEPLOY_ROOT}'" || {
    echo 'The remote app directory does not exist yet. Create it or run --apply after confirming DEPLOY_ROOT.' >&2
    exit 1
  }
else
  echo "Deploying to: ${SSH_TARGET}:${DEPLOY_ROOT}/"
  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "mkdir -p '${DEPLOY_ROOT}/public' '${DEPLOY_ROOT}/src' '${DEPLOY_ROOT}/database/migrations' '${DEPLOY_ROOT}/storage/uploads' '${DEPLOY_ROOT}/storage/logs'"
fi

for PART in public src database/migrations; do
  echo "Syncing ${PART}/"
  rsync "${RSYNC_ARGS[@]}" -e "$RSYNC_SSH" \
    "${PROJECT_ROOT}/${PART}/" \
    "${SSH_TARGET}:${DEPLOY_ROOT}/${PART}/"
done

if [[ "$MODE" == '--apply' ]]; then
  echo 'Files transferred. Configure the subdomain document root to DEPLOY_ROOT/public.'
  echo 'Create the server-side DEPLOY_ROOT/.env separately; this script never transfers it.'
  echo 'Apply SQL migrations separately after reviewing them.'
fi
