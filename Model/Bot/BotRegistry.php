<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Bot;

use Angeo\RobotsTxtAeo\Model\Cache\Type\RobotsTxtAeo as RobotsTxtAeoCache;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Single source of truth for all known AI crawler bots.
 *
 * The catalogue ships with the module and is release-managed. There is no
 * runtime registry fetched over the network: robots.txt content is a
 * security-relevant surface, and "whoever controls the registry endpoint
 * controls every install's robots.txt" is not a trade worth making.
 *
 * Integrators who need one extra bot no longer have to fork. The constructor
 * takes an $additionalBots array that di.xml can fill — still a deployed,
 * reviewed artifact, not a live feed:
 *
 *   <type name="Angeo\RobotsTxtAeo\Model\Bot\BotRegistry">
 *       <arguments>
 *           <argument name="additionalBots" xsi:type="array">
 *               <item name="my_bot" xsi:type="array">
 *                   <item name="user_agent" xsi:type="string">MyBot</item>
 *                   <item name="label" xsi:type="string">MyBot</item>
 *                   <item name="default_enabled" xsi:type="boolean">false</item>
 *               </item>
 *           </argument>
 *       </arguments>
 *   </type>
 *
 * The dedicated cache type (angeo_robots_txt_aeo) holds the hydrated
 * BotDefinition[] so BUILTIN_BOTS is not re-parsed on every robots.txt
 * request. Flush via System → Cache Management.
 *
 * @since 2.0.0 — dedicated cache type; remote-registry overlay removed.
 * @since 3.0.0 — catalogue verified against primary vendor documentation;
 *                per-bot category / token_only / supports_crawl_delay /
 *                ip_ranges_url metadata.
 * @since 4.0.0 — verification metadata per bot (published IP ranges and/or
 *                Web Bot Auth signing origins), six new tokens, di.xml
 *                extension point, cache key bumped to _v4 and now keyed by
 *                the additional-bot payload so a di.xml change cannot be
 *                served from a stale entry.
 */
class BotRegistry
{
    public const CACHE_KEY_PREFIX = 'angeo_robots_txt_aeo_bot_registry_v4';
    public const CACHE_TTL        = 3600;

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
            // OpenAI documents Web Bot Auth for ChatGPT's browsing/agent
            // traffic: Signature-Agent: "https://chatgpt.com", keys at
            // https://chatgpt.com/.well-known/http-message-signatures-directory
            // (help.openai.com/en/articles/11845367).
            'jwks_url'             => 'https://chatgpt.com/.well-known/http-message-signatures-directory',
            'signature_agents'     => ['https://chatgpt.com'],
        ],
        'oai_adsbot' => [
            'user_agent'           => 'OAI-AdsBot',
            'label'                => 'OAI-AdsBot',
            'description'          => 'Validates landing pages submitted as ChatGPT ads; visits only ad pages; data not used for training. Relevant only if you advertise on ChatGPT.',
            'category'             => 'ads',
            'default_enabled'      => false,
            'docs_url'             => 'https://developers.openai.com/api/docs/bots',
        ],

        // ── Perplexity — docs.perplexity.ai/docs/resources/perplexity-crawlers
        //    (verified 2026-09-05). Two bots, one JSON range endpoint each, no
        //    signing origin published — IP ranges are the only rail here. ──
        'perplexitybot' => [
            'user_agent'           => 'PerplexityBot',
            'label'                => 'PerplexityBot',
            'description'          => 'Perplexity search index — surfaces and links sites in Perplexity results; vendor states it is not used to crawl content for AI foundation models',
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

        // ── Anthropic — support.claude.com article 8896518 (updated 2026-04-07).
        //    One IP list covers all three bots: a match proves the request came
        //    from Anthropic, not which of its crawlers sent it. Anthropic also
        //    states plainly that blocking those addresses is the wrong opt-out,
        //    because it stops them reading robots.txt in the first place. ──
        'claudebot' => [
            'user_agent'           => 'ClaudeBot',
            'label'                => 'ClaudeBot',
            'description'          => 'Anthropic training crawler — collects public web content that may be used to train Claude models',
            'category'             => 'training',
            'supports_crawl_delay' => true,
            'ip_ranges_url'        => 'https://claude.com/crawling/bots.json',
            'ip_ranges_shared'     => true,
            'docs_url'             => 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
        ],
        'claude_user' => [
            'user_agent'           => 'Claude-User',
            'label'                => 'Claude-User',
            'description'          => 'Claude user-triggered fetch — retrieves pages when a Claude user asks a question. Anthropic documents that it honours robots.txt.',
            'category'             => 'user_fetch',
            'supports_crawl_delay' => true,
            'ip_ranges_url'        => 'https://claude.com/crawling/bots.json',
            'ip_ranges_shared'     => true,
            'docs_url'             => 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
        ],
        'claude_searchbot' => [
            'user_agent'           => 'Claude-SearchBot',
            'label'                => 'Claude-SearchBot',
            'description'          => 'Anthropic search-quality crawler — indexes content to improve relevance of Claude search results',
            'category'             => 'search',
            'supports_crawl_delay' => true,
            'ip_ranges_url'        => 'https://claude.com/crawling/bots.json',
            'ip_ranges_shared'     => true,
            'docs_url'             => 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
        ],
        'anthropic_ai' => [
            'user_agent'           => 'anthropic-ai',
            'label'                => 'anthropic-ai (deprecated by Anthropic)',
            'description'          => 'DEPRECATED — Anthropic retired the Anthropic-AI token in the 2026-02 documentation update; use ClaudeBot instead. Kept for backwards compatibility only.',
            'category'             => 'training',
            'deprecated'           => true,
            'default_enabled'      => false,
            'docs_url'             => 'https://support.claude.com/en/articles/8896518-does-anthropic-crawl-data-from-the-web-and-how-can-site-owners-block-the-crawler',
        ],

        // ── Apple ─────────────────────────────────────────────────────────
        'applebot' => [
            'user_agent'           => 'Applebot',
            'label'                => 'Applebot',
            'description'          => 'Apple Intelligence / Siri fetcher — powers Siri and Spotlight results',
            'category'             => 'search',
        ],
        'applebot_extended' => [
            'user_agent'           => 'Applebot-Extended',
            'label'                => 'Applebot-Extended',
            'description'          => 'robots.txt control token for Apple foundation-model training. Separate from Applebot: blocking it keeps Siri/Spotlight results while opting out of training.',
            'category'             => 'token',
            'token_only'           => true,
            'default_enabled'      => false,
        ],

        // ── Other vendors ─────────────────────────────────────────────────
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
            'description'          => 'Amazon Alexa / Rufus AI training & retrieval',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
        'meta_external_agent' => [
            'user_agent'           => 'Meta-ExternalAgent',
            'label'                => 'Meta-ExternalAgent',
            'description'          => 'Meta AI training and index crawler',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
        'meta_external_fetcher' => [
            'user_agent'           => 'meta-externalfetcher',
            'label'                => 'meta-externalfetcher',
            'description'          => 'Meta user-triggered fetch — retrieves a page because a person asked Meta AI about it',
            'category'             => 'user_fetch',
            'default_enabled'      => false,
        ],
        'ccbot' => [
            'user_agent'           => 'CCBot',
            'label'                => 'CCBot',
            'description'          => 'Common Crawl — builds the open dataset many models are trained on. Blocking it removes you from a corpus you cannot re-enter retroactively.',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
        'bytespider' => [
            'user_agent'           => 'Bytespider',
            'label'                => 'Bytespider',
            'description'          => 'ByteDance training crawler. No published documentation and a poor robots.txt compliance record — a rule here is a request, not a control.',
            'category'             => 'training',
            'default_enabled'      => false,
        ],
        'mistralai_user' => [
            'user_agent'           => 'MistralAI-User',
            'label'                => 'MistralAI-User',
            'description'          => 'Mistral Le Chat user-triggered fetch',
            'category'             => 'user_fetch',
            'default_enabled'      => false,
        ],
        'duckassistbot' => [
            'user_agent'           => 'DuckAssistBot',
            'label'                => 'DuckAssistBot',
            'description'          => 'DuckDuckGo AI answers fetcher',
            'category'             => 'user_fetch',
            'default_enabled'      => false,
        ],
    ];

    /** @var array<string, BotDefinition>|null */
    private ?array $runtimeCache = null;

    /**
     * @param array<string, array<string, mixed>> $additionalBots catalogue rows added via di.xml
     */
    public function __construct(
        private readonly RobotsTxtAeoCache   $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface     $logger,
        private readonly array               $additionalBots = [],
    ) {}

    /** @return array<string, BotDefinition> */
    public function all(): array
    {
        if ($this->runtimeCache !== null) {
            return $this->runtimeCache;
        }

        $cached = $this->cache->load($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            try {
                $data = $this->serializer->unserialize($cached);
                if (is_array($data)) {
                    return $this->runtimeCache = $this->rehydrate($data);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Angeo_RobotsTxtAeo] Bot registry cache corrupted: ' . $e->getMessage());
            }
        }

        return $this->runtimeCache = $this->buildAndCache();
    }

    public function get(string $key): ?BotDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Look up a definition by its robots.txt product token (case-insensitive).
     *
     * @since 4.0.0
     */
    public function getByUserAgent(string $userAgent): ?BotDefinition
    {
        $needle = strtolower(trim($userAgent));
        foreach ($this->all() as $bot) {
            if (strtolower($bot->userAgent) === $needle) {
                return $bot;
            }
        }
        return null;
    }

    /**
     * The catalogue as shipped plus di.xml additions, without cache.
     *
     * @return array<string, BotDefinition>
     */
    public function builtins(): array
    {
        $result = [];
        foreach ($this->catalogue() as $key => $data) {
            $result[$key] = BotDefinition::fromArray((string) $key, $data + ['source' => 'builtin']);
        }
        return $result;
    }

    /** Drop cache so the next all() call re-hydrates from the catalogue. */
    public function invalidate(): void
    {
        $this->runtimeCache = null;
        $this->cache->remove($this->cacheKey());
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Built-in rows merged with di.xml additions. A di.xml row with a built-in
     * key overrides that entry field by field.
     *
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(): array
    {
        $catalogue = self::BUILTIN_BOTS;

        foreach ($this->additionalBots as $key => $row) {
            if (!is_string($key) || $key === '' || !is_array($row)) {
                continue;
            }
            $catalogue[$key] = isset($catalogue[$key])
                ? array_merge($catalogue[$key], $row)
                : $row;
        }

        return $catalogue;
    }

    /**
     * Cache key bound to the di.xml payload — otherwise a deployment that adds
     * or edits a bot keeps serving the previous catalogue until someone
     * remembers to flush.
     */
    private function cacheKey(): string
    {
        if ($this->additionalBots === []) {
            return self::CACHE_KEY_PREFIX;
        }

        return self::CACHE_KEY_PREFIX . '_' . substr(sha1(json_encode($this->additionalBots) ?: ''), 0, 12);
    }

    /** @return array<string, BotDefinition> */
    private function buildAndCache(): array
    {
        $merged = $this->builtins();

        try {
            $this->cache->save(
                $this->serializer->serialize(array_map(fn(BotDefinition $b) => $b->toArray(), $merged)),
                $this->cacheKey(),
                [RobotsTxtAeoCache::CACHE_TAG],
                self::CACHE_TTL
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_RobotsTxtAeo] Failed to cache bot registry: ' . $e->getMessage());
        }

        return $merged;
    }

    /**
     * @param array<string, array<string, mixed>> $data
     * @return array<string, BotDefinition>
     */
    private function rehydrate(array $data): array
    {
        $result = [];
        foreach ($data as $key => $row) {
            if (is_array($row)) {
                $result[(string) $key] = BotDefinition::fromArray((string) $key, $row);
            }
        }
        return $result;
    }
}
