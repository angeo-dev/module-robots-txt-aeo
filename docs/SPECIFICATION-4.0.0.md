# Specification — module-robots-txt-aeo 4.0.0

Status: implemented. Supersedes `docs/SPECIFICATION-3.0.0.md` for the areas it
touches; everything not mentioned here is unchanged from 3.0.0.

## 0. Why this release exists

3.0.0 made the module correct about what robots.txt *says*: RFC 9309 evaluation,
lossless directive round-trip, vendor-verified catalogue. What it could not do is
tell an operator whether the crawler in their log is real. In 2026 that is the
question merchants actually have, because the answer decides whether an AI
shopping agent sees their catalogue or a scraper drains it.

Two things happened outside the module since June:

1. **Web Bot Auth became the de-facto identity rail.** Cloudflare verifies it in
   production, OpenAI documents `Signature-Agent: "https://chatgpt.com"` with a
   key directory at `/.well-known/http-message-signatures-directory`, and the
   IETF chartered a working group
   (`draft-meunier-webbotauth-httpsig-protocol-01`, August 2026). IP allowlists
   and User-Agent strings are explicitly named in that draft as the weak
   mechanisms it replaces.
2. **Magento 2.4.9 shipped** (12 May 2026) on PHP 8.5 and Symfony 7.4, dropping
   PHP 8.2. The 3.0.0 composer constraint (`~8.1`–`~8.4`) refuses to install
   there at all.

So: 4.0.0 is the verification release, plus the platform bump, plus the security
work found in the 3.0.0 audit.

## 1. Verification layer

### 1.1 Contract

```
Api\BotVerificationInterface
    verifyIp(string $ip, ?string $userAgent = null): array
    verifyRequest(array $headers, string $authority, string $path, string $method, string $scheme, ?string $ip): array
    getVerificationSupport(): array
```

Implemented by `Model\BotVerification`, which owns orchestration only. Each rail
is a `Model\Verify\BotVerifierInterface`:

| Method | Class | Source of truth |
|---|---|---|
| `web_bot_auth` | `WebBotAuthVerifier` | RFC 9421 signature + JWKS directory |
| `ip_range` | `IpRangeVerifier` | vendor-published CIDR JSON |

A signature is tried before an address: it survives a proxy, an address does not.

### 1.2 States

`verified` / `failed` / `unknown` / `unsupported`. The distinction between
`failed` and `unknown` is deliberate and load-bearing — "the key directory timed
out" must never be reported as "this request is forged", because an operator
acting on the second answer blocks real traffic.

### 1.3 Web Bot Auth flow

1. Read `Signature-Agent`; normalise the structured-field string to an https
   origin.
2. **Check the origin against the catalogue entry and the operator's configured
   list.** Never against the header alone. Fetching an attacker-supplied origin
   would be an SSRF primitive, and a signature verified against an attacker's own
   key set would prove nothing while looking like proof.
3. Parse `Signature-Input` / `Signature` (RFC 9651 structured fields, quoted
   inner list, `keyid` / `created` / `expires` / `alg` / `tag`). Component
   parameters (`;sf`, `;req`) are refused rather than ignored.
4. Require coverage of `@authority` and `signature-agent`; without them a
   captured signature replays against any host.
5. Check `created` / `expires` with 300 s skew and a 1 h maximum age.
6. Rebuild the RFC 9421 signature base. `@signature-params` reproduces the
   received definition byte for byte, which is why the parser keeps the raw
   string. A component we cannot reproduce returns `unknown`, never `verified`.
7. Fetch the JWK Set (cached 24 h, Ed25519/OKP only, `nbf`/`exp` honoured),
   match `keyid`, fall back to trying every published key.
8. `sodium_crypto_sign_verify_detached`.

### 1.4 Surfaces

- `bin/magento angeo:robots:verify-bot-request` — headers from a file or
  `--header` options.
- `bin/magento angeo:robots:verify-bot-ip <ip> [--bot=]` — now backed by the
  same verifier.
- `getVerificationSupport()` for `angeo/module-aeo-audit`, which currently has no
  live verification checker.

**No blocking.** Enforcement belongs at the WAF or CDN, before PHP is reached. A
module that blocks in `afterGetData()` would be both too late and too slow.

## 2. Safety fixes

| ID | Issue | Fix |
|---|---|---|
| S-1 | REPLACE mode dropped a site-wide `Disallow: /` and republished the site as crawlable | Parser keeps `/`; `wildcardBlocksSite()`; REPLACE returns the file unchanged and `validate()` explains |
| S-2 | Config values written into a public file without render-time cleaning | `RobotsLineSanitizer` on every value, token, preserved line and block; `CustomContent` backend model |
| S-3 | Scheme and host validated, address never | `IpGuard` + `CURLOPT_RESOLVE` pinning per hop |
| S-4 | Unbounded response and parse | 1 MiB cap, 50,000-line parser cap |
| S-5 | Backtracking regex built from untrusted patterns | wildcard collapsing and caps, literal prefix pre-filter, explicit backtrack limit, PCRE error ≠ match |

## 3. Lossless INJECT

Groups carry `startLine` / `endLine`. Removal is a line-span cut, not a
parse-and-reprint:

- group entirely ours → the whole span is dropped;
- mixed group → only our `User-agent:` lines are dropped, its rules stay;
- everything else, comments included, is untouched.

This closes the last class of "the module rewrote my robots.txt" complaints.

## 4. Catalogue

Added, all `default_enabled: false`: `Applebot-Extended`, `meta-externalfetcher`,
`CCBot`, `Bytespider`, `MistralAI-User`, `DuckAssistBot`.

`Applebot-Extended` matters most: 3.0.0 shipped `Applebot` only, and the two
tokens do different jobs — blocking `Applebot` costs Siri and Spotlight
visibility, while `Applebot-Extended` is the training opt-out.

New per-bot metadata: `verification[]`, `jwks_url`, `signature_agents[]`,
`ip_ranges_shared`. A claimed rail without its endpoint is dropped at
hydration, so an entry cannot advertise a proof it has no way to perform.

Verification endpoints as confirmed against primary sources, 2026-09-05:

| Vendor | IP ranges | Signing origin | Source |
|---|---|---|---|
| OpenAI | `openai.com/{gptbot,searchbot,chatgpt-user}.json` | `https://chatgpt.com` | developers.openai.com, help.openai.com 11845367 |
| Anthropic | `claude.com/crawling/bots.json` (one feed for all three bots) | none published | support.claude.com 8896518, updated 2026-04-07 |
| Perplexity | `www.perplexity.com/{perplexitybot,perplexity-user}.json` | none published | docs.perplexity.ai/docs/resources/perplexity-crawlers |

`ip_ranges_shared` exists because of the Anthropic case. Their feed covers the
whole crawler fleet, so a match proves the operator and not the bot; the
verifier says exactly that instead of overstating what a shared list can show.
Anthropic's own article adds that blocking those addresses is the wrong opt-out
— it prevents them fetching robots.txt, which is the mechanism that actually
carries the preference.

No signing origin is invented. Where a vendor publishes none, the bot's rails
are what they are; an operator who learns of a new origin adds it in
configuration without waiting for a release.

Catalogue rows can be added through di.xml (`BotRegistry::$additionalBots`). Not
a remote registry: whoever controls a live catalogue endpoint controls every
install's robots.txt.

## 5. Content signals

Placement is configurable, wildcard by default. A `Content-Signal` inside a bot
group applies to that crawler alone; Cloudflare's managed file puts it in
`User-agent: *`, which is what makes it a statement about the site. In INJECT
mode the line is spliced into the existing wildcard group; when there is none, a
signal-only wildcard group is emitted inside the managed block.

`Content-Usage` still tracks `draft-ietf-aipref-attach` (rev. 2026-04-28). The
AIPREF drafts are adopted working-group documents on the standards track, not
RFCs — the admin copy says so rather than implying a finished standard.

## 6. Breaking changes

- PHP ≥ 8.2; `ext-sodium` and `ext-json` declared.
- `BotDefinition::BOTS_IGNORING_CRAWL_DELAY` removed (deprecated in 3.0.0).
- `BotRegistry::CACHE_KEY` → `CACHE_KEY_PREFIX`, bumped to `_v4` and keyed by the
  di.xml payload.
- Constructor signatures: `RobotsInjector` (+sanitizer, +logger), `UrlFetcher`
  (+`IpGuard`), `BotRegistry` (+`$additionalBots`), `MagentoSitemapProvider`
  (`ResourceConnection` instead of `ObjectManagerInterface`).
- `getWildcardDisallows()` returns `/`.
- Content signals default to wildcard placement.

## 7. Testing

Unit coverage added for `IpGuard` (every blocked range plus the split-DNS case),
`RobotsLineSanitizer`, `SignatureInputParser` / `SignatureBaseBuilder`,
`WebBotAuthVerifier` (against a real Ed25519 keypair: genuine, wrong key,
cross-host replay, untrusted origin, expiry, unreachable directory), REP matcher
hardening, and the injector's new INJECT and REPLACE behaviour.

CI runs lint and PHPUnit on PHP 8.2, 8.3, 8.4 and 8.5, plus `magento-coding-standard`.
