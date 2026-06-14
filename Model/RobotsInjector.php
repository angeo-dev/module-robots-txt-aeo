<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Parser\ParsedRobotsTxt;
use Angeo\RobotsTxtAeo\Model\Parser\RobotsTxtParser;
use Angeo\RobotsTxtAeo\Model\Parser\UserAgentGroup;
use Angeo\RobotsTxtAeo\Model\Rep\AccessDecision;
use Angeo\RobotsTxtAeo\Model\Rep\RepMatcher;

/**
 * Core injection logic for AI bot rules.
 *
 * Responsibilities:
 *   - INJECT mode: prepend a managed Angeo block, preserve existing content.
 *   - REPLACE mode: rebuild robots.txt from scratch while keeping wildcard Disallow.
 *   - Idempotent: running twice yields the same output as running once.
 *   - Sitemap directives are emitted once, at the end of the file.
 *
 * v2.0.0 output sanitisation — produces robots.txt that the Angeo_AeoAudit
 * v3 RobotsTxtChecker does not flag as a syntax issue:
 *   - Crawl-delay is suppressed for bots that documentedly ignore it
 *     (GPTBot, ClaudeBot, Google-Extended).
 *   - When a bot has Disallow: /, the implicit Allow: / fallback is dropped
 *     so we never emit Allow: / + Disallow: / on the same agent.
 *   - User-agent strings are sanitised at the BotDefinition layer (versions
 *     like "GPTBot/1.0" are stripped to "GPTBot").
 *   - Sitemap URLs are normalised to https:// when the store's base URL is
 *     https:// — the audit warns about http:// sitemap entries.
 *
 * Parsing of existing content is delegated to RobotsTxtParser — this class
 * only orchestrates structural changes and renders output.
 *
 * @since 2.0.0 — replaced hand-rolled stripStandaloneBotEntries() with a
 *                parser-driven implementation; added syntax sanitisation.
 * @since 3.0.0 — LOSSLESS round-trip: top-level License: directives, other
 *                unknown top-level lines, and group-scoped extra directives
 *                (Content-Usage, Content-Signal, …) found in the existing
 *                robots.txt are preserved through INJECT-mode rebuilds
 *                (previously silently destroyed). Optional emission of IETF
 *                Content-Usage, Cloudflare Content-Signal, and RSL License
 *                directives (all off by default). validate() now also
 *                reports RFC 9309 effective access per bot via RepMatcher.
 */
class RobotsInjector
{
    private const BLOCK_HEADER    = '# Angeo AEO — AI Crawler Rules';
    private const BLOCK_FOOTER    = '# End Angeo AEO block';
    private const BLOCK_PATTERN   = '/# Angeo AEO — AI Crawler Rules.*?# End Angeo AEO block\n?/s';
    private const SITEMAP_HEADER  = '# Angeo AEO — Sitemaps';
    private const SITEMAP_FOOTER  = '# End Angeo AEO sitemaps';
    private const SITEMAP_PATTERN = '/# Angeo AEO — Sitemaps.*?# End Angeo AEO sitemaps\n?/s';

    public function __construct(
        private readonly Config           $config,
        private readonly RobotsTxtParser  $parser,
        private readonly SitemapResolver  $sitemapResolver,
        private readonly UrlFetcher       $urlFetcher,
        private readonly RepMatcher       $repMatcher,
    ) {}

    /**
     * Main entry point. Called by the plugin with the current robots.txt content.
     */
    public function process(string $existingContent, ?int $storeId = null): string
    {
        if (!$this->config->isEnabled($storeId)) {
            return $existingContent;
        }

        $enabledBots = $this->config->getEnabledBots($storeId);
        if (empty($enabledBots)) {
            return $existingContent;
        }

        if ($this->config->getMode($storeId) === Config::MODE_REPLACE) {
            return $this->buildReplaceContent($enabledBots, $existingContent, $storeId);
        }

        return $this->buildInjectContent($enabledBots, $existingContent, $storeId);
    }

    /**
     * INJECT mode: prepend the Angeo block, preserve everything else.
     *
     * @param array<string, BotDefinition> $enabledBots
     */
    private function buildInjectContent(array $enabledBots, string $existingContent, ?int $storeId): string
    {
        $signalLines = $this->config->getContentSignalLines($storeId);

        $block   = $this->buildAngeoBlock($enabledBots, $signalLines);
        $cleaned = $this->stripExistingManagedBlocks($existingContent);
        $cleaned = $this->stripStandaloneBotEntries($enabledBots, $cleaned);

        $sitemaps      = $this->resolveSitemaps($storeId);
        $sitemapBlock  = $this->buildSitemapBlock($sitemaps, $cleaned);

        $licenseLine = $this->buildLicenseLine($storeId, $cleaned);

        $result = $licenseLine . $block . "\n" . ltrim($cleaned);
        if ($sitemapBlock !== '') {
            $result = rtrim($result, "\n") . "\n\n" . $sitemapBlock;
        }
        return $result;
    }

    /**
     * RSL 1.0 License directive (global, top of file). Deduplicated against
     * License: lines already present in the preserved existing content.
     *
     * @since 3.0.0
     */
    private function buildLicenseLine(?int $storeId, string $existingContent): string
    {
        if (!$this->config->isRslEnabled($storeId)) {
            return '';
        }
        $url = $this->config->getRslLicenseUrl($storeId);
        if ($url === '') {
            return '';
        }
        if (stripos($existingContent, 'License: ' . $url) !== false) {
            return ''; // already present in the preserved content
        }
        return 'License: ' . $url . "\n";
    }

    /**
     * REPLACE mode: rebuild robots.txt from scratch.
     *
     * @param array<string, BotDefinition> $enabledBots
     */
    private function buildReplaceContent(array $enabledBots, string $existingContent, ?int $storeId): string
    {
        $signalLines = $this->config->getContentSignalLines($storeId);
        $licenseLine = $this->buildLicenseLine($storeId, '');

        $block = $licenseLine . $this->buildAngeoBlock($enabledBots, $signalLines);

        $customContent = trim($this->config->getCustomContent($storeId));
        if ($customContent !== '') {
            return $block . "\n" . $customContent . "\n" . $this->buildSitemapBlock(
                $this->resolveSitemaps($storeId),
                $customContent
            );
        }

        $wildcard  = "\n# Default rules\n";
        $wildcard .= "User-agent: *\n";

        $parsed = $this->parser->parse($existingContent);
        $custom = $this->parser->getWildcardDisallows($parsed);
        if (!empty($custom)) {
            foreach ($custom as $disallow) {
                $wildcard .= "Disallow: " . $disallow . "\n";
            }
        } else {
            $wildcard .= "Disallow: /checkout/\n";
            $wildcard .= "Disallow: /customer/\n";
            $wildcard .= "Disallow: /catalog/product_compare/\n";
            $wildcard .= "Disallow: /catalogsearch/\n";
            $wildcard .= "Disallow: /search/\n";
        }

        $wildcard .= "Allow: /\n";
        foreach ($signalLines as $signalLine) {
            $wildcard .= $signalLine . "\n";
        }

        $sitemapBlock = $this->buildSitemapBlock($this->resolveSitemaps($storeId), '');

        $result = $block . $wildcard;
        if ($sitemapBlock !== '') {
            $result .= "\n" . $sitemapBlock;
        }
        return $result;
    }

    /**
     * Build the managed Angeo block with per-bot Allow/Disallow/Crawl-delay.
     *
     * Audit-clean output guarantees:
     *  - No Allow: / + Disallow: / conflict on the same agent.
     *  - No Crawl-delay on bots that documentedly ignore it.
     *
     * @param array<string, BotDefinition> $enabledBots
     * @param string[] $signalLines group-scoped Content-Usage / Content-Signal
     *                              lines to append to every managed group
     */
    private function buildAngeoBlock(array $enabledBots, array $signalLines = []): string
    {
        $lines   = [];
        $lines[] = self::BLOCK_HEADER;
        $lines[] = '# https://angeo.dev | module-robots-txt-aeo';
        $lines[] = '# Do not edit this block manually — manage via Stores > Config > Angeo > Robots.txt AEO';
        $lines[] = '';

        foreach ($enabledBots as $bot) {
            $lines[] = 'User-agent: ' . $bot->userAgent;

            $disallow = $bot->disallowPaths;
            $disallowBlocksRoot = $this->pathListBlocksRoot($disallow);

            // Resolve Allow paths — when explicit Disallow: / is present we
            // intentionally omit the implicit Allow: / fallback (audit warns
            // about Allow: / + Disallow: / on the same agent).
            $allow = $bot->allowPaths;
            if (empty($allow) && !$disallowBlocksRoot) {
                $allow = ['/'];
            } elseif ($disallowBlocksRoot) {
                $allow = array_values(array_filter($allow, fn(string $p) => $p !== '/' && $p !== '/*'));
            }

            foreach ($allow as $path) {
                $lines[] = 'Allow: ' . $path;
            }
            foreach ($disallow as $path) {
                $lines[] = 'Disallow: ' . $path;
            }

            // Crawl-delay — emit only when set AND support is documented
            // (tri-state metadata; unknown is treated as unsupported).
            if ($bot->crawlDelay !== null && $bot->crawlDelay > 0 && !$bot->ignoresCrawlDelay()) {
                $delay = $bot->crawlDelay == (int) $bot->crawlDelay
                    ? (string) (int) $bot->crawlDelay
                    : (string) $bot->crawlDelay;
                $lines[] = 'Crawl-delay: ' . $delay;
            }

            foreach ($signalLines as $signalLine) {
                $lines[] = $signalLine;
            }

            $lines[] = '';
        }

        $lines[] = self::BLOCK_FOOTER;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Resolve sitemaps for the given store, forcing https:// when the store
     * itself is HTTPS so we never emit http:// entries the audit warns about.
     *
     * @return string[]
     */
    private function resolveSitemaps(?int $storeId): array
    {
        $base = $this->urlFetcher->getBaseUrl($storeId);
        $baseIsHttps = stripos($base, 'https://') === 0;

        $sitemaps = $this->sitemapResolver->resolve($storeId);
        if (!$baseIsHttps) {
            return $sitemaps;
        }

        return array_map(static function (string $url) {
            if (stripos($url, 'http://') === 0) {
                return 'https://' . substr($url, 7);
            }
            return $url;
        }, $sitemaps);
    }

    /**
     * Build the Sitemap block. Returns '' if no sitemaps configured or all are
     * already present in $existingContent (avoid duplicates).
     *
     * @param string[] $sitemaps
     */
    private function buildSitemapBlock(array $sitemaps, string $existingContent): string
    {
        if (empty($sitemaps)) {
            return '';
        }

        $toEmit = [];
        foreach ($sitemaps as $url) {
            if (stripos($existingContent, 'Sitemap: ' . $url) === false) {
                $toEmit[] = $url;
            }
        }

        if (empty($toEmit)) {
            return '';
        }

        $lines   = [];
        $lines[] = self::SITEMAP_HEADER;
        foreach ($toEmit as $url) {
            $lines[] = 'Sitemap: ' . $url;
        }
        $lines[] = self::SITEMAP_FOOTER;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Remove previous Angeo-managed blocks (bot block + sitemap block) so
     * regeneration is idempotent.
     */
    private function stripExistingManagedBlocks(string $content): string
    {
        $content = preg_replace(self::BLOCK_PATTERN,   '', $content) ?? $content;
        $content = preg_replace(self::SITEMAP_PATTERN, '', $content) ?? $content;
        return preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
    }

    /**
     * Remove ad-hoc User-agent groups for our bots that may have been added
     * outside the managed block — using the parser to identify groups, then
     * rebuilding the document.
     *
     * @param array<string, BotDefinition> $enabledBots
     */
    private function stripStandaloneBotEntries(array $enabledBots, string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $userAgents = [];
        foreach ($enabledBots as $bot) {
            $userAgents[strtolower($bot->userAgent)] = true;
        }

        $parsed = $this->parser->parse($content);

        // Filter out groups whose user-agents are all owned by us. Groups that
        // mix our UAs with foreign UAs are kept (and the foreign UAs preserved).
        $keptGroups = [];
        foreach ($parsed->groups as $group) {
            $foreign = [];
            foreach ($group->userAgents as $ua) {
                if (!isset($userAgents[strtolower(trim($ua))])) {
                    $foreign[] = $ua;
                }
            }
            if (empty($foreign)) {
                continue; // whole group is ours — drop it
            }
            $newGroup = new UserAgentGroup($foreign);
            $newGroup->allow           = $group->allow;
            $newGroup->disallow        = $group->disallow;
            $newGroup->crawlDelay      = $group->crawlDelay;
            $newGroup->extraDirectives = $group->extraDirectives;
            $keptGroups[] = $newGroup;
        }

        return $this->renderRobotsTxt($parsed, $keptGroups);
    }

    /**
     * Render a ParsedRobotsTxt (with overridden group list) back to a string.
     *
     * @param UserAgentGroup[] $groups
     */
    private function renderRobotsTxt(ParsedRobotsTxt $parsed, array $groups): string
    {
        $lines = [];

        foreach ($parsed->topComments as $comment) {
            $lines[] = $comment;
        }

        // Lossless round-trip (v3.0.0): re-emit top-level directives that are
        // not ours to manage — RSL License lines and any unknown top-level
        // directives — instead of silently dropping them.
        foreach ($parsed->licenses as $licenseUrl) {
            $lines[] = 'License: ' . $licenseUrl;
        }
        foreach ($parsed->unknownDirectives as $unknown) {
            $lines[] = $unknown;
        }

        $first = true;
        foreach ($groups as $group) {
            if (!$first) {
                $lines[] = '';
            }
            $first = false;
            foreach ($group->userAgents as $ua) {
                $lines[] = 'User-agent: ' . $ua;
            }
            foreach ($group->allow as $path) {
                $lines[] = 'Allow: ' . $path;
            }
            foreach ($group->disallow as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            if ($group->crawlDelay !== null) {
                $delay = $group->crawlDelay == (int) $group->crawlDelay
                    ? (string) (int) $group->crawlDelay
                    : (string) $group->crawlDelay;
                $lines[] = 'Crawl-delay: ' . $delay;
            }
            foreach ($group->extraDirectives as $extra) {
                $lines[] = $extra;
            }
        }

        if (!empty($parsed->sitemaps)) {
            $lines[] = '';
            foreach ($parsed->sitemaps as $sitemap) {
                $lines[] = 'Sitemap: ' . $sitemap;
            }
        }

        $rendered = implode("\n", $lines);
        if ($rendered !== '' && !str_ends_with($rendered, "\n")) {
            $rendered .= "\n";
        }
        return $rendered;
    }

    /**
     * @param string[] $paths
     */
    private function pathListBlocksRoot(array $paths): bool
    {
        foreach ($paths as $path) {
            $trimmed = trim($path);
            if ($trimmed === '/' || $trimmed === '/*') {
                return true;
            }
        }
        return false;
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    public function preview(string $existingContent, ?int $storeId = null): string
    {
        return $this->process($existingContent, $storeId);
    }

    /**
     * Check which configured bots are present in the given content.
     *
     * @return array{missing: string[], present: string[], effective: array<string, array<string, mixed>>, warnings: string[]}
     */
    public function validate(string $content, ?int $storeId = null): array
    {
        $parsed = $this->parser->parse($content);
        $result = ['missing' => [], 'present' => [], 'effective' => [], 'warnings' => []];

        foreach ($this->config->getEnabledBots($storeId) as $bot) {
            if ($this->parser->hasUserAgent($parsed, $bot->userAgent)) {
                $result['present'][] = $bot->userAgent;
            } else {
                $result['missing'][] = $bot->userAgent;
            }

            // RFC 9309 effective decision for "/" (v3.0.0): a bot can be
            // "present" yet blocked from the root by its merged rules — or
            // absent yet allowed via the wildcard group.
            $decision = $this->repMatcher->isAllowed($parsed, $bot->userAgent, '/');
            $result['effective'][$bot->userAgent] = $decision->toArray();

            if ($bot->deprecated) {
                $result['warnings'][] = sprintf(
                    '%s is deprecated by its vendor — review whether it should remain enabled.',
                    $bot->userAgent
                );
            }
        }

        return $result;
    }
}
