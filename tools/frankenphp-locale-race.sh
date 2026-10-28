#!/usr/bin/env bash
# RZ-39: prove the bootstrap locale setup is safe under a threaded SAPI.
#
# Starts classic FrankenPHP (8 PHP threads) on tests/frankenphp/locale, then for
# each mode runs one watcher request (reads LC_CTYPE in a loop) while other
# threads serve a burst of requests in that mode:
#   legacy  the pre-RZ-39 per-request setlocale(): the watcher MUST see a
#           non-UTF-8 LC_CTYPE (the request-end reset). Proves the check bites.
#   fixed   Razy\Util\Locale::ensureUtf8Ctype(): the watcher must see none.
#
#   tools/frankenphp-locale-race.sh docker <image>   (CI: the image's frankenphp)
#   tools/frankenphp-locale-race.sh bin <frankenphp>  (a local binary)
# Env: RAZY_LOCALE_RACE_SECONDS (default 4), RAZY_LOCALE_RACE_CLIENTS (default 6).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
how="${1:?docker <image> | bin <frankenphp>}"
what="${2:?image or binary}"
secs="${RAZY_LOCALE_RACE_SECONDS:-4}"
clients="${RAZY_LOCALE_RACE_CLIENTS:-6}"
port=18390
pid=""
name="razy-rz39-$$"

cleanup() {
  if [[ "$how" == docker ]]; then
    docker rm -f "$name" >/dev/null 2>&1 || true
  elif [[ -n "$pid" ]]; then
    kill "$pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

case "$how" in
  docker)
    docker run -d --name "$name" -p "127.0.0.1:${port}:${port}" \
      -v "$root:/razy:ro" \
      -e RAZY_LOCALE_TEST_ROOT=/razy/tests/frankenphp/locale \
      -e XDG_CONFIG_HOME=/tmp/c -e XDG_DATA_HOME=/tmp/d \
      --entrypoint frankenphp "$what" run --config /razy/tests/frankenphp/locale/Caddyfile >/dev/null
    ;;
  bin)
    RAZY_LOCALE_TEST_ROOT="$root/tests/frankenphp/locale" \
      XDG_CONFIG_HOME="${TMPDIR:-/tmp}/rz39-c" XDG_DATA_HOME="${TMPDIR:-/tmp}/rz39-d" \
      "$what" run --config "$root/tests/frankenphp/locale/Caddyfile" >"${TMPDIR:-/tmp}/rz39-frankenphp.log" 2>&1 &
    pid=$!
    ;;
  *)
    echo "usage: $0 docker <image> | bin <frankenphp>" >&2
    exit 2
    ;;
esac

base="http://127.0.0.1:${port}"
for _ in $(seq 1 60); do
  if curl -fs -o /dev/null --max-time 2 "$base/?mode=fixed"; then
    break
  fi
  sleep 1
done
curl -fs -o /dev/null --max-time 2 "$base/?mode=fixed" || { echo "FAIL frankenphp did not answer" >&2; [[ "$how" == docker ]] && docker logs "$name" >&2; exit 1; }

# One watcher, $clients loops of $mode requests while it runs. Prints the watcher JSON.
race() {
  local mode="$1" out
  out="$(mktemp)"
  curl -fs --max-time $((secs + 10)) "$base/?mode=watch&s=${secs}" >"$out" &
  local watcher=$!
  sleep 0.3
  local stop=$((SECONDS + secs - 1))
  local i
  for i in $(seq 1 "$clients"); do
    (while ((SECONDS < stop)); do curl -fs -o /dev/null --max-time 5 "$base/?mode=${mode}" || true; done) &
  done
  wait "$watcher"
  wait
  cat "$out"
  rm -f "$out"
}

legacy="$(race legacy)"
echo "legacy: $legacy"
fixed="$(race fixed)"
echo "fixed:  $fixed"
python3 - "$legacy" "$fixed" <<'PY'
import json, sys
for label, raw in (("legacy", sys.argv[1]), ("fixed", sys.argv[2])):
    if not raw.strip():
        sys.exit(f"FAIL the {label} watcher returned nothing")
legacy, fixed = json.loads(sys.argv[1]), json.loads(sys.argv[2])
if legacy["reads"] < 1000 or fixed["reads"] < 1000:
    sys.exit(f"FAIL the watcher read too little to judge: {legacy['reads']} / {fixed['reads']}")
if legacy["bad"] == 0:
    sys.exit("FAIL the per-request setlocale() never showed a non-UTF-8 LC_CTYPE: the check does not bite here")
if fixed["bad"] != 0:
    sys.exit(f"FAIL with RZ-39 another thread still saw LC_CTYPE {fixed['seen']} ({fixed['bad']} of {fixed['reads']} reads)")
print(f"ok per-request setlocale(): {legacy['bad']} of {legacy['reads']} reads saw {legacy['seen']}")
print(f"ok RZ-39: 0 of {fixed['reads']} reads saw a non-UTF-8 LC_CTYPE")
PY
