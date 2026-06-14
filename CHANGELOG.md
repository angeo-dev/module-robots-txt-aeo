# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] — 2026-06-11

Major release. Every feature is backed by primary-source verification
(vendor crawler docs fetched directly, IETF draft-ietf-aipref-attach
rev. 2026-04-28, RSL 1.0, RFC 9309). Full design in
docs/SPECIFICATION-3.0.0.md. Upgrade note: default behaviour is unchanged —
all new emission features ship disabled; the only output difference on
upgrade is that previously-destroyed third-party directives are now
preserved (a fix).

### Fixed
- **Data loss of third-party robots.txt directives (Tier 1).** INJECT mode
  rebuilt the file via parse→render but the renderer never re-emitted
  unrecognised directives — silently deleting `Content-Signal:`,
  `Content-Usage:` and `License:` lines (Cloudflare manages Content Signals
  on 3.8M+ domains). The parser now captures top-level `License:` lines and
  group-scoped extra directives, and the renderer re-emits all of them;
  injection remains idempotent.
- **Crawl-delay metadata contradiction.** Anthropic's 2026-02 docs state
  Crawl-delay IS supported; the hardcoded ignore-list said otherwise.
  Replaced by per-bot tri-state `supports_crawl_delay` (emit only on
  documented support; unknown = suppress). `BOTS_IGNORING_CRAWL_DELAY`
  retained but @deprecated.

### Added
- **RFC 9309 evaluation engine** (`Model\Rep\RepMatcher`): group selection
  with same-token merging and `*` fallback, longest-match-wins, Allow
  tie-break, `*`/`$` patterns, case-sensitive paths. Validate (admin + CLI)
  now reports per-bot *effective* access to `/` — a bot that is present but
  blocked at the root is a failure, with the blocking rule shown.
- **Catalogue (vendor-verified):** `Claude-SearchBot` (Anthropic, on) and
  `OAI-AdsBot` (OpenAI ads validation, off); `anthropic-ai` marked
  deprecated by Anthropic (default off); per-bot `category`, `token_only`
  (Google-Extended never appears in logs), `ip_ranges_url`, `docs_url`;
  Unicode-dash normalisation in UA sanitisation; registry cache key bumped.
- **IETF `Content-Usage` emission** (draft-ietf-aipref-attach), off by
  default: configurable aipref preference (default `train-ai=n`) appended to
  every managed bot group (and the wildcard group in REPLACE mode).
- **Cloudflare `Content-Signal` emission**, off by default: tri-state
  search / ai-train / ai-input with defaults mirroring Cloudflare's managed
  rollout; unset signals are omitted.
- **RSL 1.0 `License:` directive**, off by default: global directive with an
  https-validated URL, deduplicated against existing License lines.
- **`angeo:robots:verify-bot-ip <ip>` CLI:** checks an address against the
  vendor-published IP range endpoints (OpenAI, Perplexity) with IPv4/IPv6
  CIDR matching, to detect UA spoofing.
- Public API: `RobotsStatusInterface::getEffectiveAccess()` and
  `::getContentSignalLines()` (@since 3.0.0).
- Tests: RepMatcherTest (RFC 9309 normative cases), CidrMatcherTest, parser
  round-trip tests, BotDefinition metadata tests.

### Changed
- `BotDefinition` constructor gains optional metadata parameters;
  `Config::resolveBotOverrides()` carries all metadata through (2.x dropped
  `criticalForAudit` on override resolution).
- Crawl-delay is now emitted **only** for bots with documented support —
  stores that configured a delay for e.g. Applebot will no longer see it in
  output (previously emitted; vendor support undocumented).

## [2.0.1] — 2026-06-11

Security-hardening release. No functional or configuration changes — drop-in
upgrade from 2.0.0.

### Security
- **`UrlFetcher` SSRF hardening.** libcurl's `CURLOPT_FOLLOWLOCATION` is now
  disabled; redirects are followed manually (max 3 hops) and every hop is
  validated: target scheme must be `http`/`https`, target host must equal the
  original host (a leading `www.` is the only tolerated difference), and
  `https://` → `http://` downgrades are refused. A redirect violating the
  policy fails the fetch immediately, is logged, and is never retried.
  Previously an open redirect (or compromised upstream) on the store's own
  `robots.txt` could steer the admin Validate/Preview fetch — and its response
  body — to an arbitrary internal host.
- **Outbound URLs restricted to `http`/`https`** at both the validation layer
  (scheme allow-list before any network activity) and the transport layer
  (`CURLOPT_PROTOCOLS = CURLPROTO_HTTP | CURLPROTO_HTTPS`).
- **`store` request parameter is now validated.** New
  `Model\Adminhtml\StoreIdResolver` accepts only digit-strings and verifies
  the store exists via `StoreRepositoryInterface` before use. Previously the
  Preview/Validate controllers blind-cast the raw parameter to `int`,
  allowing probing of arbitrary store IDs.
- **No raw exception leakage from admin AJAX endpoints.** Unexpected
  exceptions in the Preview/Validate controllers are now logged server-side
  and a generic message is returned; only intentional `LocalizedException`
  messages reach the client.
- **`dashboard.js` no longer concatenates server-provided strings into
  `innerHTML`.** `showAlert()` builds DOM nodes via `textContent` /
  `createTextNode`, removing the HTML-injection sink entirely (previously
  reachable only with admin-controlled payloads, i.e. self-XSS class — fixed
  on defense-in-depth grounds).

### Added
- Unit tests for the redirect policy, scheme allow-list, and retry semantics
  (`UrlFetcherTest`) and for the new `StoreIdResolver`
  (`Test/Unit/Model/Adminhtml/StoreIdResolverTest`).

### Changed
- Retry semantics clarified: HTTP 5xx and network errors are retried with
  backoff; HTTP 4xx, redirect-policy violations, and redirect loops fail
  deterministically without retries (4xx behaviour unchanged from 2.0.0).

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
