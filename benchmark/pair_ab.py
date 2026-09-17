#!/usr/bin/env python3
"""Pair-A/B harness — the instrument EPOCH-2026-09's compiled column called for.

The same-host noise floor was MEASURED at +-2x (one image: 396.3 then 198.3
RPS). Single-pass comparisons across time therefore prove nothing finer than
that band. This harness runs arms ALTERNATELY, order-alternating across pairs
(A,B then B,A), so machine drift hits both arms roughly equally and the PAIRED
ratio is the statistic — the standard fix for exactly this noise structure.

Usage (from the repo root):
    python benchmark/pair_ab.py --arm compiled=127.0.0.1:8091 \
                               --arm legacy=127.0.0.1:8092 \
                               --path /x17/ping --pairs 3

Every pass is invalidated (never silently averaged) unless http_req_failed == 0
— the 502-inflation lesson. Raw JSONs land in benchmark/results/pair/.
"""
import argparse
import json
import pathlib
import statistics
import subprocess
import sys
import socket
import threading
import time
import urllib.request

K6_IMAGE = "grafana/k6:0.52.0"


def heartbeat(ports, stop):
    """Windows Docker port-forwarding is IDLE-REAPED: an exposed port that
    sees no traffic stops forwarding and new connections hang until poked.
    Live fire 2026-09-17: both arms 'timed out' with workers sleeping at 0%
    CPU. The poke must be a FORCE CONNECT (connect + immediate close — a GET
    hung mid-request never frees the forwarder, which is exactly how the first
    heartbeat version starved while probing; live-fire 2026-09-17). Each poke
    re-establishes the forwarder, then a real GET verifies."""
    for p in ports:
        poke(p)
    while not stop.is_set():
        for p in ports:
            poke(p)
        stop.wait(1.0)


def poke(port):
    # step 1: force the idle-reaped forwarder to re-establish
    try:
        s = socket.socket()
        s.settimeout(1.0)
        s.connect(("127.0.0.1", int(port)))
        s.close()
    except Exception:
        pass
    # step 2: verify with a real request (also keeps fpm/opcache warm)
    try:
        urllib.request.urlopen(f"http://127.0.0.1:{port}/x1/ping", timeout=2).read()
    except Exception:
        pass


def wait_ready(ports, deadline=180):
    end = time.time() + deadline
    while time.time() < end:
        if all(_ok(p) for p in ports):
            return True
        for p in ports:  # unstick each dead port exactly as the heartbeat does
            poke(p)
        time.sleep(3)
    return False


def _ok(port):
    try:
        return urllib.request.urlopen(
            f"http://127.0.0.1:{port}/x1/ping", timeout=3).read() == b"ok"
    except Exception:
        return False


def run_pass(name, host, path, scenario, outdir, idx):
    # host-side file, in-container address: /export IS benchmark/ (mounted).
    # Passing a host-relative path here wrote the export into the container's
    # own filesystem and lost it on --rm (live-fire 2026-09-17).
    host_file = outdir / f"pair{idx:02d}_{name}.json"
    if host_file.exists():
        host_file.unlink()  # never read last pass's export as this pass's
    export = f"/export/results/pair/{host_file.name}"
    # --network host: on Docker Desktop the k6 container shares the LinuxKit
    # VM's localhost, where every published arm port answers — the ONE dial
    # path proven live while compose-DNS name dials hung (2026-09-17 hunt;
    # nginx sidecars work from every vantage, see pair.conf.template).
    cmd = [
        "docker", "run", "--rm", "--network", "host",
        "-e", f"TARGET_HOST={host}",
        "-e", f"TARGET_PATH={path}",
        "-v", f"{pathlib.Path.cwd() / 'benchmark'}:/export",
        K6_IMAGE, "run", f"/export/k6/scenarios/{scenario}",
        f"--summary-export={export}",
    ]
    print(f"\n=== pair {idx} :: arm '{name}' -> {host}{path} ===", flush=True)
    r = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.STDOUT)
    if not host_file.is_file():
        return None
    m = json.loads(host_file.read_text())["metrics"]
    return {
        "rps": m["http_reqs"]["rate"],
        "failed": m["http_req_failed"]["value"],
        "checks": m.get("checks", {}).get("value"),
        "p95": m["http_req_duration"]["p(95)"],
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--arm", action="append", required=True,
                    help="name=host:port (repeat; two arms expected)")
    ap.add_argument("--path", default="/x1/ping")
    ap.add_argument("--scenario", default="10_scale_pair.js")
    ap.add_argument("--pairs", type=int, default=3)
    args = ap.parse_args()

    arms = {}
    for a in args.arm:
        name, _, host = a.partition("=")
        arms[name] = host
    if len(arms) != 2:
        sys.exit("exactly two --arm name=host:port values are required")
    (a_name, a_host), (b_name, b_host) = arms.items()

    outdir = pathlib.Path("benchmark/results/pair")
    outdir.mkdir(parents=True, exist_ok=True)

    # targets are HOST-exposed ports (127.0.0.1:PORT): Windows Docker's
    # in-network IP translation only covers the historically published ports,
    # so k6 joins the compose network only for DNS symmetry and dials through
    # the host — the same access path every published pass used.
    ports = sorted({host.rpartition(":")[2] for host in arms.values() if ":" in host})
    if not wait_ready([int(p) for p in ports]):
        sys.exit("arms never became ready from the host — fix the stack before measuring")
    stop = threading.Event()
    hb = threading.Thread(target=heartbeat, args=([int(p) for p in ports], stop), daemon=True)
    hb.start()
    try:
        _run(args, arms, outdir)
    finally:
        stop.set()


def _run(args, arms, outdir):
    (a_name, a_host), (b_name, b_host) = arms.items()
    results = {a_name: [], b_name: []}
    ratios = []
    for i in range(1, args.pairs + 1):
        # order alternates so any drift between passes is symmetric in pairs
        seq = [(a_name, a_host), (b_name, b_host)] if i % 2 else [(b_name, b_host), (a_name, a_host)]
        got = {}
        for name, host in seq:
            r = run_pass(name, host, args.path, args.scenario, outdir, i)
            if r is None or r["failed"] != 0:
                print(f"[INVALID] pair {i} arm {name}: missing export or failed={r and r['failed']} — pair discarded, not averaged")
                continue
            results[name].append(r["rps"])
            got[name] = r["rps"]
            print(f"  {name}: {r['rps']:.1f} RPS (p95 {r['p95']:.1f}ms, checks {r['checks']:.3f})")
        if len(got) == 2:
            ratio = got[a_name] / got[b_name]
            ratios.append(ratio)
            print(f"  ratio {a_name}/{b_name} = {ratio:.3f}")

    print("\n===== PAIRED SUMMARY =====")
    for name, vals in results.items():
        if vals:
            print(f"{name}: median {statistics.median(vals):.1f} RPS  passes={['%.1f' % v for v in vals]}")
    if ratios:
        print(f"ratio {a_name}/{b_name}: median {statistics.median(ratios):.3f}  all={['%.3f' % v for v in ratios]}")
        print(f"valid pairs: {len(ratios)}/{args.pairs}")
    else:
        print("no valid pairs — see INVALID lines above")


if __name__ == "__main__":
    main()
