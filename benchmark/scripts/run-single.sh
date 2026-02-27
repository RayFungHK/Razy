#!/usr/bin/env bash
# ============================================================
# Quick single-scenario runner — useful during development.
#
# Usage:
#   ./benchmark/scripts/run-single.sh 01_static_route razy localhost:8081
#
# Args:
#   $1  Scenario file stem (e.g. 01_static_route)
#   $2  Target name (razy | laravel) — for result naming
#   $3  Target host:port
# ============================================================

set -euo pipefail

SCENARIO="${1:?Usage: $0 <scenario> <target_name> <host:port>}"
TARGET_NAME="${2:?Missing target name}"
TARGET_HOST="${3:?Missing host:port}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
K6_FILE="${SCRIPT_DIR}/../k6/scenarios/${SCENARIO}.js"
RESULTS_DIR="${SCRIPT_DIR}/../results/${TARGET_NAME}"

mkdir -p "${RESULTS_DIR}"

if [ ! -f "${K6_FILE}" ]; then
    echo "ERROR: Script not found: ${K6_FILE}"
    echo "Available:"
    ls "${SCRIPT_DIR}/../k6/scenarios/"
    exit 1
fi

echo "Running scenario: ${SCENARIO}"
echo "Target: ${TARGET_NAME} @ ${TARGET_HOST}"
echo ""

k6 run \
    -e "TARGET_HOST=${TARGET_HOST}" \
    --summary-export="${RESULTS_DIR}/${SCENARIO}_run1.json" \
    "${K6_FILE}"
