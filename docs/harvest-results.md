# Harvest completion messages

HTTP batch intake stays unchanged, including cached translations in the response. The notifier can deliver completion events directly to Harvest's existing Messenger consumer instead of its webhook endpoint.

In production, `LINGUA_RESULTS_DSN` must connect to the existing RabbitMQ service's `harvest` vhost. It is configured in Dokku without storing credentials in Git. The transport uses the interop serializer, durable exchange `lingua.results`, routing key `harvest.prod`, and durable queue `harvest.lingua.results`.

`LINGUA_RESULTS_CALLBACK_URL` selects the exact subscriber whose results use RabbitMQ. Leave it unset during the Lingua-first deployment: all subscribers continue receiving HTTP webhooks. Once Harvest's matching transport and result worker are deployed, set it to `https://harvest.survos.com/webhook/lingua` and restart Lingua's notification worker. Other subscribers keep HTTP delivery.

After deploying Lingua, create/check the transport explicitly:

```bash
ssh dokku@fsn1 run lingua php bin/console messenger:setup-transports lingua_results
```

Harvest must consume `lingua_results` from the same vhost, exchange and queue, using `messenger.transport.interop_serializer`. This reuses `ConsumeRemoteEventMessage` and the existing translation consumer, including dataset guard wake-ups.

Local development uses `harvest.local` and the local `harvest` vhost. Production and local development keep separate brokers and credentials.
