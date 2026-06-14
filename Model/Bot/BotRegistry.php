<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Bot;

use Angeo\RobotsTxtAeo\Model\Cache\Type\RobotsTxtAeo as RobotsTxtAeoCache;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single source of truth for all known AI crawler bots.
 *
 * Bot catalogue is static — defined in BUILTIN_BOTS — and ships with the
 * module. New bots are added via module releases, not at runtime. This is
 * deliberate: robots.txt content is a security-relevant surface, and the
 * trade-off of "convenience of dynamic catalogue" against "anyone with the
 * registry endpoint can inject UA strings into every install's robots.txt"
 * isn't worth it.
 *
 * The dedicated cache type (angeo_robots_txt_aeo) holds the hydrated
 * BotDefinition[] for 1 h so we don't re-parse the BUILTIN_BOTS constant on
 * every robots.txt request. Cache flush via System → Cache Management.
 *
 * @since 2.0.0 — caches via dedicated cache type; +5 built-in bots; removed
 *                in-memory cache layer. v2.0.0 dropped the remote-registry
 *                runtime overlay — catalogue is now release-managed.
 * @since 3.0.0 — catalogue re-verified against primary vendor documentation
 *                (developers.openai.com/api/docs/bots, support.claude.com
 *                update of 2026-02, developers.google.com/crawling/docs,
 *                docs.perplexity.ai). Added Claude-SearchBot and OAI-AdsBot;
 *                anthropic-ai marked deprecated (Anthropic deprecated the
 *                token); per-bot category / token_only / supports_crawl_delay
 *                / ip_ranges_url metadata. Cache key bumped to _v3 so stale
 *                2.x payloads without metadata are not rehydrated.
 */
class BotRegistry
{
    public const CACHE_KEY = 'angeo_robots_txt_aeo_bot_registry_v3';
    public const CACHE_TTL = 3600;

    /** @var array<string, array<string, mixed>> */
    public const BUILTIN_BOTS = [
        // ── OpenAI — developers.openai.com/api/docs/bots ──────────────────
        'oai_searchbot' => [
            'user_agent'           => 'OAI-SearchBot',
            'label'                => 'OAI-SearchBot',
            'description'          => 'ChatGPT search — surfaces and cites sites in ChatGPT search answers',
            'critical_for_audit'   => true,
            'category'             => 'search',
            'ip_ranges_url'        => 'https://openai.com/searchbot.json',
            'docs_url'             => 'https://developers.openai.com/api/docs/bots',
        ],
        'gptbot' => [
            'user_agent'           => 'GPTBot',
            'label'                => 'GPTBot',
            'description'          => 'OpenAI training crawler — disallow to opt out of foundation-model training',
            'critical_for_audit'   => true,
            'category'             => 'training',
            'supports_crawl_delay' => false,
            'ip_ranges_url'        => 'https://openai.com/gptbot.json',
            'docs_url'             => 'https://developers.openai.com/api/docs/bots',
        ],
        'chatgpt_user' => [
            'user_agent'           => 'ChatGPT-User',
            'label'                => 'ChatGPT-User',
            'description'          => 'ChatGPT user-triggered fetch — vendor notes robots.txt rules may not apply to user-initiated actions',
            'category'             => 'user_fetch',
            'ip_ranges_url'        => 'https://openai.com/chatgpt-user.json',
            'docs_url'             => 'https://developers.openai.com/api/docs/bots',
        ],
        'oai_adsbot' => [
            'user_agent'           => 'OAI-AdsBot',
            'label'                => 'OAI-AdsBot',
            'description'          => 'Validates landing pages submitted as ChatGPT ads; visits only ad pages; data not used for training. Relevant only if you advertise on ChatGPT.',
            'category'             => 'ads',
            'default_enabled'      => false,
            'docs_url'             => 'https://developers.openai.com/api/docs/bots',
        ],

        // ── Perplexity — docs.perplexity.ai/docs/resources/perplexity-crawlers ──
        'perplexitybot' => [
            'user_agent'           => 'PerplexityBot',
            'label'                => 'PerplexityBot',
            'description'          => 'Perplexity search index — not used to train foundation models (vendor statement)',
            'category'             => 'search',
            'ip_ranges_url'        => 'https://www.perplexity.com/perplexitybot.json',
            'docs_url'             => 'https://docs.perplexity.ai/docs/resources/perplexity-crawlers',
        ],
        'perplexity_user' => [
            'user_agent'           => 'Perplexity-User',
            'label'                => 'Perplexity-User',
            'description'          => 'Perplexity user-triggered fetch — vendor states it generally ignores robots.txt for user requests',
            'respects_robots_txt'  => false,
            'category'             => 'user_fetch',
            'ip_ranges_url'        => 'https://www.perplexity.com/perplexity-user.json',
            'docs_url'             => 'https://docs.perplexity.ai/docs/resources/perplexity-crawlers',
        ],

        // ── Google — developers.google.com/crawling/docs ──────────────────
        'google_extended' => [
            'user_agent'           => 'Google-Extended',
            'label'                => 'Google-Extended',
            'description'          => 'robots.txt control token (never appears in logs) — governs Gemini training/grounding; no effect on Google Search',
            'critical_for_audit'   => true,
            'category'             => 'token',
            'token_only'           => true,
            'supports_crawl_delay' => false,
            'docs_url'             => 'https://developers.google.com/crawling/docs/crawlers-fetchers',
        ],

        // ── Anthropic — support.claude.com (doc update 2026-02): three bots;
        //    Crawl-delay documented as supported; Anthropic-AI / Claude-Web deprecated ──
        'claudebot' => [
            'user_agent'           => 'ClaudeBot',
            'label'                => 'ClaudeBot',
            'description'          => 'Anthropic training crawler — collects public web content that may be used to train Claude models',
            'category'             => 'training',
            'supports_crawl_delay' => true,
        ],
        'claude_user' => [
            'user_agent'           => 'Claude-User',
            'label'                => 'Claude-User',
            'description'          => 'Claude user-triggered fetch — retrieves pages when a Claude user asks a question',
            'category'             => 'user_fetch',
            'supports_crawl_delay' => true,
        ],
        'claude_searchbot' => [
            'user_agent'           => 'Claude-SearchBot',
            'label'                => 'Claude-SearchBot',
            'description'          => 'Anthropic search-quality crawler — indexes content to improve relevance of Claude search results',
            'category'             => 'search',
            'supports_crawl_delay' => true,
        ],
        'anthropic_ai' => [
            'user_agent'           => 'anthropic-ai',
            'label'                => 'anthropic-ai (deprecated by Anthropic)',
            'description'          => 'DEPRECATED — Anthropic retired the Anthropic-AI token in the 2026-02 documentation update; use ClaudeBot instead. Kept for backwards compatibility only.',
            'category'             => 'training',
            'deprecated'           => true,
            'default_enabled'      => false,
        ],

        // ── Other vendors ─────────────────────────────────────────────────
        'applebot' => [
            'user_agent'           => 'Applebot',
            'label'                => 'Applebot',
            'description'          => 'Apple Intelligence / Siri fetcher (Applebot-Extended is the separate training opt-out token)',
            'category'             => 'search',
        ],
        'cohere_ai' => [
            'user_agent'           => 'cohere-ai',
            'label'                => 'cohere-ai',
            'description'          => 'Cohere AI training crawler',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
        'amazonbot' => [
            'user_agent'           => 'Amazonbot',
            'label'                => 'Amazonbot',
            'description'          => 'Amazon Alexa AI training & retrieval',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
        'meta_external_agent' => [
            'user_agent'           => 'Meta-ExternalAgent',
            'label'                => 'Meta-ExternalAgent',
            'description'          => 'Meta AI training and on-demand fetch',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
    ];

    public function __construct(
        private readonly RobotsTxtAeoCache   $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface     $logger,
    ) {}

    /** @return array<string, BotDefinition> */
    public function all(): array
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            try {
                $data = $this->serializer->unserialize($cached);
                if (is_array($data)) {
                    return $this->rehydrate($data);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Angeo_RobotsTxtAeo] Bot registry cache corrupted: ' . $e->getMessage());
            }
        }

        return $this->buildAndCache();
    }

    public function get(string $key): ?BotDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** Built-ins. Same as all() — kept as a separate method for symmetry. */
    public function builtins(): array
    {
        $result = [];
        foreach (self::BUILTIN_BOTS as $key => $data) {
            $result[$key] = BotDefinition::fromArray($key, $data + ['source' => 'builtin']);
        }
        return $result;
    }

    /** Drop cache so next all() call re-hydrates from BUILTIN_BOTS. */
    public function invalidate(): void
    {
        $this->cache->remove(self::CACHE_KEY);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /** @return array<string, BotDefinition> */
    private function buildAndCache(): array
    {
        $merged = $this->builtins();

        try {
            $this->cache->save(
                $this->serializer->serialize(array_map(fn(BotDefinition $b) => $b->toArray(), $merged)),
                self::CACHE_KEY,
                [RobotsTxtAeoCache::CACHE_TAG],
                self::CACHE_TTL
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_RobotsTxtAeo] Failed to cache bot registry: ' . $e->getMessage());
        }

        return $merged;
    }

    /** @param array<string, array<string, mixed>> $data */
    private function rehydrate(array $data): array
    {
        $result = [];
        foreach ($data as $key => $row) {
            if (is_array($row)) {
                $result[$key] = BotDefinition::fromArray($key, $row);
            }
        }
        return $result;
    }
}
