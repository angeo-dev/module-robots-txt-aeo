# Angeo_RobotsTxtAeo — Specification v3.0.0

Status: APPROVED FOR IMPLEMENTATION
Date: 2026-06-11
Supersedes: v2.0.1

Every feature in this specification is backed by a primary source verified on
2026-06-11. Features whose effectiveness could not be verified against vendor
or standards-body documentation are explicitly listed in "Out of scope".

Primary sources verified for this release:

- OpenAI crawler documentation — developers.openai.com/api/docs/bots
  (fetched directly)
- Anthropic crawler documentation update of ~2026-02-20 — support.claude.com,
  corroborated by three independent reports quoting it verbatim
- Google crawler documentation — developers.google.com/crawling/docs
- Perplexity crawler documentation — docs.perplexity.ai/docs/resources/
  perplexity-crawlers (fetched directly)
- IETF draft-ietf-aipref-attach (revision of 2026-04-28, fetched directly) +
  draft-ietf-aipref-vocab
- RFC 9309 — Robots Exclusion Protocol
- RSL 1.0 — rslstandard.org/guide/robots-txt and /rsl (fetched directly)
- Cloudflare Content Signals Policy — contentsignals.org / Cloudflare docs

---

## Tier 1 — REP integrity and correctness (foundation)

### F1.1 Lossless robots.txt round-trip  [BUG FIX — data loss]

**Problem (verified in 2.x code).** `RobotsTxtParser` collects unrecognised
directives into a flat `unknownDirectives` list, but
`RobotsInjector::renderRobotsTxt()` never re-emits them. Because INJECT mode
always rebuilds the document via parse→render, the module silently deletes
`Content-Signal:`, `Content-Usage:` and `License:` lines from any existing
robots.txt. Cloudflare alone manages robots.txt with Content Signals on 3.8M+
domains, so this is a real-world data-loss path.

**Specification.**

- `UserAgentGroup` gains `extraDirectives: string[]` — raw `Name: value`
  lines inside a group that are not Allow/Disallow/Crawl-delay, preserved in
  original order.
- `ParsedRobotsTxt` gains `licenses: string[]` — values of top-level
  `License:` directives (RSL: global, not bound to a user agent).
- The parser routes: `license` → `licenses`; any other unrecognised directive
  inside a group → that group's `extraDirectives` (an unknown directive after
  a `User-agent:` line materialises the group, same as Allow/Disallow); any
  unrecognised directive outside any group → `unknownDirectives`.
- The renderer re-emits, in order: top comments, top-level `License:` lines,
  top-level unknown directives, groups (each including its
  `extraDirectives`), sitemaps.
- Acceptance: parse→render of a file containing `License:`,
  `Content-Signal:` and `Content-Usage:` lines is content-preserving for
  those lines; INJECT mode run on such a file retains them; the run remains
  idempotent.

### F1.2 RFC 9309 evaluation engine (`RepMatcher`)

Validate in 2.x only answers "is the UA group present". RFC 9309 semantics
that decide whether a bot can actually crawl are not implemented anywhere.

**Specification.** New `Model\Rep\RepMatcher` implementing RFC 9309 §2.2:

- Group selection: the crawler obeys only the merged set of groups whose
  user-agent token matches its product token exactly (case-insensitive);
  if none match, the merged `*` groups; if none, access is allowed.
- Rule precedence: among matching Allow/Disallow rules, the longest pattern
  (in bytes) wins; on equal length, Allow beats Disallow. Rule order carries
  no semantics.
- Pattern syntax: `*` matches any character sequence; `$` anchors end of URL;
  path matching is case-sensitive; empty `Disallow:` value imposes no
  restriction; URLs with no matching rule are allowed.
- Public API: `isAllowed(ParsedRobotsTxt, string $productToken, string $path):
  AccessDecision` returning allowed flag, matched rule, and match source
  (`exact` | `wildcard` | `default`).

### F1.3 Validate v2 — effective access

The admin Validate action and the CLI validate command report, per enabled
bot: presence of the UA group (as today) **and** the RFC 9309 effective
decision for `/` against the live robots.txt. A bot that is "present" but
whose merged rules block `/` is reported as a failure with the blocking rule
shown. Deprecated catalogue entries that are still enabled produce a warning.

---

## Tier 2 — Verified bot catalogue 2026

All user-agent tokens below are taken from vendor documentation, not from
third-party lists.

### F2.1 New catalogue entries

| Key | UA token | Category | Default | Source |
|---|---|---|---|---|
| `claude_searchbot` | `Claude-SearchBot` | search | on | Anthropic docs update 2026-02 |
| `oai_adsbot` | `OAI-AdsBot` | ads | off | developers.openai.com/api/docs/bots |

`OAI-AdsBot` only visits pages submitted as ads and its data is not used for
training (vendor statement); shipping it default-off because it is relevant
only to merchants advertising on ChatGPT.

### F2.2 Deprecation of `anthropic-ai`

Anthropic's 2026-02 documentation update deprecates the `Anthropic-AI` and
`Claude-Web` tokens. The `anthropic_ai` entry is kept for backwards
compatibility but: `deprecated = true`, `default_enabled = false`, admin
label suffixed "(deprecated by Anthropic)", and Validate emits a warning when
it is still enabled.

### F2.3 Catalogue metadata model

`BotDefinition` gains verified metadata:

- `category`: one of `training | search | user_fetch | ads | token`.
- `tokenOnly: bool` — true for `Google-Extended` (vendor statement: "doesn't
  have a separate HTTP request user agent string"; it never appears in access
  logs). Affects future log-based features and admin copy; robots.txt
  presence checks are unaffected.
- `deprecated: bool`.
- `supportsCrawlDelay: ?bool` — tri-state: `true` (documented support),
  `false` (documented or strongly indicated non-support), `null` (unknown;
  treated conservatively as "do not emit").
- `ipRangesUrl: ?string` — vendor-published IP range JSON, only where the
  vendor documents one: OpenAI (`openai.com/searchbot.json`, `gptbot.json`,
  `chatgpt-user.json`), Perplexity (`perplexity.com/perplexitybot.json`,
  `perplexity-user.json`).
- `docsUrl: ?string` — vendor documentation page.

### F2.4 Crawl-delay correction  [DOC-CONTRADICTION FIX]

2.x hardcodes `BOTS_IGNORING_CRAWL_DELAY = [GPTBot, ClaudeBot,
Google-Extended]`. Anthropic's 2026-02 documentation states Crawl-delay is
supported. v3 replaces the hardcoded list with per-bot `supportsCrawlDelay`
metadata: ClaudeBot/Claude-User/Claude-SearchBot → `true`; GPTBot,
Google-Extended → `false`; others → `null` (suppressed, conservative). The
class constant remains, marked `@deprecated`, for BC with external readers.

### F2.5 Unicode-dash normalisation

Perplexity's own documentation has been observed mixing U+2011 with ASCII `-`
in the agent name, which silently breaks copy-pasted robots.txt rules. UA
sanitisation maps U+2010..U+2015 and U+2212 to ASCII `-` in addition to the
existing version-suffix stripping.

### F2.6 Bot IP verification (CLI)

`bin/magento angeo:robots:verify-bot-ip <ip>` fetches each catalogue entry's
`ipRangesUrl` (HTTPS, via the hardened UrlFetcher) and reports which vendor
range, if any, contains the address. Supports IPv4 and IPv6 CIDR. Purpose:
distinguishing genuine GPTBot/PerplexityBot traffic from UA spoofing. CLI
only — no admin-triggered multi-host fetching.

---

## Tier 3 — Content-usage signals and licensing (verified standards)

All three mechanisms are **off by default** — enabling any of them is an
explicit operator decision; upgrading from 2.x changes nothing.

### F3.1 IETF `Content-Usage` (draft-ietf-aipref-attach, rev. 2026-04-28)

- Group-scoped rule per the draft's ABNF: `Content-Usage: [path] pref` with
  optional leading path and the preference encoded per aipref-vocab
  (e.g. `train-ai=n`).
- Config: `content_signals/ietf_enabled` (default 0) and
  `content_signals/ietf_preference` (default `train-ai=n`, validated:
  printable ASCII, no CR/LF/#).
- Emission: the configured preference line is appended to every managed bot
  group; in REPLACE mode also to the generated `User-agent: *` group.
  Pre-existing Content-Usage lines in foreign groups are preserved verbatim
  (F1.1).
- Honest labelling: the admin comment states this is a Proposed-Standard
  draft (WG milestone Aug 2026), a preference signal, not an enforcement
  mechanism.

### F3.2 Cloudflare `Content-Signal`

- Group-scoped line: `Content-Signal: search=yes, ai-train=no[, ai-input=…]`.
- Config: `content_signals/cloudflare_enabled` (default 0) plus three
  tri-state fields `cf_search` / `cf_ai_train` / `cf_ai_input`, each
  `yes | no | unset`, with defaults matching Cloudflare's own managed
  rollout: `search=yes`, `ai-train=no`, `ai-input=unset` ("no expressed
  preference"). Unset signals are omitted from the emitted line; if all
  three are unset, no line is emitted.
- Emitted in the same positions as F3.1. Both syntaxes may be enabled
  simultaneously (they are complementary; IETF aipref and Content Signals
  are converging per contentsignals.org).

### F3.3 RSL 1.0 `License:` directive

- Global (not group-bound) directive per rslstandard.org:
  `License: <absoluteURL>` pointing at an RSL license file; URL may be on a
  different host.
- Config: `licensing/rsl_enabled` (default 0) and `licensing/rsl_license_url`
  with a backend model enforcing an absolute `https://` URL.
- Emission: once, at the top of the generated file, deduplicated against any
  `License:` directives already present in the existing robots.txt (which
  are preserved per F1.1).

---

## Public API changes (BC notes)

- `RobotsStatusInterface` (since 3.0.0): adds
  `getEffectiveAccess(?int $storeId = null): array` (per-enabled-bot RFC 9309
  decision for `/` against the module's own effective output) and
  `getContentSignalLines(?int $storeId = null): array`.
- `BotDefinition` constructor gains optional parameters only; `toArray()` /
  `fromArray()` round-trip the new fields; bot-registry cache key is bumped
  (`…_v3`) so stale 2.x cache entries are not rehydrated without metadata.
- `BOTS_IGNORING_CRAWL_DELAY` is retained but `@deprecated`.
- Config paths added; none removed. Default behaviour of an upgraded store is
  byte-identical to 2.0.1 except that previously-destroyed unknown directives
  are now preserved (strictly a fix) and `anthropic-ai`/defaults change only
  for stores that never saved that config row.

## Out of scope for 3.0.0 (insufficient verification)

- **llms.txt generation** — ~10% adoption per the 300k-domain SERanking
  study; major crawler vendors have not confirmed they consume it. Revisit
  when at least one of OpenAI/Anthropic/Google documents support.
- **Web Bot Auth verification** — IETF draft; Google's rollout is explicitly
  experimental and covers only Google-Agent. The legacy IP/rDNS rail remains
  primary per Google's own guidance; F2.6 covers the verified part (published
  IP ranges).
- **UCP / agentic-commerce endpoints** — different domain; out of charter for
  a robots.txt module.

## Testing requirements

- Unit: parser round-trip fidelity (License / Content-Signal / Content-Usage
  / arbitrary unknown lines, group and top level); RepMatcher against the
  RFC 9309 examples (longest match, Allow tie-break, `*`/`$`, case
  sensitivity, group selection incl. merged groups and `*` fallback);
  injector idempotency with signals enabled; BotDefinition metadata
  round-trip; CIDR matcher v4/v6.
- All PHP `php -l` clean, JS `node --check` clean.
- Framework-free functional harness must pass before packaging.
