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
 *
 * @since 4.0.0 — Content-Signal placement, signal policy comment, and the
 *                operator-managed list of trusted Web Bot Auth origins.
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
            key:                $bot->key,
            userAgent:          $bot->userAgent,
            label:              $bot->label,
            description:        $bot->description,
            respectsRobotsTxt:  $bot->respectsRobotsTxt,
            allowPaths:         $allowPaths,
            disallowPaths:      $disallowPaths,
            crawlDelay:         $crawlDelay,
            defaultEnabled:     $bot->defaultEnabled,
            source:             $bot->source,
            criticalForAudit:   $bot->criticalForAudit,
            category:           $bot->category,
            tokenOnly:          $bot->tokenOnly,
            deprecated:         $bot->deprecated,
            supportsCrawlDelay: $bot->supportsCrawlDelay,
            ipRangesUrl:        $bot->ipRangesUrl,
            docsUrl:            $bot->docsUrl,
            verification:       $bot->verification,
            jwksUrl:            $bot->jwksUrl,
            signatureAgents:    $bot->signatureAgents,
            ipRangesShared:     $bot->ipRangesShared,
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

    // ─── Content-usage signals & licensing (v3.0.0) ─────────────────────────
    // All three mechanisms below are OFF by default; enabling any of them is
    // an explicit operator decision. See docs/SPECIFICATION-3.0.0.md Tier 3.

    public const SIGNAL_YES   = 'yes';
    public const SIGNAL_NO    = 'no';
    public const SIGNAL_UNSET = 'unset';

    /** Content-Signal placement (4.0.0). */
    public const PLACEMENT_WILDCARD = 'wildcard';
    public const PLACEMENT_PER_BOT  = 'per_bot';

    /**
     * IETF draft-ietf-aipref-attach: emit a group-scoped Content-Usage rule.
     */
    public function isIetfSignalsEnabled(?int $storeId = null): bool
    {
        return $this->getBool('content_signals/ietf_enabled', $storeId, false);
    }

    /**
     * The aipref-vocab preference statement, e.g. "train-ai=n".
     * Sanitised: single line, no '#' (would start a robots.txt comment).
     */
    public function getIetfPreference(?int $storeId = null): string
    {
        $raw = $this->getString('content_signals/ietf_preference', $storeId, 'train-ai=n');
        $raw = str_replace(["\r", "\n", '#'], '', $raw);
        return trim($raw);
    }

    /**
     * Cloudflare Content Signals Policy: emit a group-scoped Content-Signal line.
     */
    public function isCloudflareSignalsEnabled(?int $storeId = null): bool
    {
        return $this->getBool('content_signals/cloudflare_enabled', $storeId, false);
    }

    /**
     * Build the Content-Signal value, e.g. "search=yes, ai-train=no".
     * Signals left "unset" are omitted ("no expressed preference" per the
     * policy). Returns '' when nothing is set.
     */
    public function getCloudflareSignalValue(?int $storeId = null): string
    {
        $parts = [];
        foreach (['search' => 'cf_search', 'ai-train' => 'cf_ai_train', 'ai-input' => 'cf_ai_input'] as $signal => $field) {
            $value = $this->getString('content_signals/' . $field, $storeId, self::SIGNAL_UNSET);
            if ($value === self::SIGNAL_YES || $value === self::SIGNAL_NO) {
                $parts[] = $signal . '=' . $value;
            }
        }
        return implode(', ', $parts);
    }

    /**
     * The group-scoped signal lines to append to every managed bot group
     * (and, in REPLACE mode, to the generated wildcard group).
     *
     * @return string[] e.g. ["Content-Usage: train-ai=n", "Content-Signal: search=yes, ai-train=no"]
     */
    public function getContentSignalLines(?int $storeId = null): array
    {
        $lines = [];

        if ($this->isIetfSignalsEnabled($storeId)) {
            $pref = $this->getIetfPreference($storeId);
            if ($pref !== '') {
                $lines[] = 'Content-Usage: ' . $pref;
            }
        }

        if ($this->isCloudflareSignalsEnabled($storeId)) {
            $value = $this->getCloudflareSignalValue($storeId);
            if ($value !== '') {
                $lines[] = 'Content-Signal: ' . $value;
            }
        }

        return $lines;
    }

    /**
     * RSL 1.0: emit a global License directive pointing at an RSL license file.
     */
    public function isRslEnabled(?int $storeId = null): bool
    {
        return $this->getBool('licensing/rsl_enabled', $storeId, false);
    }

    /**
     * Absolute https:// URL of the RSL license file, or '' when unset/invalid.
     */
    public function getRslLicenseUrl(?int $storeId = null): string
    {
        $url = trim($this->getString('licensing/rsl_license_url', $storeId, ''));
        if ($url === ''
            || !filter_var($url, FILTER_VALIDATE_URL)
            || stripos($url, 'https://') !== 0
        ) {
            return '';
        }
        return $url;
    }

    /**
     * Where the Content-Signal / Content-Usage lines are emitted.
     *
     * Cloudflare's managed robots.txt puts them in the "User-agent: *" group,
     * which is what makes them a site-wide statement. Repeating the line in
     * every managed bot group (the 3.x behaviour) is valid syntax but narrows
     * each copy to that one crawler and makes the file noisy, so "wildcard" is
     * the default from 4.0.0.
     *
     * @since 4.0.0
     */
    public function getSignalPlacement(?int $storeId = null): string
    {
        $value = $this->getString('content_signals/placement', $storeId, self::PLACEMENT_WILDCARD);

        return $value === self::PLACEMENT_PER_BOT ? self::PLACEMENT_PER_BOT : self::PLACEMENT_WILDCARD;
    }

    /**
     * Whether to emit the short explanatory comment block above the signals,
     * the way Cloudflare's managed file does.
     *
     * @since 4.0.0
     */
    public function isSignalPolicyCommentEnabled(?int $storeId = null): bool
    {
        return $this->getBool('content_signals/policy_comment', $storeId, true);
    }

    // ─── Bot verification (v4.0.0) ──────────────────────────────────────────

    /**
     * Extra Web Bot Auth signing origins the operator trusts, one per line.
     *
     * The catalogue ships the origins we could confirm in vendor documentation.
     * When a vendor publishes a new one, an operator should be able to accept
     * it without waiting for a module release — but never by taking the origin
     * from the Signature-Agent header itself, which is the attacker-controlled
     * value being checked.
     *
     * @since 4.0.0
     * @return string[]
     */
    public function getTrustedSignatureAgents(?int $storeId = null): array
    {
        $raw = $this->getString('verification/trusted_signature_agents', $storeId, '');
        if (trim($raw) === '') {
            return [];
        }

        $origins = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && stripos($line, 'https://') === 0) {
                $origins[] = rtrim($line, '/');
            }
        }

        return array_values(array_unique($origins));
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
