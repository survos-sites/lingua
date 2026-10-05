# Native translation worker (dev only)

Lingua can send translations to the ai-tools native EuroNano worker over RabbitMQ instead of
calling an HTTP engine. Wire contract: `~/.config/survos/translation-worker-design/contract-v1.md`.
Production config is untouched: the worker profiles, transports and routing exist only in
`when@dev` / `when@test`.

## Profiles

`euronano-tiny-v1` and `euronano-base-v1` (`config/services.yaml`, `dispatch: worker`). The profile
name is the Target `engine`, so each profile has its own translation memory, and Libre/DeepL
rows are never reused or overwritten. `languagePairs` is whatever the worker accepts. That is
the worker's decision; today it is de/es/fr/it/pt/cs/fi/nl/sv → en. Change the config, not the code.
Each profile publishes to its own routing key (`<profile>.to-en`), so Tiny and Base workers
can run at the same time.

## Flow

1. `POST /batch-translate` (or JSON-RPC `translateBatch`) with `"engine": "euronano-tiny-v1"`.
   Pair validation happens before any write.
2. A Target already translated or identical is a memory hit: nothing is sent, and the response
   `items` already carry the text. A Target with an active job is reported as `inFlight` and is
   not re-sent unless `forceDispatch` is set. `forceDispatch` supersedes the old job.
3. On a miss, a `translation_job` row (request_id UUIDv7, input snapshot, source hash) and a
   `PublishTranslationJobMessage` are written in ONE transaction. The `translation_outbox`
   Doctrine transport is on the same connection.
4. The outbox handler dispatches a `TranslationJobRequest` DTO to the send-only
   `translation_jobs` transport. That transport publishes plain JSON with mandatory + publisher
   confirms. An unroutable, nacked or unconfirmed publish throws, the outbox retries, and in
   the end the message lands in the Doctrine `failed` transport.
5. The worker publishes `translation.result` to `lingua.results`. The `translation_results`
   transport decodes the plain JSON into `TranslationResultMessage`. Then:
   - completed: applied via `TargetTranslationApplier` (translated vs identical). Provenance
     (model, revision, variant, runtime, quantization, beam_size, request_id) is stored on the
     Target, and the result plus metrics on the job. The existing callback drain is scheduled
     in the same transaction.
   - failed / empty: the error is stored on the job. The Target stays untranslated and no
     callback fires.
   - duplicate (job already terminal): acked, and `duplicate_results` is incremented.
   - stale (job superseded or source changed): recorded, not applied.
   - malformed body, unknown request_id, wrong profile/locales, or a model/revision/variant
     that disagrees with the profile: unrecoverable, so it goes to the Doctrine `failed`
     transport and is never applied.

## Message shapes: PHP is the source of truth

The DTOs in `src/Message/TranslationWorker` carry `#[WireShape(type, version)]`. The wire is
mechanical: snake_case property names, plus `type` and `schema_version` from the attribute.
Generate the Python side, never hand-edit it:

```bash
bin/console lingua:translation-worker:dump-python            # → var/translation-worker/
bin/console lingua:translation-worker:dump-python ../ai-tools/some/dir
```

This writes `lingua_translation_contract.py` (Pydantic v2) and `translation-worker.schema.json`.
They include `SHAPE_VERSIONS` and `SHAPE_FINGERPRINTS`. `TranslationWorkerContractTest` pins each
shape's version and fingerprint. Change a DTO and it fails until you bump the shape's version,
re-pin, and regenerate.

## Local setup (M4, podman `survos_rabbitmq`)

The broker is vhost `translation_dev`, and the user `translation_lingua` may touch only
`ai.translation.*` / `lingua.translation.*`. Its DSN lives in `.env.local` as
`TRANSLATION_WORKER_AMQP_DSN=phpamqplib://…@127.0.0.1:5672/translation_dev` (never committed).

```bash
bin/console doctrine:migrations:execute --up 'DoctrineMigrations\Version20261005220000'
bin/console messenger:setup-transports translation_results   # exchange + results queue + binding
# the worker declares its own job queues + DLQs (ai-tools: --declare-only)

# TWO processes: the AMQP results receiver blocks the worker loop and starves Doctrine
# transports sharing its process (the outbox then never drains).
bin/console messenger:consume translation_outbox translation_notifications webhook -vv
bin/console messenger:consume translation_results -vv
```

Real environment variables override `.env`. A shell launched for ANOTHER app (for example a dev3
pane of a zm task, which exports zm's `DATABASE_URL`) would point these consumers at that
app's database. In such a shell, start them with `env -i HOME="$HOME" PATH="$PATH" bin/console …`.

Sample request:

```bash
curl https://lingua.wip/batch-translate -x 127.0.0.1:7080 -H 'Content-Type: application/json' \
  -d '{"source":"fr","target":["en"],"engine":"euronano-tiny-v1","texts":["Médaille en bronze."],"refs":["demo-1"],"callbackUrl":"https://harvest.wip/lingua/callback"}'
```

Inspect: `translation_job` (status/outcome/error/metrics), `bin/console messenger:failed:show`.
Never point these transports at production `target.translate` or another vhost.
