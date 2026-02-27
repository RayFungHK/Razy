#!/usr/bin/env bash
# ============================================================
# Benchmark Runner — Orchestrates all 6 scenarios for one target
# ============================================================
#
# Usage:
#   ./benchmark/scripts/run-all.sh razy   localhost:8081 3
#   ./benchmark/scripts/run-all.sh laravel localhost:8082 3
#
# Args:
#   $1  Target name (razy | laravel)  — used for result file naming
#   $2  Target host:port              — where the app is running
#   $3  Number of runs per scenario   — default 3

set -euo pipefail

TARGET_NAME="${1:?Usage: $0 <target_name> <host:port> [runs]}"
TARGET_HOST="${2:?Usage: $0 <target_name> <host:port> [runs]}"
RUNS="${3:-3}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
K6_DIR="$(cd "${SCRIPT_DIR}/../k6/scenarios" && pwd)"
RESULTS_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)/results/${TARGET_NAME}"
METRICS_DIR="${RESULTS_DIR}/metrics"

SCENARIOS=(
    "01_static_route"
    "02_template_render"
    "03_db_read"
    "04_db_write"
    "05_composite"
    "06_heavy_cpu"
)

mkdir -p "${RESULTS_DIR}" "${METRICS_DIR}"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Benchmark Runner                                          ║"
echo "║  Target:    ${TARGET_NAME} @ ${TARGET_HOST}"
echo "║  Scenarios: ${#SCENARIOS[@]}"
echo "║  Runs:      ${RUNS} each"
echo "║  Results:   ${RESULTS_DIR}"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# ── Pre-flight health check ──────────────────────────────────
echo "[Pre-flight] Checking ${TARGET_HOST} ..."
HEALTH_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "http://${TARGET_HOST}/benchmark/static" || true)
if [ "${HEALTH_STATUS}" != "200" ]; then
    echo "ERROR: Endpoint http://${TARGET_HOST}/benchmark/static returned ${HEALTH_STATUS}"
    echo "       Make sure the target is running and healthy."
    exit 1
fi
echo "[Pre-flight] OK (HTTP ${HEALTH_STATUS})"
echo ""

# ── Warmup ───────────────────────────────────────────────────
echo "[Warmup] Sending 1000 requests to stabilise workers ..."
k6 run --quiet --no-summary \
    -e "TARGET_HOST=${TARGET_HOST}" \
    --vus 20 --duration 30s \
    <(cat <<'EOF'
import http from 'k6/http';
import { sleep } from 'k6';
export default function () {
    http.get(`http://${__ENV.TARGET_HOST}/benchmark/static`);
    sleep(0.01);
}
EOF
) 2>/dev/null || true
echo "[Warmup] Done"
echo ""

# ── Run scenarios ────────────────────────────────────────────
for scenario in "${SCENARIOS[@]}"; do
    SCENARIO_FILE="${K6_DIR}/${scenario}.js"

    if [ ! -f "${SCENARIO_FILE}" ]; then
        echo "[SKIP] ${scenario} — script not found"
        continue
    fi

    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "  Scenario: ${scenario}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

    for run in $(seq 1 "${RUNS}"); do
        RESULT_FILE="${RESULTS_DIR}/${scenario}_run${run}.json"
        METRICS_FILE="${METRICS_DIR}/${scenario}_run${run}_sysmetrics.txt"

        echo ""
        echo "  Run ${run}/${RUNS} ..."

        # Start server-side metrics collection in background
        (
            while true; do
                echo "--- $(date -Iseconds) ---" >> "${METRICS_FILE}"
                # Try docker stats first, fall back to ps
                docker stats --no-stream --format \
                    "{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}" \
                    "bench-${TARGET_NAME}" >> "${METRICS_FILE}" 2>/dev/null \
                || ps aux | grep -E "php|frankenphp|swoole" | head -5 >> "${METRICS_FILE}" 2>/dev/null
                sleep 2
            done
        ) &
        METRICS_PID=$!

        # Run k6
        k6 run \
            -e "TARGET_HOST=${TARGET_HOST}" \
            --out "json=${RESULTS_DIR}/${scenario}_run${run}_raw.json.gz" \
            --summary-export="${RESULT_FILE}" \
            "${SCENARIO_FILE}" 2>&1 | tee "${RESULTS_DIR}/${scenario}_run${run}.log"

        # Stop metrics collection
        kill "${METRICS_PID}" 2>/dev/null || true
        wait "${METRICS_PID}" 2>/dev/null || true

        echo "  → Results: ${RESULT_FILE}"

        # Cool-down between runs
        if [ "${run}" -lt "${RUNS}" ]; then
            echo "  Cooling down 10s ..."
            sleep 10
        fi
    done

    # Cool-down between scenarios
    echo ""
    echo "  Scenario complete. Cooling down 15s before next ..."
    sleep 15
done

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  All scenarios complete for: ${TARGET_NAME}                ║"
echo "║  Results in: ${RESULTS_DIR}"
echo "╚══════════════════════════════════════════════════════════════╝"
