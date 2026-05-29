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
 */
class BotRegistry
{
    public const CACHE_KEY = 'angeo_robots_txt_aeo_bot_registry';
    public const CACHE_TTL = 3600;

    /** @var array<string, array<string, mixed>> */
    public const BUILTIN_BOTS = [
        'oai_searchbot' => [
            'user_agent'         => 'OAI-SearchBot',
            'label'              => 'OAI-SearchBot',
            'description'        => 'ChatGPT live search — fetches product pages for shopping queries',
            'critical_for_audit' => true,
        ],
        'gptbot' => [
            'user_agent'         => 'GPTBot',
            'label'              => 'GPTBot',
            'description'        => 'OpenAI training crawler — block to opt out of GPT model training',
            'critical_for_audit' => true,
        ],
        'chatgpt_user' => [
            'user_agent'         => 'ChatGPT-User',
            'label'              => 'ChatGPT-User',
            'description'        => 'ChatGPT user-triggered browsing',
        ],
        'perplexitybot' => [
            'user_agent'         => 'PerplexityBot',
            'label'              => 'PerplexityBot',
            'description'        => 'Perplexity background indexer — allow to appear in Perplexity results',
        ],
        'perplexity_user' => [
            'user_agent'          => 'Perplexity-User',
            'label'               => 'Perplexity-User',
            'description'         => 'Perplexity real-time fetch (does NOT respect robots.txt in practice)',
            'respects_robots_txt' => false,
        ],
        'google_extended' => [
            'user_agent'         => 'Google-Extended',
            'label'              => 'Google-Extended',
            'description'        => 'Gemini / AI Overviews — does not affect Google Search rankings',
            'critical_for_audit' => true,
        ],
        'claudebot' => [
            'user_agent'         => 'ClaudeBot',
            'label'              => 'ClaudeBot',
            'description'        => 'Anthropic Claude citation fetcher',
        ],
        'anthropic_ai' => [
            'user_agent'         => 'anthropic-ai',
            'label'              => 'anthropic-ai',
            'description'        => 'Anthropic training crawler — block to opt out of Claude model training',
        ],
        // ── v2.0.0 additions — aligned with Angeo_AeoAudit v3 catalogue ──
        'claude_user' => [
            'user_agent'         => 'Claude-User',
            'label'              => 'Claude-User',
            'description'        => 'Anthropic Claude browsing agent (user-triggered fetches)',
        ],
        'applebot' => [
            'user_agent'         => 'Applebot',
            'label'              => 'Applebot',
            'description'        => 'Apple Intelligence / Siri AI fetcher',
        ],
        'cohere_ai' => [
            'user_agent'         => 'cohere-ai',
            'label'              => 'cohere-ai',
            'description'        => 'Cohere AI training crawler',
            'default_enabled'    => false,
        ],
        'amazonbot' => [
            'user_agent'         => 'Amazonbot',
            'label'              => 'Amazonbot',
            'description'        => 'Amazon Alexa AI training & retrieval',
            'default_enabled'    => false,
        ],
        'meta_external_agent' => [
            'user_agent'         => 'Meta-ExternalAgent',
            'label'              => 'Meta-ExternalAgent',
            'description'        => 'Meta AI training and on-demand fetch',
            'default_enabled'    => false,
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
