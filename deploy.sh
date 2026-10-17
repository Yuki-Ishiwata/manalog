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
: "${DEPLOY_PUBLIC_ROOT:?Set DEPLOY_PUBLIC_ROOT in deploy.config.local}"

[[ "$DEPLOY_SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || { echo 'Invalid DEPLOY_SSH_HOST.' >&2; exit 1; }
[[ "$DEPLOY_SSH_USER" =~ ^[A-Za-z0-9_-]+$ ]] || { echo 'Invalid DEPLOY_SSH_USER.' >&2; exit 1; }
[[ "$DEPLOY_SSH_PORT" =~ ^[0-9]+$ ]] || { echo 'Invalid DEPLOY_SSH_PORT.' >&2; exit 1; }
[[ "$DEPLOY_ROOT" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo 'DEPLOY_ROOT must be a simple absolute path.' >&2; exit 1; }
[[ "$DEPLOY_PUBLIC_ROOT" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo 'DEPLOY_PUBLIC_ROOT must be a simple absolute path.' >&2; exit 1; }
DEPLOY_ENV_FILE="${DEPLOY_ENV_FILE:-${DEPLOY_ROOT%/}/.env}"
[[ "$DEPLOY_ENV_FILE" =~ ^/[A-Za-z0-9_./-]+$ ]] || { echo 'DEPLOY_ENV_FILE must be a simple absolute path.' >&2; exit 1; }
for ROOT in "$DEPLOY_ROOT" "$DEPLOY_PUBLIC_ROOT" "$DEPLOY_ENV_FILE"; do
case "/${ROOT#/}/" in
  *'/../'*|*'/./'*) echo 'Deployment paths cannot contain . or .. path segments.' >&2; exit 1 ;;
esac
[[ "$ROOT" != '/' ]] || { echo 'Deployment paths cannot be /.' >&2; exit 1; }
done
DEPLOY_ROOT="${DEPLOY_ROOT%/}"
DEPLOY_PUBLIC_ROOT="${DEPLOY_PUBLIC_ROOT%/}"
[[ "$DEPLOY_ROOT" != *'/public_html/'* && "$DEPLOY_ROOT" != *'/public_html' ]] || { echo 'DEPLOY_ROOT must be outside public_html.' >&2; exit 1; }
[[ "$DEPLOY_PUBLIC_ROOT" == *'/public_html/'* ]] || { echo 'DEPLOY_PUBLIC_ROOT must be inside public_html.' >&2; exit 1; }
[[ "$DEPLOY_ENV_FILE" != *'/public_html/'* ]] || { echo 'DEPLOY_ENV_FILE must be outside public_html.' >&2; exit 1; }
[[ -f "$DEPLOY_SSH_KEY" ]] || { echo 'SSH key file not found.' >&2; exit 1; }

SSH_TARGET="${DEPLOY_SSH_USER}@${DEPLOY_SSH_HOST}"
SSH_ARGS=(-p "$DEPLOY_SSH_PORT" -i "$DEPLOY_SSH_KEY" -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=10)
RSYNC_SSH="$(printf '%q ' ssh "${SSH_ARGS[@]}")"
RSYNC_ARGS=(-avz --itemize-changes --exclude='.DS_Store' --exclude='.env*' --exclude='*.local.php' --exclude='*.log')

if [[ "$MODE" == '--dry-run' ]]; then
  RSYNC_ARGS+=(--dry-run)
  echo "Dry run: ${SSH_TARGET}:${DEPLOY_ROOT}/ and ${DEPLOY_PUBLIC_ROOT}/"
  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "test -d '${DEPLOY_PUBLIC_ROOT}' && test -f '${DEPLOY_ENV_FILE}'" || {
    echo 'The subdomain document root or server-side .env does not exist yet.' >&2
    exit 1
  }
  FIRST_DEPLOY=false
  if ! ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "test -d '${DEPLOY_ROOT}'"; then
    FIRST_DEPLOY=true
    echo 'First deployment: the private app directory does not exist yet.'
  fi
else
  echo "Deploying app to: ${SSH_TARGET}:${DEPLOY_ROOT}/"
  echo "Deploying public files to: ${SSH_TARGET}:${DEPLOY_PUBLIC_ROOT}/"
  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "test -f '${DEPLOY_ENV_FILE}'" || {
    echo 'The server-side .env file does not exist at DEPLOY_ENV_FILE.' >&2
    exit 1
  }
  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "mkdir -p '${DEPLOY_ROOT}/src' '${DEPLOY_ROOT}/database/migrations' '${DEPLOY_ROOT}/storage/uploads' '${DEPLOY_ROOT}/storage/logs' '${DEPLOY_PUBLIC_ROOT}'"
  if [[ "$DEPLOY_ENV_FILE" != "$DEPLOY_ROOT/.env" ]]; then
    ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "if test -e '${DEPLOY_ROOT}/.env' || test -L '${DEPLOY_ROOT}/.env'; then test -L '${DEPLOY_ROOT}/.env' && test \"\$(readlink '${DEPLOY_ROOT}/.env')\" = '${DEPLOY_ENV_FILE}'; else ln -s '${DEPLOY_ENV_FILE}' '${DEPLOY_ROOT}/.env'; fi" || {
      echo 'Could not link DEPLOY_ROOT/.env to DEPLOY_ENV_FILE.' >&2
      exit 1
    }
  fi
fi

for PART in src database/migrations; do
  echo "Syncing ${PART}/"
  if [[ "$MODE" == '--dry-run' && "$FIRST_DEPLOY" == true ]]; then
    echo "Would create ${DEPLOY_ROOT}/${PART}/ and upload its files."
    rg --files --hidden "${PROJECT_ROOT}/${PART}" || true
  else
    rsync "${RSYNC_ARGS[@]}" -e "$RSYNC_SSH" \
      "${PROJECT_ROOT}/${PART}/" \
      "${SSH_TARGET}:${DEPLOY_ROOT}/${PART}/"
  fi
done
echo 'Syncing public/'
rsync "${RSYNC_ARGS[@]}" -e "$RSYNC_SSH" \
  "${PROJECT_ROOT}/public/" \
  "${SSH_TARGET}:${DEPLOY_PUBLIC_ROOT}/"

if [[ "$MODE" == '--apply' ]]; then
  ssh "${SSH_ARGS[@]}" "$SSH_TARGET" "printf '%s\n' '<?php return \"${DEPLOY_ROOT}\";' > '${DEPLOY_PUBLIC_ROOT}/app_root.local.php'"
  echo 'Files transferred. Keep the subdomain document root at DEPLOY_PUBLIC_ROOT.'
  echo 'The server-side .env is read from DEPLOY_ENV_FILE; this script never transfers it.'
  echo 'Apply SQL migrations separately after reviewing them.'
fi
