# Angeo Robots.txt AEO — AI Crawler Rules for Magento 2

[![Packagist](https://img.shields.io/packagist/v/angeo/module-robots-txt-aeo.svg)](https://packagist.org/packages/angeo/module-robots-txt-aeo)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.1%20|%208.2%20|%208.3%20|%208.4-8892BF.svg)](https://php.net)
[![Magento](https://img.shields.io/badge/magento-2.4.6%20|%202.4.7%20|%202.4.8-EE672F.svg)](https://magento.com)

Injects AI crawler rules into your Magento 2 `robots.txt` — **without overwriting your existing configuration**.

Bots managed out-of-the-box: `OAI-SearchBot`, `GPTBot`, `ChatGPT-User`, `PerplexityBot`, `Perplexity-User`, `Google-Extended`, `ClaudeBot`, `anthropic-ai`, `Claude-User`, `Applebot`, `cohere-ai`, `Amazonbot`, `Meta-ExternalAgent`.

Fixes the **"robots.txt — AI Bot Access"** signal in [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit).

---

## What's new in 2.0

- **5 new built-in bots** aligned with the AEO Audit v3 catalogue: `Claude-User`, `Applebot`, `cohere-ai`, `Amazonbot`, `Meta-ExternalAgent`. An out-of-the-box install now passes the AEO Audit's `robots_txt` check.
- **Audit-clean output** — emitted robots.txt no longer triggers syntax warnings:
  - `Crawl-delay` suppressed on bots that ignore it (GPTBot, ClaudeBot, Google-Extended).
  - No `Allow: /` + `Disallow: /` conflict on the same agent.
  - Versioned UAs sanitised at the catalogue layer.
  - Sitemap URLs upgraded to `https://` when the store base URL is HTTPS.
- **`Api\RobotsStatusInterface`** — public read-only API for cross-module integration. Consumers like `angeo/module-aeo-audit` can wire to it and skip the HTTP round-trip.
- **Dedicated cache type** `angeo_robots_txt_aeo` — flush in isolation from System → Cache Management.
- **Backend validation** — `PathList` and `CrawlDelay` backend models normalise admin input on save.
- **CSP-clean admin UI** — no inline styles, no inline scripts.
- **i18n/en_US.csv** — admin labels are translatable.
- **Removed runtime remote-registry feature** — bot catalogue is now release-managed only. Dynamic catalogue injection from an external endpoint was a security trade-off (anyone with the endpoint could inject UA strings into every install's robots.txt) and a half-implemented UX one (added bots had no admin checkbox). New bots ship via module releases.
- **Removed orphan code** — the unused `RemoteRegistryUpdater` triplet from 1.x is gone.

See [CHANGELOG.md](CHANGELOG.md) for the full list.

---

## How it works

The module intercepts the robots.txt response at render time via a plugin on
`Magento\Robots\Model\Robots::getData()` and prepends a managed block of AI bot rules.
**No database writes. No filesystem changes.** Your existing admin config is untouched.

### Inject mode (default — recommended)

```
# Angeo AEO — AI Crawler Rules
# https://angeo.dev | module-robots-txt-aeo
# Do not edit this block manually — manage via Stores > Config > Angeo > Robots.txt AEO

User-agent: OAI-SearchBot
Allow: /

User-agent: GPTBot
Allow: /

User-agent: ClaudeBot
Allow: /
Disallow: /admin/

User-agent: Claude-User
Allow: /

User-agent: Applebot
Allow: /

# End Angeo AEO block

User-agent: *
Disallow: /checkout/
... (your existing rules follow unchanged)

# Angeo AEO — Sitemaps
Sitemap: https://example-store.com/sitemap.xml
# End Angeo AEO sitemaps
```

### Replace mode

Regenerates the full robots.txt. Preserves your custom `Disallow` rules from the existing wildcard block. Use only if you want this module to own the entire file.

---

## Installation

```bash
composer require angeo/module-robots-txt-aeo
bin/magento module:enable Angeo_RobotsTxtAeo
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

That's it. The module is enabled with sensible defaults — all 10 mainstream AI bots are allowed; the 3 lower-traffic bots (cohere-ai, Amazonbot, Meta-ExternalAgent) are catalogued but disabled by default.

---

## Configuration

`Stores → Configuration → Angeo → Robots.txt AEO`

| Section | Purpose |
|---|---|
| **General** | Enable/disable, choose Inject or Replace mode |
| **AI Crawlers** | Tick which bots to allow. Bots marked ★ are critical for AEO Audit pass |
| **AI Crawler Path Overrides** | Per-bot `Allow:`, `Disallow:`, `Crawl-delay:` |
| **Sitemap Directive** | Auto-detect from `Magento_Sitemap`, manual list, or none |
| **Live Preview** | Renders the AEO block that will be injected |

All settings respect store scope — multi-store installs can configure each store independently.

---

## CLI

```bash
# Render what would be emitted, without applying it
bin/magento angeo:robots:preview [--store=N]

# Fetch the live robots.txt and check enabled bot rules are present
bin/magento angeo:robots:validate [--store=N] [--insecure]
```

`validate` exits non-zero when expected bot rules are missing from the live
file — useful in post-deploy smoke tests:

```yaml
# .github/workflows/post-deploy.yml
- run: bin/magento angeo:robots:validate
```

For a full AEO scoring of robots.txt (critical-bot checks, syntax warnings,
sitemap quality) install [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit).
It reads the effective output of this module via `Api\RobotsStatusInterface` —
no HTTP round-trip when both modules are installed.

---

## Cross-module integration (Api\RobotsStatusInterface)

The module exposes a public read-only API that consumer modules can wire to via
DI. Soft-coupling pattern — consumers `interface_exists()`-check before
declaring the dependency, so they keep working when this module is not installed.

```php
use Angeo\RobotsTxtAeo\Api\RobotsStatusInterface;

class MyChecker
{
    public function __construct(
        private readonly ?RobotsStatusInterface $robotsStatus = null,
    ) {}

    public function check(int $storeId): void
    {
        if ($this->robotsStatus !== null) {
            // Zero-overhead — pure in-process call
            $effective = $this->robotsStatus->getEffectiveRobotsTxt($storeId);
            $bots      = $this->robotsStatus->getEnabledBotUserAgents($storeId);
            // ...
        } else {
            // Fall back to HTTP fetch
        }
    }
}
```

Used by `angeo/module-aeo-audit` v3+ when both modules are installed.

---

## How robots.txt manual content interacts

The module's admin form (Inject mode) **does not** modify the existing Magento admin robots.txt textarea (Content → Design → Configuration → Edit Custom instruction of robots.txt). Both sources coexist:

- Your custom block is preserved untouched.
- The AEO block is prepended at render time.
- Re-running the plugin is idempotent — the AEO block is replaced, not stacked.

If you'd rather manage AI bot rules yourself, either disable the module (`bin/magento module:disable Angeo_RobotsTxtAeo`) or untick individual bots in admin.

---

## Compatibility

| | Status |
|---|---|
| Magento 2.4.6 (PHP 8.1) | ✅ |
| Magento 2.4.7 (PHP 8.2 / 8.3) | ✅ |
| Magento 2.4.8 (PHP 8.3 / 8.4) | ✅ |
| Magento Open Source / Commerce / Cloud | ✅ |
| Hyvä / PWA Studio | ✅ (robots.txt is server-side) |
| Multi-store / multi-website | ✅ |
| `Magento_Sitemap` not installed | ✅ (soft dependency, no-op resolver) |
| Varnish / Fastly | ⚠️ purge CDN cache after config changes |

---

## License

MIT. See [LICENSE](LICENSE).

## Security

See [SECURITY.md](SECURITY.md) for the disclosure policy.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
