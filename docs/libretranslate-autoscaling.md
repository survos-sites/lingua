# On-demand scaling of LibreTranslate (fsn1)

`libretranslate.web.1` holds ~2.2 GB RSS but is used only while translating. Lingua scales the Dokku
app **0 → 1** when work is queued and **1 → 0** after N idle minutes with an empty queue.
Default is **off** (`ENGINE_AUTOSCALE_ENABLED=0`): nothing changes until it is switched on.

## How Lingua reaches LibreTranslate today

- HTTP only: `LibreTranslateEngine` (survos/translator-bundle) `POST {LIBRE_HOST}/translate`, `q` as an array.
  `LIBRE_HOST=https://libretranslate.survos.com`. Scoped client with Symfony `retry_failed`
  (stock strategy: 3 retries, ~1 s/2 s/4 s backoff, i.e. ~7 s of patience — far less than a 30–60 s model load).
  No explicit request timeout.
- Intake (`TranslationIntakeService`) validates language pairs via `TranslationEngineCatalog::languages()`
  (`GET /languages`, cached 1 h) **before** queueing, then dispatches `TranslateBatchMessage` to
  `target.translate` (RabbitMQ in prod). The `translator` process (Procfile) consumes it;
  `TranslateBatchMessageHandler` makes the engine call synchronously. There is **no callback** for
  libre — the response is applied in-process; the webhook/`lingua_results` flow is Lingua → subscriber
  after completion, unrelated to the engine.
- Legacy per-string path: `TargetWorkflow::onTransition` (queued `TransitionMessage`s).
- Only Lingua calls the engine in production. Harvest `translation send` and Europeana `euro/*` go through
  Lingua's API. (Harvest/bts `.env` only point `LIBRE_HOST` at localhost; `dummy` points at
  `babel.survos.com`, the second vhost of the same app — dev, but it would hit a stopped engine.)
- Newspapers are never translated (`NewspaperTranslationPolicy`), so they add no load.

## Dokku layout (read-only inspection, Dokku 0.38.27)

`libretranslate`: one `web` process, running; vhosts `libretranslate.survos.com`, `babel.survos.com`,
`libretranslate.fsn1-survos`; restart policy `on-failure:10`; app.json startup check `GET /languages`
(initialDelay 60 s, 40 × 15 s). Dokku has no native scale-to-zero. At `web=0`, nginx has no upstream
(expect 502/404 on the vhosts) — which is why Lingua polls the health URL itself and never relies on the proxy.

## Design

```
intake ──requestUp()──┐                       worker (translator process)
 (web container)      │ scaleTo(1), throttled   ensureReady(): health? else scaleTo(1) + poll /languages
                      ▼                         → EngineNotReadyException (Recoverable, 30 s) if still down
        EngineAutoscaler ── shared DB cache ──  touch() before/after every engine call
                      │ scaleTo(n)              WorkerRunningEvent (idle, ≤1/min): scaleDownIfIdle(queueDepth)
                      ▼
        EngineScalerInterface ── SshEngineScaler ──ssh──▶ forced command ▶ dokku ps:scale … --skip-deploy ; ps:restart
```

- **Scaler contract takes a replica count** (`scaleTo(int)`), clamped to `ENGINE_AUTOSCALE_MAX_REPLICAS` (1).
  `EngineAutoscaler::replicasFor(queueDepth, idle)` is the single place a depth-sized fleet would change.
- **First calls wait, not fail.** `ensureReady()` blocks ≤ `ENGINE_AUTOSCALE_READY_TIMEOUT` (180 s), then throws a
  `RecoverableMessageHandlingException` (retried after 30 s, **not** counted against max_retries). Intake also fires
  a non-blocking `requestUp()` so the model loads while the queue drains.
- **Idle scale-down** in the `translator` worker's idle loop (no cron container on a starved host). Conditions:
  `target.translate` message count == 0 **and** no `touch()` for `ENGINE_AUTOSCALE_IDLE_MINUTES` (10).
  Activity is stamped before *and after* each call, so a long batch is covered. If the transport cannot report its
  depth the command/listener leaves the engine running (fail safe). Unacked in-flight messages are not in the count;
  the idle window covers them.
- **State** is in a DB-backed pool (`engine_autoscale.cache`), because `cache.app` is per-container filesystem.
- **`/languages` stale fallback:** the catalog now keeps the last good list 7 days and serves it if the refresh fails,
  so intake validation keeps working while the engine is down (a refresh never wakes the engine).
- Manual: `bin/console app:engine:autoscale` (idle check) / `--scale=0|1` (force).

### Config (env)

| var | default | meaning |
|---|---|---|
| `ENGINE_AUTOSCALE_ENABLED` | `0` | master switch |
| `ENGINE_AUTOSCALE_IDLE_MINUTES` | `10` | idle window before scale-down |
| `ENGINE_AUTOSCALE_READY_TIMEOUT` | `180` | seconds `ensureReady()` waits |
| `ENGINE_AUTOSCALE_MAX_REPLICAS` | `1` | hard cap (only 0/1 implemented) |
| `ENGINE_AUTOSCALE_SSH_TARGET` / `_SSH_KEY` | – | `libre-scale@<host>` and key path in the container |

## Why SSH forced command, not an endpoint

No new always-on service on a memory-starved host. The key can run exactly `scale 0|1` (`docs/ops/libre-scale`
validates `SSH_ORIGINAL_COMMAND`; `restrict` disables shell/forwarding) and nothing else; the Dokku calls happen
as the `dokku` user through a one-line sudoers rule. Lingua never holds root or a general Dokku key.
Never `ps:stop`: it stops the whole app.

## Rollout plan (nothing below has been run; each step needs Tac's go-ahead)

0. **Rehearsed 2026-11-01** on a throwaway app `scale-rehearsal` (traefik/whoami, since destroyed), Dokku 0.38.27:
   - `ps:scale web=0 --skip-deploy` alone changes nothing; `ps:restart` applies it. After restart no container runs
     (an old one is retired within the 60 s `wait-to-retire` window if still present), `ps:scale` then prints no `web` row, and
     the vhost answers **502**. `web=1 --skip-deploy` + `ps:restart` brings it back (200). `ps:restart` returned in
     ~25 s here (no startup check; libretranslate's 60 s+ check will make it longer — hence the detached wrapper).
   - **Surprise:** in the first cycle `dokku/<app>:latest` disappeared and scale-up failed with "App image not found"
     (fixed by `ps:rebuild`). It did not recur in two further cycles and the cause is unknown, so `libre-scale-apply`
     falls back to `ps:rebuild`. For libretranslate that is a rebuild from a pinned public base image; confirm the
     host still has `libretranslate/libretranslate:v1.9.5` layers and the model volume before enabling.
   - Not rehearsed: persistence of 0 across a host reboot (Dokku restores per its scale file; check before relying on it).
1. Host (root via `fsn1-root`): `adduser --system --shell /bin/sh libre-scale`; install `docs/ops/libre-scale` and
   `libre-scale-apply` to `/usr/local/bin` (0755, root); `/etc/sudoers.d/libre-scale`:
   `libre-scale ALL=(dokku) NOPASSWD: /usr/local/bin/libre-scale-apply 0, /usr/local/bin/libre-scale-apply 1`;
   generate an ed25519 key; `~libre-scale/.ssh/authorized_keys` =
   `command="/usr/local/bin/libre-scale",restrict <pubkey>`.
2. Lingua (dokku): `storage:mount` a dir holding the private key (0600) and `known_hosts` (host key of the address
   the container uses); `config:set ENGINE_AUTOSCALE_SSH_TARGET=libre-scale@<host> ENGINE_AUTOSCALE_SSH_KEY=/app/scaler/id_ed25519`.
   Deploy (image now includes `openssh-client`), then `ssh-test` from the container: `app:engine:autoscale --scale=1` (no-op while up).
3. Optional: lower `initialDelay` in libretranslate's `app.json` (60 s is pure wait on every wake) — next libretranslate deploy.
4. Enable: `config:set ENGINE_AUTOSCALE_ENABLED=1` (+ restart `translator`). Queue a small batch while it is up
   (no-op), then `--scale=0` and queue one string: expect scale-up, wait, success. Watch `/var/log/libre-scale.log`.
5. Do it when no Harvest export is relying on libre output; idle shutdown then frees ~2.2 GB.

### Rollback

`config:set ENGINE_AUTOSCALE_ENABLED=0` (+ restart web/translator) makes Lingua treat the engine as always-on again;
then `dokku ps:scale libretranslate web=1 --skip-deploy && dokku ps:restart libretranslate`. Remove the key line from
`~libre-scale/.ssh/authorized_keys` to revoke the mechanism. No schema changes; the DB cache table is created lazily.

## Known limits

- Other consumers of `libretranslate.survos.com`/`babel.survos.com` (outside Lingua) see 502s while it is stopped.
- Cold start ≥ 60 s today (healthcheck initialDelay), plus model load; first batch after idle is slow by design.
- Multiple translator workers share the throttle via the DB cache, but two simultaneous scale requests are harmless
  (`libre-scale-apply` is flock-serialised and idempotent).
