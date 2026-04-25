# Angeo Robots.txt AEO — AI Crawler Rules for Magento 2

[![Packagist](https://img.shields.io/packagist/v/angeo/module-robots-txt-aeo.svg)](https://packagist.org/packages/angeo/module-robots-txt-aeo)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)

Injects AI crawler rules (`OAI-SearchBot`, `GPTBot`, `PerplexityBot`, `Google-Extended`, `ClaudeBot` and more) into your Magento 2 robots.txt — **without overwriting your existing configuration**.

Fixes the **"robots.txt — AI Bot Access"** signal in [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit).

---

## How it works

The module intercepts the robots.txt response at render time (via plugin) and prepends a managed block of AI bot rules. **No database writes. No file-system changes.** Your existing admin config is untouched.

### Inject mode (default — recommended)

```
# Angeo AEO — AI Crawler Rules
# https://angeo.dev | module-robots-txt-aeo
# Do not edit this block manually — manage via Stores > Config > Angeo > Robots.txt AEO

User-agent: OAI-SearchBot
Allow: /

User-agent: GPTBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Perplexity-User
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: anthropic-ai
Allow: /

# End Angeo AEO block

User-agent: *
Disallow: /checkout/
... (your existing rules follow unchanged)
```

### Replace mode

Regenerates the full robots.txt. Preserves your custom `Disallow` rules from the existing wildcard block. Use only if you want this module to own the entire file.

---

## Important: if you manage robots.txt manually

If you prefer to manage AI bot rules yourself directly in Magento admin (**Content → Design → Configuration → Edit Custom instruction of robots.txt**), you can:

**Option A — Disable the module entirely:**
```
bin/magento module:disable Angeo_RobotsTxtAeo
bin/magento cache:flush
```

**Option B — Keep the module but manage specific bots yourself:**
Disable individual bots in **Stores → Configuration → Angeo → Robots.txt AEO → AI Crawlers**. The module only injects bots that are enabled in config. Bots you disable are left entirely to your manual configuration.

**Option C — Use the module as-is (recommended):**
The injected block is idempotent and clearly labeled. If you later add a bot manually to admin config, the module will detect the duplicate and skip re-injecting it.

---

## Installation

```bash
composer require angeo/module-robots-txt-aeo
bin/magento setup:upgrade
bin/magento cache:flush
```

**Requirements:** PHP 8.2+, Magento 2.4+. Compatible with Magento Open Source and Adobe Commerce Cloud.

---

## Configuration

**Stores → Configuration → Angeo → Robots.txt AEO**

| Setting | Default | Description |
|---|---|---|
| Enable AI Bot Rules | Yes | Master on/off switch |
| Injection Mode | Inject | Inject (prepend) or Replace (full file) |
| OAI-SearchBot | Yes | ChatGPT live search crawler |
| GPTBot | Yes | OpenAI training crawler |
| ChatGPT-User | Yes | ChatGPT user-triggered browsing |
| PerplexityBot | Yes | Perplexity background indexer |
| Perplexity-User | Yes | Perplexity real-time fetch |
| Google-Extended | Yes | Gemini / AI Overviews |
| ClaudeBot | Yes | Anthropic Claude citations |
| anthropic-ai | Yes | Anthropic training crawler |

---

## Admin Dashboard

**Angeo → Robots.txt AEO** in the Magento admin menu.

The dashboard provides:

- **Validate live robots.txt** — fetches `yourstore.com/robots.txt` and shows pass/fail status for each enabled bot
- **Preview after injection** — shows the full robots.txt output with syntax highlighting, without making any changes
- Per-bot status table updated in real time via AJAX
- Direct link to live robots.txt and Settings

No CLI access required — non-technical store owners can validate and preview from the browser.

---

## CLI commands

```bash
# Preview what robots.txt will look like after injection (no changes made)
bin/magento angeo:robots:preview

# Preview with diff — show only lines being added
bin/magento angeo:robots:preview --diff

# Validate that AI rules are present in the live robots.txt
bin/magento angeo:robots:validate

# Validate against a specific store URL
bin/magento angeo:robots:validate --url=https://yourstore.com
```

---

## Adobe Commerce Cloud note

On Adobe Commerce Cloud, `robots.txt` is served via a Fastly VCL snippet. After any configuration change:

1. Save config in admin
2. Purge the Fastly CDN cache
3. Run `bin/magento angeo:robots:validate` to confirm the live file is updated

---

## CI pipeline integration

```bash
# Fail the build if AI bot rules are missing
bin/magento angeo:robots:validate || exit 1
```

---

## The Angeo AI Visibility Suite

| Module | Purpose | Signal |
|---|---|---|
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | AEO audit — detects missing signals | — |
| [`angeo/module-robots-txt-aeo`](https://packagist.org/packages/angeo/module-robots-txt-aeo) | **This module** — AI bot access | Signal #1 |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | Generates llms.txt and llms.jsonl | Signal #2 |
| [`angeo/module-rich-data`](https://packagist.org/packages/angeo/module-rich-data) | Product / FAQ / Org JSON-LD schema | Signal #3 |
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | ACP product feed for ChatGPT Shopping | Signal #4 |

---

## License

MIT — see [LICENSE](LICENSE)
