# Native translation worker (dev only)

Lingua can send translations to the ai-tools native EuroNano worker over RabbitMQ instead of
calling an HTTP engine. Wire contract: `~/.config/survos/translation-worker-design/contract-v1.md`.
Production config is untouched: the worker profiles, transports and routing exist only in
`when@dev` / `when@test`. In dev, state-bundle's per-transition queues (`target.translate`) run on
Doctrine so they are inspectable rows. Production stays on RabbitMQ.

## Profiles

`euronano-tiny-v1` and `euronano-base-v1` (`config/services.yaml`, `dispatch: worker`). The profile
name is the Target `engine`, so each profile has its own translation memory, and Libre/DeepL
rows are never reused or overwritten. `languagePairs` is whatever the worker accepts. That is
the worker's decision; today it is de/es/fr/it/pt/cs/fi/nl/sv → en. Change the config, not the code.
Each profile publishes to its own routing key (`<profile>.to-en`), so Tiny and Base workers
can run at the same time.

## Flow

Worker profiles use the existing batch path, so intake is unchanged:

1. `POST /batch-translate` (or JSON-RPC `translateBatch`) with `"engine": "euronano-tiny-v1"`.
   Pair validation happens before any write. Already-translated Targets are memory hits and are
   not queued, exactly as for Libre/DeepL.
2. `TranslateBatchMessageHandler` (queue `target.translate`) sees a `dispatch: worker` profile.
   Instead of calling an engine, it dispatches one `TranslationJobRequest` per Target to the
   send-only `translation_jobs` transport. Before that, it applies and commits TargetWorkflow's
   `dispatch` step (u → q), so a fast result always finds the Target in `q`. The transport
   publishes plain JSON with mandatory + publisher confirms. If the broker refuses or does not
   confirm, that Target is `reject`ed back to `u` and the batch throws, so Messenger retries it. Targets already
   `q`, `t` or `i` are skipped, because `can(dispatch)` is false.
3. `request_id` is the Target key: 16 hex characters, deterministic per (source, locale,
   profile). The result maps straight back to its row, with no job table.
4. The worker publishes `translation.result` to `lingua.results`. The `translation_results`
   transport decodes it into `TranslationResultMessage`, and `TranslationResultProcessor` takes
   one of these paths:
   - completed: `TargetTranslationApplier` writes the text and the pinned provenance (model,
     revision, variant, runtime, quantization, beam_size). Then the workflow moves the Target
     through whichever guard passes: `receive` (q → t, text differs from source) or
     `receive_identical` (q → i). The existing callback drain is scheduled in the same
     transaction.
   - Target already t/i (a duplicate): acked, and nothing changes.
   - worker failure or empty text: `reject` (q → u), with no callback, and the next push resends
     it. The message is parked in the Doctrine `failed` transport with the error.
   - malformed body, unknown key, wrong profile/locales, or a model/revision/variant that
     disagrees with the profile: parked in `failed`, never applied.

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
bin/console messenger:setup-transports translation_results   # exchange + results queue + binding
# the worker declares its own job queues + DLQs (ai-tools: --declare-only)

# TWO processes: the AMQP results receiver blocks the worker loop and starves Doctrine
# transports sharing its process.
bin/console messenger:consume target.translate translation_notifications webhook -vv
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

Inspect: `bin/console state:stats Target` (u/q/t/i per marking) and `bin/console messenger:failed:show`.
Never point these transports at production `target.translate` or another vhost.
