# Speeding up lingua → babel: what the measurements say

Measured 2026-08-16 against production `babel.survos.com` (LibreTranslate v1.9.5 on Dokku).
Scripts in `doc/bench/`, re-runnable. ~150 translate calls total, en→es, short UI-label
strings, unique per run so nothing can be served from an upstream cache.

**Answer up front: none of the three transport options is the lever. babel's own latency
varies 3–4× minute to minute, which is larger than the difference between any of them.**

## The three candidates, measured

Same 20 strings, three full runs of `doc/bench/babel_bench.py`:

| run | sequential singles | one batch call `q=[20]` | 20 singles @ 8-way parallel |
|---|---|---|---|
| 1 | 9.48 s | 7.21 s | 23.12 s |
| 2 | 31.61 s | 6.08 s | 32.79 s |
| 3 | 9.05 s | **26.16 s** | — |

Read the columns down, not across. Sequential swings 9.05 → 31.61 s. Batch swings 6.08 →
26.16 s. **Both strategies produced both the best and the worst number**, so the ranking
between them is not stable and any single run "proving" batch is 5.2× faster (run 2) or 0.3×
slower (run 3) is measuring the weather.

Within a single run, calls are consistent — `doc/bench/babel_dist.py` printed all 20
sequential latencies as 236–735 ms, p50 481, mean/median 0.90. There is no long tail
*inside* a run. The variance is *between* runs: babel has minutes where everything is ~3×
slower.

### Parallelism is the one clear signal, and it is negative

8-way concurrency took 23.12 s and 32.79 s for work that sequential does in ~9 s on a good
minute. A separate sweep (`doc/bench/babel_conc.py`, 12 strings per level) during a slow
period:

| concurrency | wall | throughput | median call |
|---|---|---|---|
| 1 | 60.19 s | 0.20/s | 1287 ms |
| 2 | 51.27 s | 0.23/s | 1780 ms |
| 4 | 34.93 s | 0.34/s | 1958 ms |
| 6 | 44.07 s | 0.27/s | 3372 ms |

Some parallelism exists (4-way ≈ 1.7× throughput over serial) but per-call latency degrades
steadily and 6-way is worse than 4-way. This is the profile of a CPU-bound service with a
small fixed worker pool, not something that scales with client concurrency.

**So: do not answer this by sending more simultaneous requests.** That was measured and it
loses.

## Is HTTP overhead the problem? No, but it isn't nothing

`GET /languages` — network + TLS + HTTP + app dispatch, no translation — is **160–197 ms
median**. A median single-string translate is 452–576 ms. So overhead is roughly **28–44% of
a small-string call**.

That is enough to be worth removing, and it is why batching is still the right default: one
round trip instead of twenty. It is just not worth *expecting a speedup from*, because the
250–700 ms of actual translation work per string dominates, and babel's minute-to-minute
variance dominates that.

## What actually moves the needle

1. **Don't send babel strings lingua already has.** This is lingua's entire reason to exist
   and it is a 100% saving on every hit, against a service where every miss costs
   250–1300 ms. Any transport tuning is rounding error next to cache hit rate.

2. **Fix the 1 string : 1 HTTP request : 1 DB flush pattern.** `TargetWorkflow::onTransition()`
   translates a single `Target` per Messenger message: one `TranslationRequest`, one POST, one
   `$em->flush()`. Meanwhile `LibreTranslateEngine::translateBatch()` already exists, already
   sends `q` as an array, and is **never called by anything**. Grouping a message per
   (source, target locale) chunk instead of per string removes 19 of every 20 round trips and
   19 of every 20 flushes. Do this because it removes work, not because the benchmark promises
   a speedup.

3. **Give babel more CPU.** This is where the real throughput is. `LT_THREADS` is not set in
   `~/sites/libretranslate/Dockerfile`, so it runs on the image default; the run-to-run
   variance above is consistent with a small worker pool contending with other load on
   dokku-ash. The service repo's README already makes the argument — babel is cattle and "can
   be rebuilt from scratch, moved to a faster box for a big batch job, or destroyed and
   recreated, with no data loss." A big backfill is exactly that case.

4. **Measure server-side before tuning further.** Everything here is measured from the
   client, through Cloudflare, from a laptop. The 160 ms floor and the 3× swings could be
   babel, the box, or the network in front of it, and the client-side numbers cannot tell
   those apart. `LT_THREADS` and container CPU are the next things to look at, on the host.

## Bearing on the JSON-RPC work

None of this argues against Phase 1/2 in `JSONRPC.md`, but it does re-aim the claim made
there. JSON-RPC batching is worth having for the **app ⇄ lingua** leg — that is a chatty
metadata protocol where a round trip costs ~200 ms and carries almost no compute, so
amortizing it is a real win, and it is what made direct DB access tempting.

The **lingua → babel** leg is compute-bound and is not fixed by protocol choice. Do not
justify the RPC migration with babel throughput numbers; justify it with the app leg.
