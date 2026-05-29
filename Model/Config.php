<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Bot\BotRegistry;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Configuration reader for Angeo_RobotsTxtAeo.
 *
 * All values are read at store scope so multi-store installations can have
 * different robots.txt configurations per website / store. The admin form is
 * declared with showInWebsite=1 to surface per-store overrides.
 *
 * Bot metadata lives in BotRegistry — this class only resolves the boolean
 * enabled-state and per-bot path overrides for each known bot key.
 */
class Config
{
    public const XML_PREFIX = 'angeo_robots_txt_aeo/';

    public const MODE_INJECT  = 'inject';
    public const MODE_REPLACE = 'replace';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly BotRegistry          $botRegistry,
    ) {}

    // ─── General toggles ─────────────────────────────────────────────────────

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->getBool('general/enabled', $storeId, true);
    }

    public function getMode(?int $storeId = null): string
    {
        return $this->getString('general/mode', $storeId, self::MODE_INJECT);
    }

    public function getCustomContent(?int $storeId = null): string
    {
        return $this->getString('general/custom_content', $storeId, '');
    }

    // ─── Bot configuration ───────────────────────────────────────────────────

    /**
     * Resolve the list of bots that are enabled in admin and ready to be injected.
     *
     * Each returned BotDefinition has its `allowPaths`, `disallowPaths`, and
     * `crawlDelay` resolved from per-bot config overrides (if any), falling back
     * to the registry default.
     *
     * @return array<string, BotDefinition>
     */
    public function getEnabledBots(?int $storeId = null): array
    {
        $enabled = [];
        foreach ($this->botRegistry->all() as $key => $bot) {
            if (!$this->isBotEnabled($key, $bot, $storeId)) {
                continue;
            }
            $enabled[$key] = $this->resolveBotOverrides($bot, $storeId);
        }
        return $enabled;
    }

    /**
     * @return array<string, BotDefinition>
     */
    public function getAllBots(): array
    {
        return $this->botRegistry->all();
    }

    /**
     * @return array<string, BotDefinition>
     */
    public function getBuiltinBots(): array
    {
        return $this->botRegistry->builtins();
    }

    private function isBotEnabled(string $key, BotDefinition $bot, ?int $storeId): bool
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PREFIX . 'bots/' . $key,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // For newly-shipped bots without a config row yet — use default_enabled.
        if ($raw === null) {
            return $bot->defaultEnabled;
        }

        return (bool) $raw;
    }

    /**
     * Apply per-bot path overrides from store config.
     *
     * Config keys (all optional):
     *   angeo_robots_txt_aeo/bot_overrides/<key>/allow      — comma/newline list
     *   angeo_robots_txt_aeo/bot_overrides/<key>/disallow   — comma/newline list
     *   angeo_robots_txt_aeo/bot_overrides/<key>/crawl_delay — numeric or empty
     */
    private function resolveBotOverrides(BotDefinition $bot, ?int $storeId): BotDefinition
    {
        $prefix = self::XML_PREFIX . 'bot_overrides/' . $bot->key . '/';

        $allowRaw     = $this->scopeConfig->getValue($prefix . 'allow',       ScopeInterface::SCOPE_STORE, $storeId);
        $disallowRaw  = $this->scopeConfig->getValue($prefix . 'disallow',    ScopeInterface::SCOPE_STORE, $storeId);
        $crawlDelayRaw = $this->scopeConfig->getValue($prefix . 'crawl_delay', ScopeInterface::SCOPE_STORE, $storeId);

        $allowPaths    = $this->parsePathList((string) $allowRaw,    $bot->allowPaths);
        $disallowPaths = $this->parsePathList((string) $disallowRaw, $bot->disallowPaths);

        $crawlDelay = $bot->crawlDelay;
        if ($crawlDelayRaw !== null && $crawlDelayRaw !== '' && is_numeric($crawlDelayRaw)) {
            $crawlDelay = (float) $crawlDelayRaw;
        }

        return new BotDefinition(
            key:               $bot->key,
            userAgent:         $bot->userAgent,
            label:             $bot->label,
            description:       $bot->description,
            respectsRobotsTxt: $bot->respectsRobotsTxt,
            allowPaths:        $allowPaths,
            disallowPaths:     $disallowPaths,
            crawlDelay:        $crawlDelay,
            defaultEnabled:    $bot->defaultEnabled,
            source:            $bot->source,
        );
    }

    /**
     * @param string[] $fallback
     * @return string[]
     */
    private function parsePathList(string $raw, array $fallback): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }

        $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        $items = array_filter(array_map('trim', $items), 'strlen');
        return array_values(array_unique($items));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function getBool(string $path, ?int $storeId, bool $default): bool
    {
        $raw = $this->scopeConfig->getValue(self::XML_PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null) {
            return $default;
        }
        return (bool) $raw;
    }

    private function getString(string $path, ?int $storeId, string $default): string
    {
        $raw = $this->scopeConfig->getValue(self::XML_PREFIX . $path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === null || $raw === '') {
            return $default;
        }
        return (string) $raw;
    }
}
