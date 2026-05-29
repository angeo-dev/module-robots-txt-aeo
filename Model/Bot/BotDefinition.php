<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Bot;

/**
 * Immutable definition of a single AI crawler bot.
 *
 * This is the canonical representation used everywhere — Config builds it,
 * RobotsInjector consumes it, BotRegistry hydrates it from JSON.
 *
 * Per-bot path rules:
 *   allowPaths    — explicit Allow: directives (if empty, defaults to ['/'])
 *   disallowPaths — explicit Disallow: directives (additive on top of Allow)
 *   crawlDelay    — null = omit directive, float = "Crawl-delay: <value>"
 *
 * @since 2.0.0 — added $criticalForAudit and $ignoresCrawlDelay metadata.
 */
final class BotDefinition
{
    /**
     * Bots that documentedly ignore Crawl-delay (operator and AEO Audit warn
     * when a Crawl-delay directive is emitted for these).
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
        public readonly bool    $respectsRobotsTxt = true,
        public readonly array   $allowPaths        = ['/'],
        public readonly array   $disallowPaths     = [],
        public readonly ?float  $crawlDelay        = null,
        public readonly bool    $defaultEnabled    = true,
        public readonly ?string $source            = 'builtin',
        public readonly bool    $criticalForAudit  = false,
    ) {}

    /**
     * Hydrate from an associative array (e.g. JSON registry payload or config row).
     *
     * Strips any "/version" suffix from the user-agent — robots.txt matching is
     * literal-string so versioned UAs never match. The AEO Audit flags this as
     * a syntax issue.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $key, array $data): self
    {
        $userAgent = self::sanitizeUserAgent((string) ($data['user_agent'] ?? $key));

        return new self(
            key:               $key,
            userAgent:         $userAgent,
            label:             (string) ($data['label']       ?? $data['user_agent'] ?? $key),
            description:       (string) ($data['description'] ?? ''),
            respectsRobotsTxt: (bool)   ($data['respects_robots_txt'] ?? true),
            allowPaths:        self::asStringArray($data['allow_paths']    ?? ['/']),
            disallowPaths:     self::asStringArray($data['disallow_paths'] ?? []),
            crawlDelay:        isset($data['crawl_delay']) && is_numeric($data['crawl_delay'])
                                   ? (float) $data['crawl_delay']
                                   : null,
            defaultEnabled:    (bool)   ($data['default_enabled'] ?? true),
            source:            (string) ($data['source']          ?? 'builtin'),
            criticalForAudit:  (bool)   ($data['critical_for_audit'] ?? false),
        );
    }

    /**
     * True when this bot is documented to ignore Crawl-delay.
     */
    public function ignoresCrawlDelay(): bool
    {
        return in_array($this->userAgent, self::BOTS_IGNORING_CRAWL_DELAY, true);
    }

    /**
     * Serialize to an associative array (for caching / debugging).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key'                 => $this->key,
            'user_agent'          => $this->userAgent,
            'label'               => $this->label,
            'description'         => $this->description,
            'respects_robots_txt' => $this->respectsRobotsTxt,
            'allow_paths'         => $this->allowPaths,
            'disallow_paths'      => $this->disallowPaths,
            'crawl_delay'         => $this->crawlDelay,
            'default_enabled'     => $this->defaultEnabled,
            'source'              => $this->source,
            'critical_for_audit'  => $this->criticalForAudit,
        ];
    }

    /**
     * Drop any "/x.y" version suffix from a UA token. robots.txt User-agent
     * matching is exact-token, so "GPTBot/1.0" never matches GPTBot.
     */
    private static function sanitizeUserAgent(string $userAgent): string
    {
        $userAgent = trim($userAgent);
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
