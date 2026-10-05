# Named translation profiles

`engine` selects a configured TranslatorManager instance. Both REST `/batch-translate`
and JSON-RPC `translateBatch` now validate the resolved engine and source→target pair
before writing Source/Target rows or dispatching work. Explicit requests never silently
fall back to a different service. REST validation errors return HTTP 400.

`GET /api/translation-engines` exposes configured names, public profile metadata and
supported pairs. URLs and credentials are excluded. LibreTranslate and DeepL language
lists come from their APIs and are cached for one hour. A new engine must supply
`languagePairs` in its capabilities metadata or profile configuration, or a
`languagesUrl` with LibreTranslate-compatible `{code, targets}` entries.

The response's `engine` and `profile` show the resolved choice. Completed Target rows
store provenance; callbacks include provider/model/revision when known. Providers
that do not disclose a model/version return null rather than an invented identifier.
The Libre-compatible adapter preserves model/revision supplied by ai-tools in both
single and batch responses. A result whose model/revision conflicts with the configured
profile is rejected rather than published under the wrong identity.

## Configuration

Profile metadata lives under `translation.engine_profiles`; engine transport adapters
remain under `survos_translator.engines`. Use **a new engine name** when changing model,
revision, prompt, or generation settings: Lingua's cache/Target identity contains the
engine name. Do not repoint `libre` at ai-tools, or reuse a model profile name for a
new revision. Names can now be up to 100 characters.

The dev-only `euronano` profile points to the already-installed local ai-tools service,
using its pinned `qvac/TranslatePsy-EuroNano` revision. This configuration does not
install or expose that model on the production host. Defaults remain Libre/DeepL.

## Rollout

Apply Version20261005171000 before using the new application code; it widens the engine
column and adds Target provenance. Update translator-bundle to include response
metadata preservation. Existing results are retained, with empty provenance if unknown.
Harvest has its own Version20261005170000 migration and engine-qualified callback IDs.
The changes and both migrations have been exercised locally; production is unchanged.
