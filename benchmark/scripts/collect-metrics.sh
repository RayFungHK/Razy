#!/usr/bin/env bash
# ============================================================
# Server-side metrics collector (runs alongside k6).
#
# Captures CPU%, Memory, and open file descriptors at 2s intervals
# for the target container. Output is TSV for easy parsing.
#
# Usage:
#   ./benchmark/scripts/collect-metrics.sh bench-razy 120 > metrics.tsv
#
# Args:
#   $1  Container name (bench-razy | bench-laravel)
#   $2  Duration in seconds (default: 120)
# ============================================================

set -euo pipefail

CONTAINER="${1:?Usage: $0 <container_name> [duration_seconds]}"
DURATION="${2:-120}"
INTERVAL=2
ITERATIONS=$((DURATION / INTERVAL))

echo -e "timestamp\tcpu_pct\tmem_usage\tmem_limit\tmem_pct\tpids\topen_fds"

for i in $(seq 1 "${ITERATIONS}"); do
    TS="$(date -Iseconds)"

    # Docker stats (single snapshot)
    STATS=$(docker stats --no-stream --format "{{.CPUPerc}}\t{{.MemUsage}}\t{{.MemPerc}}\t{{.PIDs}}" "${CONTAINER}" 2>/dev/null || echo "N/A\tN/A\tN/A\tN/A")

    # Open file descriptors inside container
    FDS=$(docker exec "${CONTAINER}" sh -c 'ls /proc/1/fd 2>/dev/null | wc -l' 2>/dev/null || echo "N/A")

    echo -e "${TS}\t${STATS}\t${FDS}"
    sleep "${INTERVAL}"
done
