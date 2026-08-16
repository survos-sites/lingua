# JSON-RPC for the Lingua API — plan

**Status: plan only, nothing implemented.** Written 2026-08-16, after babel.survos.com moved
to Dokku (`~/sites/libretranslate`) and lingua/lingua-bundle were left untouched.

Reference implementations to copy from:
- `~/sites/mediary/doc/JSONRPC.md` + `mediary/src/RPC/V1/ProbeAssetsMethod.php` — the same
  bundle (`otezvikentiy/json-rpc-api ^5.1`), one read-only method, shared service so REST and
  RPC cannot drift. Mediary commit `814ff08`.
- `~/sites/depot/docs/json-rpc-bundle-multipart-pr.md` — the deep dive on the bundle's
  internals, from writing [PR #9](https://github.com/OtezVikentiy/symfony-jsonrpc-api-bundle/pull/9).

## The chain, and where JSON-RPC stops

    app → lingua-bundle (LinguaClient) → lingua.survos.com → babel.survos.com
          ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^     (LibreTranslate v1.9.5)
          this leg is ours; this is the JSON-RPC candidate      not ours — REST, upstream

The babel leg is LibreTranslate's own HTTP API. It is not a candidate for anything: we do not
own the wire format, and babel is deliberately cattle. **JSON-RPC stops at lingua's front door.**

Verified working 2026-08-16 (so nothing below is fixing an outage):

```bash
curl -s -X POST https://babel.survos.com/translate -H 'Content-Type: application/json' \
  -d '{"q":"hello world","source":"en","target":"es","format":"text"}'
# {"translatedText":"hola mundo"}
```

## What the app→lingua surface actually looks like today

| Route | Defined in | State |
|---|---|---|
| `POST /batch-translate` | `ApiController::batchRequest` | Real. `#[MapRequestPayload] BatchRequest`, then wraps the result in `{status:'ok', response:{…}}` — an envelope the client unwraps speculatively in three separate places. |
| `POST\|GET /babel/pull` | `ApiController::pullBabel` | Real. Hand-parses `$request->toArray()`, validates `hashes[]` by hand, returns ad-hoc `{"error": "…"}` at assorted 400s. Client sends **both** `hashes` and `keys` "for back-compat" and then unwraps a `response`/`data` envelope that the server never sends. |
| `GET /source/{hash}.json` | `AppController::app_source` | Real, but `LinguaClient::getSource()` has **no caller anywhere**. |
| `GET /job/{id}.json` | — | **Route does not exist.** `LinguaClient::getJobStatus()` + `ROUTE_JOB` call a 404. No caller either. |
| `GET /get-translations` | `ApiController::getTranslations` | Dev leftover; returns a Twig template *or* a JsonResponse depending on input. |
| `GET /api/sources`, `/api/targets` | API Platform | Fine. Read-only. **Leave alone.** |
| `ANY /mcp` | `mcp_server.entrypoint_controller` | **500 in production.** Identical dead `ecourty/mcp-server-bundle` route that mediary deleted on 2026-08-16 (commit `5aed133`). |

### Three clients for one server

1. `Survos\LinguaBundle\Service\LinguaClient` — the live one. Route constants live here.
2. `Survos\Lingua\Contracts\Http\LinguaApi` (lingua-contracts) — "Wire-level constants…
   keep this framework-agnostic and stable." Declares `ROUTE_BATCH = '/api/lingua/batch'`
   and `ROUTE_PULL = '/api/lingua/pull'`. **Neither route exists.** Unused.
3. `Survos\LibreTranslateBundle\Service\TranslationClientService` (libre-bundle) — hardcodes
   `https://translation-server.survos.com` (**404 today**), carries its own `calcHash()`
   duplicating `HashUtil::calcSourceKey()`, and is **registered by babel-bundle's
   `config/services.php`**, so it is live in every babel app.

### No authentication, at all

`lingua/config/packages/security.yaml` is stock Symfony: one lazy firewall, `users_in_memory`,
`access_control` entirely commented out. Verified anonymously against production:

```
POST https://lingua.survos.com/babel/pull  → 200 []
GET  https://lingua.survos.com/api/sources.jsonld → 200
```

`/batch-translate` creates rows and can spend DeepL money. `LinguaClient` sends both
`X-Api-Key` and `Authorization: Bearer`; the server reads neither. `survos_lingua.api_key`
in lingua's own config is lingua-bundle's *client* config (lingua installs its own bundle for
the in-process sub-request short-circuit), not a server-side check.

This is the same conclusion mediary reached — its `ProbeAssetsMethod` docblock says the batch
push stays on REST until "the bundle's auth story [is] settled first, which is the actual
reason to adopt it."

## Plan

### Phase 0 — cleanup first, no JSON-RPC involved

Cheap, independent, and it stops the migration from carrying dead weight forward.

1. **lingua:** delete the dead `/mcp` route and the `ecourty/mcp-server-bundle` leftovers.
   Follow mediary `5aed133`. If lingua wants an agent surface, it comes back as `/_mcp` from
   `symfony/mcp-bundle`, dev/test-gated, as in mediary.
2. **lingua-bundle:** delete `LinguaClient::getJobStatus()` and `ROUTE_JOB`.
3. **lingua-bundle:** delete `LinguaClient::getSource()`, or wire it to something. It is dead.
4. **lingua:** delete or clearly mark `GET /get-translations`.
5. **lingua-contracts:** `LinguaApi`'s two route constants are wrong. Either delete the class
   or make Phase 1 the moment they become true (see below) — do not leave fiction in a file
   whose docblock claims it is the stable wire contract.

### Phase 1 — `pullTranslations`, the read half

Mirrors mediary's choice exactly: migrate the **read-only** half first, where being wrong
costs nothing, and prove the bundle on real traffic before the write path.

- `composer require otezvikentiy/json-rpc-api ^5.1` in lingua; endpoint at `POST /api/v1`.
- Extract the DQL currently inside `ApiController::pullBabel()` into a
  `TranslationPullService` — this is mediary's `AssetProbeService` pattern, and the reason
  REST and RPC cannot drift.
- `App\RPC\V1\PullTranslationsMethod` with a DTO request: `hashes: list<string>`,
  `locale: ?string`, `engine: ?string`. Params arrive deserialized and type-checked instead
  of `json_decode` + `array_filter` + three hand-written 400s.
- Response returns **`translations` and `missing`**. Today `/babel/pull` returns a bare map,
  so the client cannot distinguish "hash unknown to lingua" from "known but not yet
  translated" — the same silent-short-answer problem `probeAssets` fixed with its
  `missing` list. This alone is worth the phase.
- Client: add an RPC path to `LinguaClient::pullBabelByHashes()` behind config, keeping REST
  as the default until it is proven. Drop the `keys` duplicate and the speculative
  `response`/`data` unwrapping on the RPC path — the envelope is defined, not guessed.

### Phase 2 — `translateBatch` **plus auth, together**

The write path is where JSON-RPC actually pays, and it must not ship without authentication.

- `translateBatch` taking the contracts `BatchRequest`. `TranslationIntakeService` already
  does the work, so the method is thin.
- **Batching is the real win.** `lingua:push` today loops one HTTP request per 200-row batch,
  per (source, target) locale pair. A JSON-RPC batch sends every locale group in one request.
- **Auth lands here.** The bundle's swagger config already names `X-AUTH-TOKEN`; lingua needs
  a real firewall + `access_control` in front of `/api/v1` (and, separately, in front of the
  surviving REST routes and API Platform, which are open today regardless of this migration).
  `SURVOS_LINGUA_API_KEY` already flows from every client — the server just has to check it.
- Keep `POST /batch-translate` as-is during the transition; consumers are `zm`, `bts`,
  `harvest`, and lingua itself.

### Phase 3 — one client, one contract

- Method names + request/response DTOs move into `lingua-contracts`, replacing the two wrong
  `LinguaApi` route constants with something true.
- `TranslationClientService` (libre-bundle) is repointed at lingua or deleted, and
  babel-bundle's `services.php` rewired. **This one needs Tac's call before touching** —
  babel-bundle is in every translating app, and the service being wired to a dead host
  suggests some path through it is already unused, which is worth confirming rather than
  assuming.

## Decisions needed

1. **Auth scheme for lingua** — shared static `X-AUTH-TOKEN` per client app (matches what
   the bundle and `SURVOS_LINGUA_API_KEY` already assume), or something per-tenant. Phase 2
   is blocked on this; Phases 0 and 1 are not.
2. **libre-bundle's `TranslationClientService`** — delete, or repoint? (Phase 3.)
3. **Does lingua want an MCP surface?** "Translate these strings" / "what's the coverage for
   locale X" are decent agent tools, and MCP *is* JSON-RPC 2.0, so `/_mcp` and `/api/v1` can
   coexist the way they do in mediary. Not required by anything above.
