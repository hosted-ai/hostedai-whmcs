#!/usr/bin/env bash
# rollback.sh — restore server files from a backup made by deploy.sh
#
# Usage:
#   ./deploy/rollback.sh <TIMESTAMP>
#   ./deploy/rollback.sh          — list available backups

set -euo pipefail

SSH_HOST="${WHMCS_SSH_HOST:-volodymyr.lukashkin@whmcs.sandbox1.hostedai.dev}"
REMOTE_ROOT="${WHMCS_ROOT:-/var/www/vhosts/whmcs.sandbox1.hostedai.dev/httpdocs}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/deployments.log"
LAST_DEPLOYED_FILE="${SCRIPT_DIR}/.last_deployed"

if [ "$#" -eq 0 ]; then
    echo "Available deployments (newest first):"
    echo ""
    if [ -f "${LOG_FILE}" ]; then
        tac "${LOG_FILE}" | awk -F' | ' '{printf "  %s  git:%s  files: %s\n", $1, $2, $4}'
    else
        echo "  (no deployments logged)"
    fi
    echo ""
    echo "Usage: $0 <TIMESTAMP>"
    exit 0
fi

TIMESTAMP="$1"

# Look up the backup dir from log
LOG_LINE=$(grep "^${TIMESTAMP}" "${LOG_FILE}" 2>/dev/null || true)
if [ -z "${LOG_LINE}" ]; then
    echo "Error: timestamp '${TIMESTAMP}' not found in deployments.log"
    exit 1
fi

BACKUP_DIR=$(echo "${LOG_LINE}" | awk -F' | ' '{print $3}')
GIT_HASH=$(echo "${LOG_LINE}" | awk -F' | ' '{print $2}')

echo "╔══════════════════════════════════════════════╗"
echo "  Rollback to ${TIMESTAMP} (git:${GIT_HASH})"
echo "  Backup dir: ${BACKUP_DIR}"
echo "╚══════════════════════════════════════════════╝"

# Find all backed-up files and restore them
BACKUP_FILES=$(ssh "${SSH_HOST}" "find ${BACKUP_DIR} -type f 2>/dev/null" || true)

if [ -z "${BACKUP_FILES}" ]; then
    echo "Error: no files found in backup ${BACKUP_DIR}"
    exit 1
fi

while IFS= read -r BACKUP_FILE; do
    RELATIVE="${BACKUP_FILE#${BACKUP_DIR}/}"
    REMOTE_FILE="${REMOTE_ROOT}/${RELATIVE}"
    echo -n "  restore ${RELATIVE} ... "
    ssh "${SSH_HOST}" "sudo cp ${BACKUP_FILE} ${REMOTE_FILE} && echo ok"
done <<< "${BACKUP_FILES}"

# Find previous deploy hash to restore .last_deployed
PREV_HASH=$(grep "^${TIMESTAMP}" "${LOG_FILE}" | awk -F' | ' '{print $2}')
# Get the deploy before this one
PREV_DEPLOY=$(grep -B1 "^${TIMESTAMP}" "${LOG_FILE}" | head -1)
PREV_GIT_HASH=$(echo "${PREV_DEPLOY}" | awk -F' | ' '{print $2}')

if [ -n "${PREV_GIT_HASH}" ] && [ "${PREV_GIT_HASH}" != "${PREV_HASH}" ]; then
    echo "${PREV_GIT_HASH}" > "${LAST_DEPLOYED_FILE}"
fi

echo ""
echo "Rollback complete. Server restored to state before ${TIMESTAMP}."
