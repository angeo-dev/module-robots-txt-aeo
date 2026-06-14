<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Bot;

/**
 * Immutable definition of a single AI crawler bot.
 *
 * This is the canonical representation used everywhere — Config builds it,
 * RobotsInjector consumes it, BotRegistry hydrates it from cache payloads.
 *
 * Per-bot path rules:
 *   allowPaths    — explicit Allow: directives (if empty, defaults to ['/'])
 *   disallowPaths — explicit Disallow: directives (additive on top of Allow)
 *   crawlDelay    — null = omit directive, float = "Crawl-delay: <value>"
 *
 * @since 2.0.0 — added $criticalForAudit and crawl-delay metadata.
 * @since 3.0.0 — vendor-verified metadata: $category, $tokenOnly,
 *                $deprecated, $supportsCrawlDelay (tri-state, replaces the
 *                hardcoded ignore-list), $ipRangesUrl, $docsUrl. Unicode-dash
 *                normalisation in UA sanitisation.
 */
final class BotDefinition
{
    public const CATEGORY_TRAINING   = 'training';   // collects content for model training
    public const CATEGORY_SEARCH     = 'search';     // builds a search/citation index
    public const CATEGORY_USER_FETCH = 'user_fetch'; // fetches on a live user request
    public const CATEGORY_ADS       = 'ads';         // ad landing-page validation
    public const CATEGORY_TOKEN     = 'token';       // robots.txt control token only — never in logs

    /**
     * @deprecated since 3.0.0 — superseded by per-bot $supportsCrawlDelay
     *             metadata. Anthropic's 2026-02 documentation update states
     *             Crawl-delay IS supported, contradicting this list's
     *             ClaudeBot entry. Retained only so external readers of this
     *             constant do not fatal; do not use in new code.
     */
    public const BOTS_IGNORING_CRAWL_DELAY = ['GPTBot', 'ClaudeBot', 'Google-Extended'];

    /**
     * @param string[] $allowPaths
     * @param string[] $disallowPaths
     */
    public function __construct(
        public readonly string  $key,
        public readonly string  $userAgent,
        public readonly string  $label,
        public readonly string  $description,
        public readonly bool    $respectsRobotsTxt  = true,
        public readonly array   $allowPaths         = ['/'],
        public readonly array   $disallowPaths      = [],
        public readonly ?float  $crawlDelay         = null,
        public readonly bool    $defaultEnabled     = true,
        public readonly ?string $source             = 'builtin',
        public readonly bool    $criticalForAudit   = false,
        public readonly string  $category           = self::CATEGORY_SEARCH,
        public readonly bool    $tokenOnly          = false,
        public readonly bool    $deprecated         = false,
        public readonly ?bool   $supportsCrawlDelay = null,
        public readonly ?string $ipRangesUrl        = null,
        public readonly ?string $docsUrl            = null,
    ) {}

    /**
     * Hydrate from an associative array (cache payload or config row).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $key, array $data): self
    {
        $userAgent = self::sanitizeUserAgent((string) ($data['user_agent'] ?? $key));

        return new self(
            key:                $key,
            userAgent:          $userAgent,
            label:              (string) ($data['label']       ?? $data['user_agent'] ?? $key),
            description:        (string) ($data['description'] ?? ''),
            respectsRobotsTxt:  (bool)   ($data['respects_robots_txt'] ?? true),
            allowPaths:         self::asStringArray($data['allow_paths']    ?? ['/']),
            disallowPaths:      self::asStringArray($data['disallow_paths'] ?? []),
            crawlDelay:         isset($data['crawl_delay']) && is_numeric($data['crawl_delay'])
                                    ? (float) $data['crawl_delay']
                                    : null,
            defaultEnabled:     (bool)   ($data['default_enabled'] ?? true),
            source:             (string) ($data['source']          ?? 'builtin'),
            criticalForAudit:   (bool)   ($data['critical_for_audit'] ?? false),
            category:           (string) ($data['category'] ?? self::CATEGORY_SEARCH),
            tokenOnly:          (bool)   ($data['token_only'] ?? false),
            deprecated:         (bool)   ($data['deprecated'] ?? false),
            supportsCrawlDelay: array_key_exists('supports_crawl_delay', $data)
                                && $data['supports_crawl_delay'] !== null
                                    ? (bool) $data['supports_crawl_delay']
                                    : null,
            ipRangesUrl:        isset($data['ip_ranges_url']) ? (string) $data['ip_ranges_url'] : null,
            docsUrl:            isset($data['docs_url'])      ? (string) $data['docs_url']      : null,
        );
    }

    /**
     * Whether a Crawl-delay directive should be suppressed for this bot.
     *
     * Tri-state policy: emit only when support is documented (true); suppress
     * on documented non-support (false) AND on unknown (null) — conservative,
     * keeps output audit-clean.
     */
    public function ignoresCrawlDelay(): bool
    {
        return $this->supportsCrawlDelay !== true;
    }

    /**
     * Serialize to an associative array (for caching / debugging).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'                  => $this->key,
            'user_agent'           => $this->userAgent,
            'label'                => $this->label,
            'description'          => $this->description,
            'respects_robots_txt'  => $this->respectsRobotsTxt,
            'allow_paths'          => $this->allowPaths,
            'disallow_paths'       => $this->disallowPaths,
            'crawl_delay'          => $this->crawlDelay,
            'default_enabled'      => $this->defaultEnabled,
            'source'               => $this->source,
            'critical_for_audit'   => $this->criticalForAudit,
            'category'             => $this->category,
            'token_only'           => $this->tokenOnly,
            'deprecated'           => $this->deprecated,
            'supports_crawl_delay' => $this->supportsCrawlDelay,
            'ip_ranges_url'        => $this->ipRangesUrl,
            'docs_url'             => $this->docsUrl,
        ];
    }

    /**
     * Sanitise a UA token for robots.txt emission:
     *  - normalise Unicode dashes (U+2010..U+2015, U+2212) to ASCII "-" —
     *    vendor docs have been observed mixing non-breaking hyphens into
     *    agent names, which silently breaks literal robots.txt matching;
     *  - drop any "/x.y" version suffix — robots.txt User-agent matching is
     *    exact-token, so "GPTBot/1.0" never matches GPTBot.
     */
    private static function sanitizeUserAgent(string $userAgent): string
    {
        $userAgent = str_replace(
            ["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"],
            '-',
            trim($userAgent)
        );

        if (($slashPos = strpos($userAgent, '/')) !== false) {
            $userAgent = substr($userAgent, 0, $slashPos);
        }
        return $userAgent;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function asStringArray(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)), 'strlen');
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map('strval', $value));
    }
}
