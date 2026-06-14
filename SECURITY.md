# Security policy

## Reporting a vulnerability

If you find a security issue in this module, **please do not file a public GitHub
issue**. Instead, email the maintainer directly:

📧 **info@angeo.dev**

Include:
- A description of the vulnerability
- Steps to reproduce
- The Magento version, PHP version, and module version affected
- Any proof-of-concept code (if applicable)

We aim to acknowledge reports within 48 hours and to provide a fix or mitigation
plan within 7 days for confirmed issues.

## Supported versions

| Version | Supported |
|---------|-----------|
| 3.0.x   | ✅ Yes    |
| 2.0.x   | ⚠️ Critical fixes only until 2027-06-01 |
| 1.1.x   | ⚠️ Critical fixes only until 2026-12-01 |
| 1.0.x   | ⚠️ Critical fixes only until 2026-10-01 |
| < 1.0   | ❌ No     |

## Threat model

This module:

- Reads from `ScopeConfig` (admin-controlled values).
- Writes the response body of `Magento\Robots\Model\Robots::getData()` via a plugin.
- Performs HTTP GET requests against `/robots.txt` on the store's own base URL
  (admin Validate/Preview actions and CLI `angeo:robots:validate` /
  `angeo:robots:preview` only). Since 2.0.1 these requests:
  - accept `http`/`https` URLs only (scheme allow-list +
    `CURLOPT_PROTOCOLS`),
  - never follow redirects blindly — each hop is validated to stay on the
    original host (modulo a leading `www.`) with no HTTPS→HTTP downgrade,
    max 3 hops.
- Does **not** write to the database.
- Does **not** write to the filesystem.
- Does **not** accept input from frontend visitors.
- Does **not** fetch from any external URL — the runtime remote bot registry
  was removed in 2.0.

### Trust assumptions

- Admin users are trusted to configure paths in `bot_overrides` correctly. If you
  do not trust your admin users, do not grant them
  `Angeo_RobotsTxtAeo::config` ACL.
- TLS verification is on by default for all outbound HTTP calls. The `--insecure`
  CLI flag exists solely for local dev with self-signed certs and must not be
  used in production.

### What we look for in reports

High signal:
- Remote code execution
- Stored XSS in admin panel
- Path traversal in bot override fields
- Cache poisoning of the served robots.txt
- ACL bypass

Out of scope:
- Self-XSS where the admin pastes JavaScript into a config field
- DoS via extreme config values (e.g., one million Disallow lines) — that's a
  legitimate admin choice; we won't add input size limits unless they would
  prevent realistic admin error.

## Credits

Reporters of valid issues will be credited in the changelog unless they request
anonymity.
