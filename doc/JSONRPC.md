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

### Phase 0 — cleanup first, no JSON-RPC involved — **DONE 2026-08-16**

lingua `7936cb6`, mono `3e491a9e`.

1. ~~**lingua:** dead `/mcp` route + `ecourty/mcp-server-bundle` leftovers.~~ Gone. MCP was an
   experiment in tools-over-JSON-RPC as the agent interface; the direction is now plain
   JSON-RPC with token auth, so it was deleted rather than rebuilt on `symfony/mcp-bundle`.
2. ~~**lingua-bundle:** `getJobStatus()` / `ROUTE_JOB`.~~ Gone.
3. ~~**lingua-bundle:** `getSource()` / `ROUTE_SOURCE`.~~ Gone.
4. ~~**lingua:** `GET /get-translations`~~ and the private, unrouted `receiveBatchRequest()`
   stub. Both gone.
5. ~~**lingua-contracts:** `LinguaApi`'s wrong route constants.~~ Corrected, and deliberately
   **not** wired into either end.

**Worth knowing, learned by getting it wrong:** the obvious cleanup — have both ends read
`LinguaApi::ROUTE_*` so there is one source of truth — was tried and reverted. lingua deploys
on a *published* lingua-contracts, so pointing `#[Route(...)]` at the constant moved the live
routes to `/api/lingua/*` the moment a vendor copy predated the fix. Constants that define a
wire contract cannot safely be shared across a version boundary unless both ends move
together. They are corrected as documentation to check against; Phase 3 can make contracts
the real owner, in one release, on purpose.

Verified after: `debug:router` shows `/batch-translate` and `/babel/pull` unmoved and no
`/mcp`; `lint:container` and `cache:warmup` clean. lingua's three pre-existing tests still
error for unrelated reasons — `symfony/browser-kit` is not installed (2) and
`BatchRequestTest` imports `Survos\LinguaBundle\Dto\BatchRequest`, a namespace that no longer
exists (1). Untouched here; worth fixing before Phase 1 adds real tests.

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

Baseline to migrate against, captured 2026-08-16 on the local server:

```bash
curl -s -x http://127.0.0.1:7080 -X POST https://lingua.wip/babel/pull \
  -H 'Content-Type: application/json' \
  -d '{"hashes":["e69eb61789730bd1","5eec6ce370d255d5","33aa52fedd637176"]}'
# {"33aa52fedd637176":"編輯%entity_label_singular%","5eec6ce370d255d5":"नमस्ते, दुनिया","e69eb61789730bd1":"god morgen"}

curl … -d '{"hashes":["deadbeefdeadbeef"]}'
# []      <- note: empty *array*, not object. The response type flips shape on no-hits.
```

#### On streaming

**The bundle cannot stream, and it is worth being clear about that before building on it.**
`otezvikentiy/json-rpc-api` 5.1 has no `StreamedResponse`, no `text/event-stream`, and no
generator path anywhere in `src/` — `ApiController::index()` returns a fully-buffered
`OvResponseInterface`. Streaming JSON-RPC is an MCP extension (that is what SSE / streamable
HTTP were added for), not part of JSON-RPC 2.0, and MCP is the thing being dropped here.

The two features it *does* have address the same pain more directly:

- **Batch** — an array of request objects in one POST. This is the real fix for
  round-trip latency: `lingua:push`/`lingua:pull` currently make one HTTP request per
  200-row chunk per locale pair, and that serial chatter is what made direct DB access
  tempting in the first place.
- **Notifications** — a request with no `id` gets no reply at all (`docs/notifications.md`).
  The right shape for fire-and-forget pushes where the client does not wait on a result.

If genuine streaming is wanted later — results trickling back as each locale finishes rather
than one answer at the end — that is a separate transport decision (SSE endpoint, or Mercure),
not something to expect from this bundle.

#### The direct-database hack

There was a point where the exchange felt slow enough that an app was pointed straight at
lingua's database and queried by hash. **Verified gone as of 2026-08-16**: no second Doctrine
connection or `LINGUA_DATABASE_*` env var in zm, harvest, bts or openfoto, and nothing in
lingua-bundle or lingua-core opens a connection — `LinguaPullBabelCommand` goes through
`LinguaClient::pullBabelByHashes()` over HTTP like everything else.

Worth naming anyway, because it is the actual design constraint: the hack existed because
per-chunk HTTP round-trips are slow, and if RPC does not fix that, the same pressure returns.
Batch is the answer (above), and it is the reason Phase 1 is worth doing beyond tidiness.

### Phase 1 — **DONE 2026-08-16**, lingua `fcce320`, mono `b49d09c4`

`POST /api/v1`, method `pullTranslations`. `TranslationPullService` holds the query and both
transports call it. Verified live: real hashes answered, `missing` isolates an unknown hash,
a two-element batch answered in one round trip with per-element ids, `-32602` for bad params,
REST unchanged.

Two things testing caught that reading would not have:

- **The bundle compiles validators from property *types* only** and ignores `Assert`
  attributes on the DTO (`docs/validation.md`). `{"hashes":[{"a":1}]}` satisfied the `array`
  check and then threw in `strval()`, surfacing as `-32603 Internal error`. The element check
  is explicit now, as a `\TypeError` — the bundle's documented signal, converted to `-32602`.
- **The Request DTO cannot follow CONVENTIONS.md.** `CompilerPass` throws
  "Property … has no accessible getter" at container build for any property without one, and
  optional params are applied via setters. Public promoted properties were tried; the
  container would not compile. The *Response* has no such constraint and is written the
  conventional way — public promoted readonly, no accessors.

One claim in this document was over-stated and is corrected in the code: the RPC response
does **not** fix the `[]`-vs-`{}` type flip on an empty result. The bundle's serialiser
normalises every value to a PHP array before encoding, so an empty map encodes as `[]`
either way. Fixing it needs a different shape (a list of `{hash, text}` entries), which is a
heavier contract than the empty case is worth. `missing` was always the substantive fix.

### Phase 2 — the shared key, **plumbing landed inert 2026-08-16**

`survos_lingua.api_key` (`LINGUA_API_KEY`) is one value installed on lingua *and* on every
app that calls it: clients send it as `X-Api-Key`, lingua compares with `hash_equals`.
`LinguaKeyGuard` (lingua-bundle) plus `LinguaApiKeyListener` (lingua) guard `/api/v*`,
`/batch-translate` and `/babel/pull`.

**With no key set it allows everything — exactly today's behaviour** — so this is safe to
deploy before the key is distributed. Turning it on is a deployment step: set the same value
on lingua and on zm/bts/harvest *together*, or callers start getting 401s. Verified all six
cases live (unset → 200; missing → 401; wrong → 401; correct `X-Api-Key` → 200; correct
`Bearer` → 200; `/health` untouched).

Configuration is resolved in `SurvosLinguaBundle::loadExtension()` and handed to services as
explicit typed arguments — no `#[Autowire('%env(...)%')]` in a constructor and no opaque
`$config` array. That pass also fixed a latent bug: `LinguaWebhookController` autowired
`param: 'lingua.webhook_key'`, a parameter defined nowhere, which had never fired only
because its route is not registered.

**Key scope: decided.** One shared secret, not per-tenant and not sharded. The endpoint has
been open; the goal is to close it, not to build an access-control system. If per-tenant keys
are ever wanted, `LinguaKeyGuard` is the one place that changes.

### Phase 2 — `translateBatch` **DONE 2026-08-16**

`translateBatch` on `POST /api/v1`, sharing `TranslationIntakeService` with
`POST /batch-translate` exactly as `pullTranslations` shares `TranslationPullService` with
`/babel/pull`. The REST route is untouched — zm/bts/harvest still call it, and a test pins
its `{"status":"ok","response":{…}}` envelope.

Three improvements over REST, all tested:

- **Errors are errors.** A rejected payload used to come back as
  `{"status":"ok","response":{"error":"Invalid payload: …"}}` at HTTP 200 — the caller had to
  dig into a *successful* response to discover nothing happened. Now `-32602` with the
  reason, via `JRPCException`, which is the pattern the maintainer's walkthrough describes.
- **Unknown engines are caught at the edge.** Nothing validated `engine` before: an unknown
  one created Target rows, queued them, and then failed one at a time *inside the worker* at
  `TargetWorkflow`'s `Assert::inArray` — after the write, asynchronously, where the caller
  never saw it. Now `-32602` naming the configured engines, before anything is written. This
  is the "more reliable" half.
- **Batch.** Several (source, target-locale) groups in one round trip instead of one HTTP
  call per chunk per locale pair.

`status` is gone from the result: JSON-RPC says success by returning `result` rather than
`error`, so the REST route's double envelope was redundant twice over.

Two things worth remembering, both found by testing:

- **Defaults must be on the property, not only the constructor parameter.** The bundle decides
  required-vs-optional with `$propertyType->allowsNull() || $property->hasDefaultValue()`, so
  `bool $insertNewStrings = true` in the signature alone still made it required, and every
  caller that omitted it got "[insertNewStrings] - This field is missing."
- **Overriding `async` in the test env does nothing for this path.** state-bundle registers
  its own per-transition transport, `target.translate` on `doctrine://default`, and
  `AsyncQueueLocator::stamps()` routes there — overriding any transport stamp built from the
  payload. Tests count rows in `messenger_messages` instead.

Still outstanding: `LinguaClient` has no RPC path, so nothing *calls* either method yet.

### Phase 2 — original note, kept for the reasoning

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
3. ~~**Does lingua want an MCP surface?**~~ Answered: not much MCP is wanted. RPC with token
   passing is the direction, and the dead `/mcp` route was removed in Phase 0.

## Reading: the maintainer's own walkthrough

[Build a JSON-RPC 2.0 API in Symfony in 15 Minutes](https://dev.to/otezvikentiy/build-a-json-rpc-20-api-in-symfony-in-15-minutes-from-composer-require-to-openapi-5bpk)
covers the same ground end to end and explains two things the DTOs alone do not.

**Required in the constructor, optional in setters, and why.** "The rule is simple:
constructor parameters are required, properties with setters are optional." The point is
that validation rules are "literally the PHP types of your DTO" — since 5.0 the comparison
is strict, so `"title": 123` is rejected rather than quietly cast. That is what
`PullTranslations\Request` is built around, and it is why `hashes` is a constructor arg
while `locale`/`engine` are setters.

Worth noting: the rule as described is not quite the rule as enforced.
`CompilerPass::getValidatorsForRequest()` requires a getter **and** a setter for *every*
property — so a required, constructor-injected param is also forced to expose a public
setter it never uses, and cannot be immutable. `analyzeRequestClass()` treats the setter as
optional, so the two passes disagree.

Raised upstream as
[issue #11](https://github.com/OtezVikentiy/symfony-jsonrpc-api-bundle/issues/11), with the
evidence rather than an opinion:

- The compiled getters are only ever *called* for a property typed as another class —
  `RequestHandler::processValidatorsForRequestInstance()` does
  `if (!class_exists($validatorItem['type'], false)) continue;` before reaching them. For
  `array $hashes` / `?string $locale` the getter is required at build and never invoked.
- The bundle already takes the opposite position on the response side.
  `SerialisesPublicSurface` exports "through a public getter, **or by being public
  itself**", and its docblock argues that requiring a getter "would protect nothing and
  would drop the promoted public properties that are the shortest honest way to write a
  response DTO". That is the request-side argument verbatim.
- Tried it: a ~10-line patch (public property satisfies the check; runtime falls back to
  reading the property) lets the conventional promoted-public DTO compile, and all 16
  functional tests pass unchanged — batch, filters, `-32602`, `-32601` included. Strict
  type validation is unaffected, since it derives from the property types either way. The
  patch was reverted; `Request` keeps its accessors until upstream decides.

**`JRPCException` for domain errors, sanitisation for everything else.** A deliberate
`throw new JRPCException('Task not found.', JRPCException::SERVER_ERROR)` becomes a proper
JSON-RPC error object, while any unexpected `Throwable` reaches the client as a generic
`-32603 Internal error` with the stack trace going to the log only — no leaking paths or
class names.

`pullTranslations` has no domain error to raise: an unknown hash is not a failure, it is a
`missing` entry, which is the whole point of the method. **`translateBatch` in Phase 2 is
where this matters** — an unknown engine, an unsupported locale pair, or a rejected payload
should be a `JRPCException` with a real code, not an exception that sanitises to a bare
`-32603` the caller cannot act on. The REST endpoint currently answers these with
`{"status":"error","error":"..."}` at HTTP 200, which is worse than either.

This also explains the `-32603` seen while building Phase 1: a nested array in `hashes`
passed the type check and threw deeper in, and the sanitiser did exactly what it promises —
turned it into a generic internal error. The fix was to make it a real `-32602` at the edge,
not to expose the internals.

## Upstream: profiler integration

`otezvikentiy/json-rpc-api` has **no Symfony Profiler integration** — no `DataCollector`, no
`data_collector`-tagged service, no templates. So a dev request shows the HTTP client call
and nothing about the RPC layer: which method ran, what params, result or `-32602`, how long.

Proposed upstream as
[issue #10](https://github.com/OtezVikentiy/symfony-jsonrpc-api-bundle/issues/10), modelled
on `symfony/ai-bundle`'s panel, which shows both the **registry** ("Registered Tools") and
the **traffic** (calls with inputs and results). Both halves already exist here and neither
is exposed:

- `MethodSpecCollection::getAllMethods()` → every registered method with its params, types,
  required/optional split, tags, roles and compiled validators. This is the AiTools-style
  "show the definitions" half.
- `JsonRpcCallLoggerInterface` is already the "something happened" seam — `logRequest()`
  takes the decoded element, `logResponse()` takes the response — so a `Traceable*`
  decorator needs no new instrumentation, and `SensitiveDataMasker` already solves masking.

The one real design question, left to the maintainer: `logging.enabled` defaults to `false`,
so a collector that merely decorates the configured logger would collect nothing by default,
and an empty panel is worse than no panel. Filed before writing code for that reason; PR to
follow if the maintainer agrees on the seam. Same route as the multipart work (issue #8 →
PR #9), which is documented in `~/sites/depot/docs/json-rpc-bundle-multipart-pr.md`.
