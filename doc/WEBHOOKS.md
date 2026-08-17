# lingua webhooks

lingua **sends** `translation.completed` when strings a client asked for have been translated.
It receives no webhooks.

The cross-service contract — naming, signatures, how to write a receiver — lives in
[kit-bundle/docs/webhooks.md](../vendor/survos/kit-bundle/docs/webhooks.md). This page covers
what is specific to lingua.

---

## The problem this solves

Translation is asynchronous: `translateBatch` queues work and returns immediately, and the
client had no way to learn it had finished except `lingua:pull` on a timer — a poll that usually
finds nothing, and that nobody remembers to schedule until a locale turns up empty in
production.

Now a client can ask to be told.

---

## Subscribing

Send `callbackUrl` and `refs` with a batch:

```json
{
  "source": "en",
  "target": ["es", "fr"],
  "texts": ["Browse the collection", "Search"],
  "refs":  ["str-code-1", "str-code-2"],
  "callbackUrl": "https://zm.survos.com/webhook/lingua",
  "engine": "libre"
}
```

- **`refs`** is the caller's OWN key per text, positionally aligned with `texts` — babel's
  `Str.code`. lingua echoes it back verbatim. This exists because lingua identifies a string by
  a content hash while the caller identifies it by its own code, and those are not the same
  value; reconciling them is exactly the hash→code map `lingua:pull` rebuilds on every run.
  A text with no ref is still translated, just not subscribed.
- **Omit both** and nothing changes: no subscription rows are written and the polling contract
  is untouched.

`survos/lingua-bundle` fills `callbackUrl` in from `LINGUA_CALLBACK_URL` automatically, so an
app that sets that env var gets webhooks without changing any calling code.

### Why a subscription table

A `Target` is `(source, targetLocale, engine)` and `Source` is deduplicated by a hash of the
text — so when two apps ask for "Untitled" in Spanish they get the **same** `Target` row.
Hanging `callbackUrl` off `Target` would mean the second app to push silently steals the first
app's notifications. With zm, bts, harvest and openfoto all pushing overlapping UI strings that
is the normal case, not an edge case. Hence `translation_subscription`, unique on
`(target, callback_url)`.

Re-pushing the same string clears `notifiedAt`, so it is announced again — that is the supported
way to recover from a delivery the receiver lost.

---

## What gets sent

One webhook carries up to 500 translations for one subscriber, with the text inline:

```json
{
  "event": "translation.completed",
  "count": 2,
  "translations": [
    {"ref": "str-code-1", "targetLocale": "es", "engine": "libre",
     "text": "Examine la colección", "identical": false, "sourceLocale": "en"}
  ]
}
```

Inline rather than "come and fetch them": a translated UI string is a few dozen bytes, so a page
of them is smaller than the round trip that would replace it, and a self-contained payload means
the client's write path does not depend on lingua being up when it drains its queue.

`identical: true` means the engine returned the source unchanged. That is a real answer, not a
failure — a client that only accepted `translated` would wait forever for every proper noun it
ever pushed.

---

## Batching, and why the transition stays silent

The obvious implementation — fire from the translate transition — floods. A `lingua:push` of
5,000 strings into three locales is 15,000 transitions, and one webhook each is 15,000 HTTP
requests to a subscriber that wanted a handful of batches. It is also the exact shape that
buried mediary's queue when every workflow transition dispatched its own Meilisearch job.

So `App\Message\FlushTranslationNotificationsMessage` coalesces instead. Each run announces one
page per subscriber and then decides:

- page came back **full** → re-dispatch immediately, more is ready now
- nothing sent but rows still **waiting** on the translator → re-dispatch after 30s
- nothing pending → **stop**

Steady state is an empty queue, not a heartbeat. The chain gives up after ~1 hour of
never-finishing translations; `lingua:webhook:flush` is the way back in.

---

## Configuration

```bash
# Signing key. Every subscriber needs the SAME value in its own environment.
# Empty ⇒ TranslationNotifier refuses to send rather than send unsigned.
LINGUA_WEBHOOK_SECRET=
```

Deliveries and the flush share the `webhook` transport, so pausing announcements is one
decision rather than two, and a subscriber being down cannot stall `target.translate`.

---

## Running it

```bash
bin/console messenger:consume target.translate -v   # translate (existing `translator` worker)
bin/console messenger:consume webhook -v            # flush + deliver
bin/console lingua:webhook:flush --all              # manual drain
bin/console messenger:failed:show
```

The `webhook` transport needs a worker in the Procfile. Queued and never consumed looks exactly
like never sent.

---

## Removed

`Survos\LinguaBundle\Controller\LinguaWebhookController` and the `survos_lingua.webhook_key`
setting are gone. The controller checked an `X-Api-Key` by hand, its route was never registered,
and its own docblock recorded that the `lingua.webhook_key` parameter it originally read had
never been defined anywhere either — it was a placeholder for this. The inbound secret is now
`framework.webhook.routing.lingua.secret`, where `symfony/webhook` expects it and where it is a
`#[\SensitiveParameter]`.
