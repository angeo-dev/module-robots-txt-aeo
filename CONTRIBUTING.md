# Contributing to module-robots-txt-aeo

Thanks for your interest. This module is small, focused, and intended to stay
that way — so contributions are most welcome but we are deliberately conservative
about scope.

## What we accept

- Bug fixes with a regression test.
- New AI crawlers (one PR per bot, with a citation to the bot's official docs).
- Documentation improvements.
- Performance fixes.
- Test additions for currently-uncovered code paths.

## What we usually decline

- Features that re-implement functionality already provided by core Magento
  (e.g., a generic robots.txt editor — Magento already has one).
- Anything that does database writes or filesystem writes for a feature that
  could be done as a render-time plugin.
- New configuration fields with weak rationale. Every config field is forever.

## Workflow

1. Open an issue first if your change is non-trivial. Saves both of us time.
2. Fork → branch → PR. Branch name format: `fix/`, `feat/`, `docs/`, `test/`.
3. Run unit tests locally:
   ```
   vendor/bin/phpunit Test/Unit
   ```
4. PRs without tests for new logic will be asked for tests before review.

## Code style

- PHP 8.2+, `declare(strict_types=1)`, readonly properties, constructor promotion.
- No `array` shape arrays where a value object would be clearer.
- Comments should explain *why*, not *what*. The code shows what.
- 4-space indent. No trailing whitespace. Files end with a single newline.

## Tests

- Unit tests live under `Test/Unit/`.
- One test class per production class. Mirror the namespace.
- Use named PHPUnit method arguments (`willReturn(...)` etc.) over positional.
- Prefer real instances over mocks when the dependency is a pure value object or
  has no side effects (e.g., `RobotsTxtParser` in `RobotsInjectorTest`).

## Adding a new built-in bot

1. Add a row to `BotRegistry::BUILTIN_BOTS` with `user_agent`, `label`, `description`, and optional `critical_for_audit` / `default_enabled` / `respects_robots_txt` flags.
2. Add a `<field>` to `etc/adminhtml/system.xml` under the `bots` group (and an entry in `etc/config.xml` for the default state).
3. Add the key to the expected list in `BotRegistryTest::testBuiltinsContainsAllExpectedBots`.
4. If the bot is critical for AEO Audit pass, also update `Test/Unit/Model/Bot/BotRegistryTest::testCriticalForAuditMetadataPropagates`.
5. Document the bot's purpose in `README.md`.

The bot catalogue is release-managed — adding a bot ships in the next module release. There is no runtime catalogue overlay (removed in 2.0).

## Releasing (maintainers only)

1. Update `CHANGELOG.md` — move "Unreleased" entries under a dated version.
2. Bump version in `composer.json`.
3. Tag: `git tag -a v1.x.y -m "Release 1.x.y"` and push tags.
4. Packagist auto-syncs from the GitHub webhook.

## License

By contributing you agree your changes will be released under the MIT license.
