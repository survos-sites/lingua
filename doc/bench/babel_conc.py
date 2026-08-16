#!/usr/bin/env python3
"""Concurrency sweep against babel: does ANY parallelism help, or none at all?

12 strings translated at concurrency 1, 2, 4, 6. If babel had N free workers we'd see
throughput climb to N and then flatten. If it's effectively serial, wall time stays flat
(and latency per call rises proportionally).
"""
import json, time, urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE, UA, N = "https://babel.survos.com", "curl/8.5.0", 12
RUN = str(int(time.time()))[-5:]


def translate(s):
    req = urllib.request.Request(
        BASE + "/translate",
        data=json.dumps({"q": s, "source": "en", "target": "es", "format": "text"}).encode(),
        headers={"Content-Type": "application/json", "User-Agent": UA}, method="POST")
    t0 = time.perf_counter()
    with urllib.request.urlopen(req, timeout=180) as r:
        r.read()
    return time.perf_counter() - t0


print(f"concurrency sweep: {N} strings per level, run={RUN}\n")
print(f"{'conc':>4} {'wall':>8} {'thru/s':>8} {'med call':>10}")
for conc in (1, 2, 4, 6):
    strings = [f"Please review document {RUN}{conc}x{i} before the deadline" for i in range(N)]
    t0 = time.perf_counter()
    with ThreadPoolExecutor(max_workers=conc) as ex:
        lat = list(ex.map(translate, strings))
    wall = time.perf_counter() - t0
    lat.sort()
    print(f"{conc:>4} {wall:>7.2f}s {N/wall:>7.2f} {lat[len(lat)//2]*1000:>9.0f}ms")
