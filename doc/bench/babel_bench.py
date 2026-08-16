#!/usr/bin/env python3
"""Measure where time actually goes on babel.survos.com (LibreTranslate v1.9.5).

Four measurements, deliberately small (~60 translate calls total):
  0. overhead   -- GET /languages: network + HTTP + app dispatch, no translation work
  1. sequential -- N single-string POST /translate, one at a time (today's pattern)
  2. batch      -- ONE POST /translate with q = [all N strings]
  3. parallel   -- the same N single-string calls at C-way concurrency

Distinct strings every run so nothing can be served from an upstream cache.
"""
import json, statistics, sys, time, urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE = "https://babel.survos.com"
N = 20
CONC = 8
RUN = str(int(time.time()))
UA = "curl/8.5.0"

# Short, realistic UI-label-ish strings, made unique per run.
STRINGS = [f"Save the {w} record number {RUN[-4:]}{i}" for i, w in enumerate(
    "alpha bravo charlie delta echo foxtrot golf hotel india juliet "
    "kilo lima mike november oscar papa quebec romeo sierra tango".split())]


def post(path, payload, timeout=180):
    req = urllib.request.Request(
        BASE + path,
        data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "User-Agent": UA},
        method="POST")
    t0 = time.perf_counter()
    with urllib.request.urlopen(req, timeout=timeout) as r:
        body = json.loads(r.read())
    return time.perf_counter() - t0, body


def get(path):
    t0 = time.perf_counter()
    req = urllib.request.Request(BASE + path, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=60) as r:
        r.read()
    return time.perf_counter() - t0


def main():
    print(f"babel bench: N={N} strings, concurrency={CONC}, run={RUN}\n")

    # 0. pure round-trip overhead, no translation
    overhead = [get("/languages") for _ in range(10)]
    ov = statistics.median(overhead)
    print(f"0. overhead  GET /languages x10   median {ov*1000:7.1f} ms  "
          f"(min {min(overhead)*1000:.0f} / max {max(overhead)*1000:.0f})")

    # 1. sequential singles -- what TargetWorkflow does today
    seq = []
    for s in STRINGS:
        dt, body = post("/translate", {"q": s, "source": "en", "target": "es", "format": "text"})
        seq.append(dt)
    seq_total = sum(seq)
    print(f"1. sequential {N} singles          total {seq_total:7.2f} s   "
          f"median/call {statistics.median(seq)*1000:.0f} ms")

    # 2. one batch call with an array
    bt, body = post("/translate", {"q": STRINGS, "source": "en", "target": "es", "format": "text"})
    got = body.get("translatedText")
    ok = isinstance(got, list) and len(got) == N
    print(f"2. batch      1 call, q=[{N}]        total {bt:7.2f} s   "
          f"per-string {bt/N*1000:.0f} ms   array_ok={ok}")

    # 3. same singles, concurrent
    strings3 = [s + " x" for s in STRINGS]  # distinct again
    t0 = time.perf_counter()
    with ThreadPoolExecutor(max_workers=CONC) as ex:
        list(ex.map(lambda s: post("/translate",
                                   {"q": s, "source": "en", "target": "es", "format": "text"}),
                    strings3))
    par_total = time.perf_counter() - t0
    print(f"3. parallel   {N} singles @ {CONC}        total {par_total:7.2f} s   "
          f"per-string {par_total/N*1000:.0f} ms")

    print()
    print(f"   translation work per string (seq median - overhead): "
          f"{(statistics.median(seq)-ov)*1000:.0f} ms")
    print(f"   overhead share of a sequential call: {ov/statistics.median(seq)*100:.0f}%")
    print(f"   batch speedup vs sequential:   {seq_total/bt:.1f}x")
    print(f"   parallel speedup vs sequential: {seq_total/par_total:.1f}x")
    if isinstance(got, list) and got:
        print(f"\n   sample: {STRINGS[0]!r} -> {got[0]!r}")


if __name__ == "__main__":
    sys.exit(main())
