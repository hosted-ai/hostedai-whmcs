#!/usr/bin/env bash
# deploy.sh — deploy hostedai module to WHMCS with backup for rollback
#
# Usage:
#   ./deploy/deploy.sh                  — deploy all files changed since last deploy
#   ./deploy/deploy.sh <file> [<file>]  — deploy specific files
#
# Required env vars (set in shell or .env):
#   WHMCS_SSH_HOST  e.g. user@whmcs.example.com
#   WHMCS_ROOT      e.g. /var/www/vhosts/whmcs.example.com/httpdocs
#
# Backups: ~/hostedai-backups/<TIMESTAMP>/ on the server
# Rollback: ./deploy/rollback.sh <TIMESTAMP>

set -euo pipefail

# ── Require env vars — no silent fallback to sandbox ──────────────────────
if [[ -z "${WHMCS_SSH_HOST:-}" ]]; then
    echo "Error: WHMCS_SSH_HOST is not set. Export it before deploying." >&2
    exit 1
fi
if [[ -z "${WHMCS_ROOT:-}" ]]; then
    echo "Error: WHMCS_ROOT is not set. Export it before deploying." >&2
    exit 1
fi

SSH_HOST="${WHMCS_SSH_HOST}"
REMOTE_ROOT="${WHMCS_ROOT}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
LOG_FILE="${SCRIPT_DIR}/deployments.log"
LAST_DEPLOYED_FILE="${SCRIPT_DIR}/.last_deployed"

cd "${REPO_ROOT}"

# ── Lock — prevent concurrent deploys to the same host ────────────────────
LOCK_FILE="/tmp/whmcs-deploy-$(echo "${SSH_HOST}" | tr -dc '[:alnum:]').lock"
exec 9>"${LOCK_FILE}"
if ! flock -n 9; then
    echo "Error: another deploy to ${SSH_HOST} is already running (${LOCK_FILE})" >&2
    exit 1
fi

# ── SSH ControlMaster — reuse a single connection for all operations ───────
SSH_CONTROL="/tmp/whmcs-deploy-ctl-$$"
SSH_OPTS=(-o "ControlMaster=auto" -o "ControlPath=${SSH_CONTROL}" -o "ControlPersist=60")

TIMESTAMP=$(date +%Y%m%d-%H%M%S)
DEPLOYED=()
DEPLOY_COMPLETE=false

cleanup() {
    ssh -O exit -o "ControlPath=${SSH_CONTROL}" "${SSH_HOST}" 2>/dev/null || true
    flock -u 9 2>/dev/null || true
    if [[ "${DEPLOY_COMPLETE}" != "true" && "${#DEPLOYED[@]}" -gt 0 ]]; then
        echo "" >&2
        echo "Warning: deploy interrupted after ${#DEPLOYED[@]} file(s) — server may be in a mixed state." >&2
        echo "         To rollback: ./deploy/rollback.sh ${TIMESTAMP}" >&2
    fi
}
trap cleanup EXIT

# ── Verify SSH before touching anything ───────────────────────────────────
echo -n "Checking SSH connectivity to ${SSH_HOST} ... "
if ! ssh "${SSH_OPTS[@]}" "${SSH_HOST}" exit 2>/dev/null; then
    echo "FAILED" >&2
    echo "Error: cannot reach ${SSH_HOST}. Try: ssh ${SSH_HOST}" >&2
    exit 1
fi
echo "ok"

# ── Resolve remote home to absolute path — avoids ~ vs /home/user mismatch
# when find returns absolute paths and we try to strip the backup prefix.
REMOTE_HOME=$(ssh "${SSH_OPTS[@]}" "${SSH_HOST}" 'echo "$HOME"')
if [[ -z "${REMOTE_HOME}" ]]; then
    echo "Error: could not resolve \$HOME on ${SSH_HOST}" >&2
    exit 1
fi
if [[ "${REMOTE_HOME}" =~ [[:space:]] ]]; then
    echo "Error: remote \$HOME '${REMOTE_HOME}' contains spaces — unsupported" >&2
    exit 1
fi
REMOTE_BACKUP_BASE="${REMOTE_HOME}/hostedai-backups"
TMP_BASE="${REMOTE_HOME}/hostedai-deploy-tmp"

# ── Warn on uncommitted local changes ─────────────────────────────────────
if ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
    echo "Warning: uncommitted local changes detected — only HEAD-tracked files will be deployed."
    echo ""
fi

GIT_HASH=$(git rev-parse --short HEAD)
BACKUP_DIR="${REMOTE_BACKUP_BASE}/${TIMESTAMP}"
TMP_DIR="${TMP_BASE}/${TIMESTAMP}"

# ── Determine files to deploy ──────────────────────────────────────────────
if [ "$#" -gt 0 ]; then
    # Validate manual args — no path traversal
    for arg in "$@"; do
        if [[ "${arg}" == *".."* ]]; then
            echo "Error: path traversal detected in argument: ${arg}" >&2
            exit 1
        fi
    done
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
    DEPLOY_COMPLETE=true
    exit 0
fi

echo "╔══════════════════════════════════════════════╗"
echo "  Deploy ${TIMESTAMP} (${GIT_HASH})"
echo "  Target: ${SSH_HOST}:${REMOTE_ROOT}"
echo "  Files:  ${#FILES[@]}"
echo "╚══════════════════════════════════════════════╝"

# Create backup and tmp dirs in one round-trip
ssh "${SSH_OPTS[@]}" "${SSH_HOST}" "mkdir -p ${BACKUP_DIR} ${TMP_DIR}"

for FILE in "${FILES[@]}"; do
    if [ ! -f "${FILE}" ]; then
        echo "  SKIP   ${FILE} (not found locally)"
        continue
    fi

    REMOTE_FILE="${REMOTE_ROOT}/${FILE}"
    BACKUP_FILE="${BACKUP_DIR}/${FILE}"
    TMP_FILE="${TMP_DIR}/${FILE}"

    # Backup — distinguish "new file" from actual errors.
    # If the remote file exists and backup fails (disk full, perms), abort loudly.
    echo -n "  backup ${FILE} ... "
    if ssh "${SSH_OPTS[@]}" "${SSH_HOST}" "test -f ${REMOTE_FILE}"; then
        ssh "${SSH_OPTS[@]}" "${SSH_HOST}" \
            "mkdir -p \$(dirname ${BACKUP_FILE}) && sudo cp ${REMOTE_FILE} ${BACKUP_FILE} && echo ok"
    else
        echo "new"
    fi

    # SCP to user-writable tmp, then sudo cp to target with correct permissions
    echo -n "  deploy ${FILE} ... "
    ssh "${SSH_OPTS[@]}" "${SSH_HOST}" "mkdir -p \$(dirname ${TMP_FILE})"
    scp "${SSH_OPTS[@]}" "${FILE}" "${SSH_HOST}:${TMP_FILE}"
    ssh "${SSH_OPTS[@]}" "${SSH_HOST}" \
        "sudo cp ${TMP_FILE} ${REMOTE_FILE} && sudo chmod 644 ${REMOTE_FILE} && echo ok"

    DEPLOYED+=("${FILE}")
done

# Cleanup tmp dir
ssh "${SSH_OPTS[@]}" "${SSH_HOST}" "rm -rf ${TMP_DIR}"

# Write log entry
printf '%s | %s | %s | %s\n' \
    "${TIMESTAMP}" "${GIT_HASH}" "${BACKUP_DIR}" "${DEPLOYED[*]}" \
    >> "${LOG_FILE}"

# Update last deployed marker
echo "${GIT_HASH}" > "${LAST_DEPLOYED_FILE}"

DEPLOY_COMPLETE=true
echo ""
echo "Deployed ${#DEPLOYED[@]} file(s). Backup: ${BACKUP_DIR}"
echo "To rollback: ./deploy/rollback.sh ${TIMESTAMP}"
