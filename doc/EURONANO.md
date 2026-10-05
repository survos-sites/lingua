# Local EuroNano Base testing

The dev environment registers `euronano` at `http://127.0.0.1:8884` using the
LibreTranslate wire protocol. Start ai-tools with prepared Base weights first.
Override `EURONANO_HOST` and optionally `EURONANO_API_KEY` in local environment
configuration if necessary. This engine is not registered in production.

Set `engine` to `euronano` explicitly in the translation form, batch intake, or
JSON-RPC translateBatch request. The default remains `libre`; existing Libre
translations are not reused or overwritten as EuroNano results. Engine is part
of Lingua's target identity.

For the initial test use English source text and French or Spanish targets.
The endpoint supports English↔de/es/fr/it/pt/cs/fi/nl/sv, but no implicit pivot,
HTML, source auto-detection, or protected placeholders. Long segments over 120
model tokens return 422; split long descriptions into sentences. Send plain text.
The generic Libre adapter advertises HTML support, so do not rely on that
capability flag for this experimental engine.

ai-tools returns model/revision and elapsed/inference times; the current provider
adapter does not persist them in Lingua. `confidence` is null, not a quality score.
For direct inspection:

```bash
curl http://127.0.0.1:8884/translate \
  -H 'Content-Type: application/json' \
  -d '{"q":["A bronze medal.","Portrait of a woman and a horse."],"source":"en","target":"fr","format":"text"}'
```

The local endpoint cannot be reached from production using loopback. Remote
connectivity and a production rollout are separate from this dev configuration.
