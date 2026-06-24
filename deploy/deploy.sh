#!/usr/bin/env bash
# deploy.sh — deploy hostedai module to WHMCS sandbox with backup for rollback
#
# Usage:
#   ./deploy/deploy.sh                  — deploy all files changed in HEAD vs previous deploy
#   ./deploy/deploy.sh <file> [<file>]  — deploy specific files
#
# Backups are stored in ~/hostedai-backups/<TIMESTAMP>/ on the server.
# Every deploy is logged in deploy/deployments.log.
# Rollback: ./deploy/rollback.sh <TIMESTAMP>

set -euo pipefail

SSH_HOST="${WHMCS_SSH_HOST:-volodymyr.lukashkin@whmcs.sandbox1.hostedai.dev}"
REMOTE_ROOT="${WHMCS_ROOT:-/var/www/vhosts/whmcs.sandbox1.hostedai.dev/httpdocs}"
REMOTE_BACKUP_BASE="~/hostedai-backups"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
LOG_FILE="${SCRIPT_DIR}/deployments.log"
LAST_DEPLOYED_FILE="${SCRIPT_DIR}/.last_deployed"

cd "${REPO_ROOT}"

TIMESTAMP=$(date +%Y%m%d-%H%M%S)
GIT_HASH=$(git rev-parse --short HEAD)
BACKUP_DIR="${REMOTE_BACKUP_BASE}/${TIMESTAMP}"

# Determine files to deploy
if [ "$#" -gt 0 ]; then
    FILES=("$@")
else
    FILES=()
    if [ -f "${LAST_DEPLOYED_FILE}" ]; then
        LAST_HASH=$(cat "${LAST_DEPLOYED_FILE}")
        while IFS= read -r line; do [ -n "$line" ] && FILES+=("$line"); done \
            < <(git diff --name-only "${LAST_HASH}" HEAD -- '*.php' '*.tpl' '*.css' '*.js' 2>/dev/null || true)
    else
        # First deploy — take everything tracked
        while IFS= read -r line; do [ -n "$line" ] && FILES+=("$line"); done \
            < <(git ls-files -- '*.php' '*.tpl' '*.css' '*.js')
    fi
fi

if [ "${#FILES[@]}" -eq 0 ]; then
    echo "Nothing to deploy (no changes since last deploy)."
    exit 0
fi

echo "╔══════════════════════════════════════════════╗"
echo "  Deploy ${TIMESTAMP} (${GIT_HASH})"
echo "  Files: ${#FILES[@]}"
echo "╚══════════════════════════════════════════════╝"

# Create backup dir on server
ssh "${SSH_HOST}" "mkdir -p ${BACKUP_DIR}"

TMP_DIR="~/hostedai-deploy-tmp/${TIMESTAMP}"
ssh "${SSH_HOST}" "mkdir -p ${TMP_DIR}"

DEPLOYED=()
for FILE in "${FILES[@]}"; do
    if [ ! -f "${FILE}" ]; then
        echo "  SKIP   ${FILE} (not found locally)"
        continue
    fi

    REMOTE_FILE="${REMOTE_ROOT}/${FILE}"
    BACKUP_FILE="${BACKUP_DIR}/${FILE}"
    TMP_FILE="${TMP_DIR}/${FILE}"

    # Backup current server version
    echo -n "  backup ${FILE} ... "
    ssh "${SSH_HOST}" "mkdir -p \$(dirname ${BACKUP_FILE}) && sudo cp ${REMOTE_FILE} ${BACKUP_FILE} 2>/dev/null && echo ok || echo new"

    # SCP to tmp (user-writable), then sudo cp to target
    echo -n "  deploy ${FILE} ... "
    ssh "${SSH_HOST}" "mkdir -p \$(dirname ${TMP_FILE})"
    scp -q "${FILE}" "${SSH_HOST}:${TMP_FILE}"
    ssh "${SSH_HOST}" "sudo cp ${TMP_FILE} ${REMOTE_FILE} && echo ok"

    DEPLOYED+=("${FILE}")
done

# Cleanup tmp
ssh "${SSH_HOST}" "rm -rf ${TMP_DIR}" 2>/dev/null || true

# Write log entry
printf '%s | %s | %s | %s\n' \
    "${TIMESTAMP}" "${GIT_HASH}" "${BACKUP_DIR}" "${DEPLOYED[*]}" \
    >> "${LOG_FILE}"

# Update last deployed marker
echo "${GIT_HASH}" > "${LAST_DEPLOYED_FILE}"

echo ""
echo "Deployed ${#DEPLOYED[@]} file(s). Backup: ${BACKUP_DIR}"
echo "To rollback: ./deploy/rollback.sh ${TIMESTAMP}"
