#!/bin/sh
# Scenario 10 — module-scale BOOT curve for the benchgate scale site.
#
# k6 is the wrong tool here: what varies with module count is NOT steady-state
# request cost but (a) how long a cold worker takes before it answers anything,
# (b) how much memory that boots into, and (c) the first-request (cold-dispatch)
# latency. This script restarts the container, times the first successful
# answer, samples worker RSS, and repeats ROUNDS times per scale.
#
# Run from the HOST (needs docker + curl). Assumes the scale image is already
# built for the given SCALE and the mysql container is up:
#   docker compose build --build-arg SCALE=25 scale
#   ./boot-curve.sh 25 3
set -u

SCALE="${1:-5}"
ROUNDS="${2:-3}"
URL="http://localhost:8086/pp/ping"
SERVICE="scale"
CONTAINER="bench-scale"

echo "scale=${SCALE} rounds=${ROUNDS}"

r=1
while [ "$r" -le "$ROUNDS" ]; do
  docker compose -f ../docker-compose.yml --profile scale stop "$SERVICE" >/dev/null 2>&1
  docker compose -f ../docker-compose.yml --profile scale rm -f "$SERVICE" >/dev/null 2>&1

  # -t 0: no wait on stop, cold as CI would deliver it
  start=$(date +%s%N)
  docker compose -f ../docker-compose.yml --profile scale up -d "$SERVICE" >/dev/null 2>&1

  # Poll until the FIRST answered request (any HTTP code counts: the
  # framework answering is the readiness signal, 200 asserted separately).
  first=""
  i=0
  while [ $i -lt 300 ]; do
    code=$(curl -s -o /dev/null -H 'Host: bench-scale' -w '%{http_code}' --max-time 2 "$URL" 2>/dev/null)
    if [ "$code" = "200" ]; then
      now=$(date +%s%N)
      first=$now
      boot_ms=$(( (now - start) / 1000000 ))
      echo "round $r: boot_to_first_200_ms=${boot_ms}"
      break
    fi
    i=$((i+1))
    sleep 0.2
  done
  [ -z "$first" ] && { echo "round $r: NEVER READY (300 polls)"; r=$((r+1)); continue; }

  # First three answered-request latencies (cold dispatch on request 1).
  # -H forces an explicit Host: Caddy binds ':8080' (no site name) and
  # refuses requests without a Host header (400 otherwise).
  j=1
  while [ $j -le 3 ]; do
    t=$(curl -s -o /dev/null -H 'Host: bench-scale' -w '%{time_total}' --max-time 5 "$URL")
    echo "round $r: req${j}_seconds=${t}"
    j=$((j+1))
  done

  # Worker RSS (KB) via docker stats one-shot; total across worker threads
  rss=$(docker stats --no-stream --format '{{.MemUsage}}' "$CONTAINER" 2>/dev/null | head -1)
  echo "round $r: container_mem=${rss}"

  r=$((r+1))
done

echo "done scale=${SCALE}"
