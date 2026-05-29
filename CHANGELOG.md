# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — 2026-05-29

### Added
- **5 new built-in bots** aligned with the `angeo/module-aeo-audit` v3 catalogue:
  `Claude-User`, `Applebot`, `cohere-ai`, `Amazonbot`, `Meta-ExternalAgent`.
  Out-of-the-box, an install now produces a robots.txt that passes the audit
  module's `robots_txt` check.
- **`Angeo\RobotsTxtAeo\Api\RobotsStatusInterface`** — public read-only API
  exposing the effective robots.txt, enabled bot UAs, sitemaps, and mode.
  Cross-module integration with `angeo/module-aeo-audit` (and any third-party
  consumer) is now zero-overhead — no HTTP round-trip required.
- **Dedicated cache type** `angeo_robots_txt_aeo` — surfaces in
  System → Cache Management and can be flushed in isolation.
- **Backend models for config validation** — `PathList` and `CrawlDelay`
  normalise input on save (admin form, `config:set`, direct DB writes).
- **i18n/en_US.csv** — admin labels are now translatable.
- **`criticalForAudit` metadata** on `BotDefinition` — flags bots whose
  blocking causes the AEO Audit to FAIL (currently OAI-SearchBot, GPTBot,
  Google-Extended).

### Changed
- **Audit-clean output sanitisation** — emitted robots.txt no longer triggers
  syntax warnings from the AEO Audit:
  - `Crawl-delay` directives are suppressed on bots that documentedly ignore
    them (GPTBot, ClaudeBot, Google-Extended).
  - When a bot has `Disallow: /`, the implicit `Allow: /` fallback is dropped
    so we never emit both directives on the same agent.
  - User-agent strings are sanitised at the `BotDefinition` layer — any
    `/version` suffix is stripped (e.g. `GPTBot/1.0` → `GPTBot`).
  - Sitemap URLs are upgraded to `https://` when the store base URL is HTTPS.
- **`RobotsInjector::stripStandaloneBotEntries`** rewritten to use
  `RobotsTxtParser` instead of a hand-rolled regex state machine.
  Cleaner, ~50 lines smaller, and correct for previously edge-case input.
- **`Plugin\RobotsModelPlugin`** — short-circuits before building the
  injector graph when the module is disabled for the current store.
- **`composer.json`** — PHP requirement loosened to `~8.1.0||~8.2.0||~8.3.0||~8.4.0`,
  added hard dependency on `magento/module-robots`, pinned `magento/framework`
  to `^103.0`.
- Admin Preview block and Dashboard template are now CSP-friendly — all
  inline `<style>` and `<script>` removed in favour of dedicated CSS/JS
  assets loaded via the layout.

### Removed
- **Remote bot registry feature** — the runtime overlay from `https://angeo.dev/registry/bots.json`
  is gone. Bot catalogue is now release-managed only. Removed:
  - `BotRegistry::refresh()`, all signature-verification, and the cache layer
    for the overlay.
  - `Cron\RefreshRemoteRegistry` and `etc/crontab.xml`.
  - `Console\Command\RegistryUpdateCommand` (`bin/magento angeo:robots:registry:update`).
  - `<remote_registry>` group in `etc/adminhtml/system.xml` and `etc/config.xml`.
  - `Config::isRemoteRegistryEnabled()` and `Config::getRemoteRegistryUrl()`.
  - HMAC-SHA256 signature verification and `X-Angeo-Signature` header support.
  - Response headers carried by `FetchResult` (no consumer remained).
  - `BotRegistry` constructor parameters: `ScopeConfigInterface`, `UrlFetcher`,
    `DeploymentConfig` — now takes cache, serializer, logger only.

  Rationale: the overlay was a security trade-off (anyone holding the endpoint
  could inject UA strings into every install's robots.txt) and a half-implemented
  UX (registry-added bots had no admin checkbox so admins couldn't enable them).
  New bots ship via module releases — the cadence is already adequate
  (the bot landscape changes every 2–3 months; module releases are faster).
- **`Model\Bot\RemoteRegistryUpdater`** and **`Model\Bot\UpdateResult`** —
  orphan duplicate of the now-also-removed `BotRegistry::refresh()`.
- **`Test\Unit\Model\Bot\RemoteRegistryUpdaterTest`** — tests for the
  removed classes.
- Commented-out half-finished DI block in `etc/di.xml`.

### Migration notes
- Run `bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush`.
- The new dedicated cache type `angeo_robots_txt_aeo` appears in System →
  Cache Management — leave it enabled.
- Existing per-store config under `angeo_robots_txt_aeo/general/*`,
  `angeo_robots_txt_aeo/bots/*`, `angeo_robots_txt_aeo/bot_overrides/*`,
  and `angeo_robots_txt_aeo/sitemap/*` is preserved verbatim.
- New bots (`claude_user`, `applebot`, etc.) inherit their `default_enabled`
  state from `config.xml` on first read.
- Sites that had `angeo_robots_txt_aeo/remote_registry/*` set in DB or
  `app/etc/config.php` will see those values become inert — no harm, but you
  may run `bin/magento config:set angeo_robots_txt_aeo/remote_registry/enabled 0`
  before upgrade if you want a clean DB.
- Consumers of `RemoteRegistryUpdater` or `BotRegistry::refresh()` (none known)
  should migrate to release-tracking — new bots appear in `BotRegistry::BUILTIN_BOTS`
  with each release.

## [1.1.0] — 2026-04-25

### Added
- **Per-bot path overrides** — each bot can now have its own `Allow:`, `Disallow:`,
  and `Crawl-delay:` directives, configurable from admin under "AI Crawler Path Overrides".
- **`Sitemap:` directive support** — the module now emits `Sitemap:` lines into
  robots.txt. Three modes: Auto (read from Magento Sitemap module or fall back to
  `/sitemap.xml`), Custom (admin textarea), or None.
- **Remote bot registry** (`https://angeo.dev/registry/bots.json`) — optional opt-in
  source for newly emerged AI crawlers. New entries are added as suggestions with
  `default_enabled = false`; the admin must explicitly opt in. Daily cron refresh.
- **Multi-store / multi-website support** — `system.xml` now declares store-scope
  fields and `Config` reads through `ScopeInterface::SCOPE_STORE`. Each store can
  have its own bot configuration.
- **`RobotsTxtParser`** — proper line-by-line state machine for parsing robots.txt.
- **`BotRegistry`** — central registry of bot definitions with caching and remote overlay.
- **`bin/magento angeo:robots:registry:update`** — CLI command to refresh the remote registry.
- **CLI `--store` and `--insecure` flags** for `preview` and `validate` commands.
- Cron job `angeo_robots_txt_aeo_registry_refresh` (daily at 03:17).

### Changed
- **`UrlFetcher` now uses `Magento\Framework\HTTP\Client\Curl`** instead of
  `file_get_contents`. TLS verification is enabled by default; `--insecure`
  available as explicit opt-in. Adds proper timeouts, retries with exponential
  backoff, and follow-redirects.
- All HTTP responses are now wrapped in `FetchResult` (immutable value object).
- `Config::BOTS` constant removed — bot definitions live in `BotRegistry`.
- Plugin now resolves the store ID via `StoreManagerInterface` so multi-store
  installations get correct per-store output.

## [1.0.0] — 2026-03-12

### Added
- Initial release. Plugin on `Magento\Robots\Model\Robots::getData()`.
- Default catalog of 8 AI crawler bots.
- Admin configuration UI under Stores → Configuration → Angeo → Robots.txt AEO.
- ACL, sequence on `Magento_Robots`, MIT licensed.
