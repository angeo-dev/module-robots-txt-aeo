# Security policy

## Reporting a vulnerability

If you find a security issue in this module, **please do not file a public GitHub
issue**. Instead, email the maintainer directly:

**info@angeo.dev**

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
| 4.0.x   | Yes |
| 3.0.x   | Critical fixes only until 2027-06-01 |
| 2.0.x   | Critical fixes only until 2027-06-01 |
| < 2.0   | No |

## Threat model

This module:

- Reads from `ScopeConfig` (admin-controlled values) and **sanitises every value
  again at render time**, because config can be written without passing a
  backend model (direct DB write, `app/etc/config.php`, env override).
- Writes the response body of `Magento\Robots\Model\Robots::getData()` via a
  plugin. It does **not** write to the database or the filesystem.
- Does **not** accept input from frontend visitors.
- **Makes outbound HTTP requests.** This was not true before 3.0.0 and the
  previous version of this document still said so. The module fetches:
  - the store's own `/robots.txt` (admin Validate/Preview, `angeo:robots:validate`,
    `angeo:robots:preview`);
  - vendor-published bot IP range lists, e.g. `https://openai.com/gptbot.json`
    (`angeo:robots:verify-bot-ip`);
  - Web Bot Auth key directories at
    `<origin>/.well-known/http-message-signatures-directory`
    (`angeo:robots:verify-bot-request`).

  All of them go through `Model\UrlFetcher`, under one policy:
  - `http`/`https` only (scheme allow-list plus `CURLOPT_PROTOCOLS`);
  - redirects are never followed by libcurl — each hop is validated manually,
    must stay on the original host (a leading `www.` aside), must not downgrade
    HTTPS to HTTP, max 3 hops;
  - every hop's host is resolved and each address checked by `Model\Http\IpGuard`;
    loopback, RFC 1918, link-local (including the `169.254.169.254` metadata
    endpoint), CGNAT, multicast and reserved ranges are refused, IPv6 and
    IPv4-mapped forms included;
  - the validated address is pinned with `CURLOPT_RESOLVE`, so the host cannot
    resolve to something else between the check and the connection;
  - responses are capped at 1 MiB and the parser stops after 50,000 lines.

### Bot verification

`Api\BotVerificationInterface` answers "is this request really from that
vendor?" — it never blocks anything. Blocking belongs at the WAF or CDN, where
it happens before PHP is reached.

The Web Bot Auth verifier fetches keys only from an origin that appears in the
bot catalogue or in `angeo_robots_txt_aeo/verification/trusted_signature_agents`.
It never takes the origin from the `Signature-Agent` header alone: that value is
attacker-controlled, fetching it would be an SSRF primitive, and a signature
verified against an attacker's own key set would "prove" whatever they wanted.

A signature is only accepted when it covers `@authority` and the
`signature-agent` header — otherwise a valid signature captured elsewhere could
be replayed against this store.

### Trust assumptions

- Admin users are trusted to configure paths and custom content. If you do not
  trust your admin users, do not grant them `Angeo_RobotsTxtAeo::config`. The
  render-time sanitiser is defence in depth, not a privilege boundary.
- TLS verification is on by default for all outbound calls. The `--insecure`
  CLI flag exists solely for local development with self-signed certificates and
  must not be used in production.
- Verification results are advisory. A `verified` result proves the signature or
  the source address; it does not tell you the request is welcome.

### What we look for in reports

High signal:
- Remote code execution
- Stored XSS in the admin panel
- Path traversal in bot override fields
- Cache poisoning of the served robots.txt
- ACL bypass
- Any way to make the module emit a directive the operator did not configure
- Any way to make the verifier report `verified` for a request that was not signed
  by the vendor, or to make it fetch a key directory from an untrusted origin
- SSRF: reaching an internal address through any of the fetch paths above

Out of scope:
- Self-XSS where an admin pastes JavaScript into a config field
- DoS via extreme config values within the documented caps — that is a
  legitimate admin choice
- A vendor publishing an inaccurate IP range list or key directory

## Credits

Reporters of valid issues will be credited in the changelog unless they request
anonymity.
