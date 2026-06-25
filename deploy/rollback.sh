#!/usr/bin/env bash
# rollback.sh — restore server files from a backup made by deploy.sh
#
# Usage:
#   ./deploy/rollback.sh <TIMESTAMP>   — rollback to state before this deploy
#   ./deploy/rollback.sh               — list available backups

set -euo pipefail

# ── Require env vars ───────────────────────────────────────────────────────
if [[ -z "${WHMCS_SSH_HOST:-}" ]]; then
    echo "Error: WHMCS_SSH_HOST is not set." >&2
    exit 1
fi
if [[ -z "${WHMCS_ROOT:-}" ]]; then
    echo "Error: WHMCS_ROOT is not set." >&2
    exit 1
fi

SSH_HOST="${WHMCS_SSH_HOST}"
REMOTE_ROOT="${WHMCS_ROOT}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG_FILE="${SCRIPT_DIR}/deployments.log"
LAST_DEPLOYED_FILE="${SCRIPT_DIR}/.last_deployed"

# ── SSH ControlMaster ──────────────────────────────────────────────────────
SSH_CONTROL="/tmp/whmcs-rollback-ctl-$$"
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CONTROL}" -o "ControlPersist=60")

cleanup() {
    ssh -O exit -o "ControlPath=${SSH_CONTROL}" "${SSH_HOST}" 2>/dev/null || true
}
trap cleanup EXIT

# ── List available deployments ─────────────────────────────────────────────
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

# ── Validate timestamp format — prevents regex injection in grep ───────────
if [[ ! "${TIMESTAMP}" =~ ^[0-9]{8}-[0-9]{6}$ ]]; then
    echo "Error: invalid timestamp format '${TIMESTAMP}'. Expected: YYYYMMDD-HHMMSS" >&2
    exit 1
fi

# ── Validate timestamp exists in log ──────────────────────────────────────
# Use grep -F (fixed string) to prevent any BRE interpretation of the timestamp.
LOG_LINE=$(grep -F "${TIMESTAMP} |" "${LOG_FILE}" 2>/dev/null | head -1 || true)
if [ -z "${LOG_LINE}" ]; then
    echo "Error: timestamp '${TIMESTAMP}' not found in deployments.log" >&2
    exit 1
fi

BACKUP_DIR=$(echo "${LOG_LINE}" | awk -F' | ' '{print $3}')
GIT_HASH=$(echo "${LOG_LINE}" | awk -F' | ' '{print $2}')

echo "╔══════════════════════════════════════════════╗"
echo "  Rollback to ${TIMESTAMP} (git:${GIT_HASH})"
echo "  Backup dir: ${BACKUP_DIR}"
echo "  Target:     ${SSH_HOST}:${REMOTE_ROOT}"
echo "╚══════════════════════════════════════════════╝"

# ── Confirm before touching the server ────────────────────────────────────
read -r -p "Restore server to state before this deploy? [y/N] " CONFIRM
if [[ "${CONFIRM}" != "y" && "${CONFIRM}" != "Y" ]]; then
    echo "Aborted."
    exit 0
fi

# ── Verify SSH ─────────────────────────────────────────────────────────────
echo -n "Checking SSH connectivity to ${SSH_HOST} ... "
if ! ssh "${SSH_OPTS[@]}" "${SSH_HOST}" exit 2>/dev/null; then
    echo "FAILED" >&2
    echo "Error: cannot reach ${SSH_HOST}." >&2
    exit 1
fi
echo "ok"

# ── Verify backup dir exists and is non-empty ─────────────────────────────
# BACKUP_DIR is stored as an absolute path (resolved at deploy time via $HOME).
# This avoids the ~ vs /home/user mismatch when find returns absolute paths.
BACKUP_FILES=$(ssh "${SSH_OPTS[@]}" "${SSH_HOST}" "find ${BACKUP_DIR} -type f 2>/dev/null" || true)

if [ -z "${BACKUP_FILES}" ]; then
    echo "Error: no files found in backup ${BACKUP_DIR}" >&2
    exit 1
fi

# ── Restore files ──────────────────────────────────────────────────────────
while IFS= read -r BACKUP_FILE; do
    # Strip the backup dir prefix to get the relative path.
    # BACKUP_DIR is absolute (e.g. /home/user/hostedai-backups/TS),
    # BACKUP_FILE is absolute (e.g. /home/user/hostedai-backups/TS/modules/.../file.php).
    # Stripping gives: modules/.../file.php
    RELATIVE="${BACKUP_FILE#${BACKUP_DIR}/}"
    if [[ "${RELATIVE}" == "${BACKUP_FILE}" ]]; then
        # Strip failed — prefix didn't match. Abort rather than cp to a wrong path.
        echo "Error: could not strip backup prefix from '${BACKUP_FILE}'" >&2
        echo "  Expected prefix: ${BACKUP_DIR}/" >&2
        exit 1
    fi
    REMOTE_FILE="${REMOTE_ROOT}/${RELATIVE}"
    echo -n "  restore ${RELATIVE} ... "
    ssh "${SSH_OPTS[@]}" "${SSH_HOST}" "sudo cp '${BACKUP_FILE}' '${REMOTE_FILE}' && echo ok"
done <<< "${BACKUP_FILES}"

# ── Restore .last_deployed to the hash deployed before this rollback ───────
# Find the line number of the rolled-back deploy, take the hash from the
# line before it (= what was live prior to this deploy).
LINE_NUM=$(grep -n "^${TIMESTAMP}" "${LOG_FILE}" | cut -d: -f1)
PREV_LINE=$((LINE_NUM - 1))
if [ "${PREV_LINE}" -gt 0 ]; then
    PREV_HASH=$(sed -n "${PREV_LINE}p" "${LOG_FILE}" | awk -F' | ' '{print $2}')
    echo "${PREV_HASH}" > "${LAST_DEPLOYED_FILE}"
    echo "Restored .last_deployed to git:${PREV_HASH}"
else
    rm -f "${LAST_DEPLOYED_FILE}"
    echo "Removed .last_deployed (this was the first recorded deploy)"
fi

echo ""
echo "Rollback complete. Server restored to state before ${TIMESTAMP}."
