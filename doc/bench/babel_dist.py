#!/usr/bin/env python3
"""Sequential single-string latency DISTRIBUTION, plus a batch call on identical text.

Run 1 sequential total 9.48s, run 2 total 31.61s, yet medians were 452ms and 576ms --
so the totals are driven by a long tail, not by typical calls. Print every latency.
"""
import json, statistics, time, urllib.request

BASE, UA, N = "https://babel.survos.com", "curl/8.5.0", 20
RUN = str(int(time.time()))[-5:]
STRINGS = [f"Open the settings panel and choose option {RUN}{i}" for i in range(N)]


def post(payload):
    req = urllib.request.Request(
        BASE + "/translate", data=json.dumps(payload).encode(),
        headers={"Content-Type": "application/json", "User-Agent": UA}, method="POST")
    t0 = time.perf_counter()
    with urllib.request.urlopen(req, timeout=300) as r:
        body = json.loads(r.read())
    return time.perf_counter() - t0, body


lat = []
for s in STRINGS:
    dt, _ = post({"q": s, "source": "en", "target": "es", "format": "text"})
    lat.append(dt)
    print(f"  call {len(lat):>2}: {dt*1000:>7.0f} ms")

s = sorted(lat)
print(f"\nsequential N={N}  total {sum(lat):.2f}s")
print(f"  min {s[0]*1000:.0f}  p50 {s[N//2]*1000:.0f}  p90 {s[int(N*.9)]*1000:.0f}  max {s[-1]*1000:.0f} ms")
print(f"  mean {statistics.mean(lat)*1000:.0f} ms   -> mean/median ratio {statistics.mean(lat)/s[N//2]:.1f}")

bt, body = post({"q": STRINGS, "source": "en", "target": "es", "format": "text"})
print(f"\nbatch same {N} strings: {bt:.2f}s total, {bt/N*1000:.0f} ms/string")
print(f"  batch vs sequential: {sum(lat)/bt:.1f}x")
