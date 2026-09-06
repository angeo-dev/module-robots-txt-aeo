<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Parser\ParsedRobotsTxt;
use Angeo\RobotsTxtAeo\Model\Parser\RobotsTxtParser;
use Angeo\RobotsTxtAeo\Model\Parser\UserAgentGroup;
use Angeo\RobotsTxtAeo\Model\Rep\RepMatcher;
use Angeo\RobotsTxtAeo\Model\Sanitizer\RobotsLineSanitizer;
use Psr\Log\LoggerInterface;

/**
 * Core injection logic for AI bot rules.
 *
 * Responsibilities:
 *   - INJECT mode: prepend a managed Angeo block, preserve everything else.
 *   - REPLACE mode: rebuild robots.txt while keeping the wildcard rules.
 *   - Idempotent: running twice yields the same output as running once.
 *   - Sitemap directives are emitted once, at the end of the file.
 *
 * @since 2.0.0 — parser-driven implementation; syntax sanitisation.
 * @since 3.0.0 — third-party directives (License, Content-Usage,
 *                Content-Signal) survive a rebuild; optional emission of
 *                Content-Usage, Content-Signal and RSL License.
 * @since 4.0.0 — three changes worth knowing about before upgrading:
 *
 *   1. INJECT mode no longer re-renders the file. Previous versions parsed the
 *      operator's robots.txt and printed the model back out, which silently
 *      reformatted it and dropped comments inside groups. The document is now
 *      edited in place: only the lines belonging to groups this module owns
 *      are cut, every other byte is left exactly as the operator wrote it.
 *
 *   2. REPLACE mode refuses to run when the existing wildcard group blocks the
 *      whole site. Up to 3.0.0 a "User-agent: * / Disallow: /" file — a
 *      staging or pre-launch shop — was rebuilt into a crawlable one, because
 *      the "/" entry was filtered out while collecting wildcard rules. Opening
 *      a deliberately closed site is not a change any module should make on
 *      its own, so the original content is returned untouched and validate()
 *      reports why.
 *
 *   3. Every value is sanitised at render time, not only on save. Config can
 *      be written straight to the database or shipped in app/etc/config.php,
 *      bypassing backend models; a newline in a stored path would otherwise
 *      forge extra directives in a public file.
 */
class RobotsInjector
{
    private const BLOCK_HEADER    = '# Angeo AEO — AI Crawler Rules';
    private const BLOCK_FOOTER    = '# End Angeo AEO block';
    private const BLOCK_PATTERN   = '/# Angeo AEO — AI Crawler Rules.*?# End Angeo AEO block\n?/s';
    private const SITEMAP_HEADER  = '# Angeo AEO — Sitemaps';
    private const SITEMAP_FOOTER  = '# End Angeo AEO sitemaps';
    private const SITEMAP_PATTERN = '/# Angeo AEO — Sitemaps.*?# End Angeo AEO sitemaps\n?/s';

    /** Warning key surfaced by validate() when REPLACE mode stands down. */
    public const WARNING_SITE_BLOCKED = 'site_blocked';

    public function __construct(
        private readonly Config              $config,
        private readonly RobotsTxtParser     $parser,
        private readonly SitemapResolver     $sitemapResolver,
        private readonly UrlFetcher          $urlFetcher,
        private readonly RepMatcher          $repMatcher,
        private readonly RobotsLineSanitizer $sanitizer,
        private readonly LoggerInterface     $logger,
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
            if ($this->replaceWouldUnblockSite($existingContent)) {
                $this->logger->warning(
                    '[Angeo_RobotsTxtAeo] REPLACE mode stood down: the current robots.txt blocks '
                    . 'the whole site (User-agent: * / Disallow: /). Rebuilding it would open the '
                    . 'site to crawlers. Remove the site-wide Disallow, or switch to Inject mode.'
                );
                return $existingContent;
            }

            return $this->buildReplaceContent($enabledBots, $existingContent, $storeId);
        }

        return $this->buildInjectContent($enabledBots, $existingContent, $storeId);
    }

    /**
     * Whether a REPLACE-mode rebuild would turn a fully blocked site into a
     * crawlable one.
     *
     * @since 4.0.0
     */
    public function replaceWouldUnblockSite(string $existingContent): bool
    {
        if (trim($existingContent) === '') {
            return false;
        }

        return $this->parser->wildcardBlocksSite($this->parser->parse($existingContent));
    }

    // ─── INJECT ──────────────────────────────────────────────────────────────

    /**
     * INJECT mode: prepend the Angeo block, preserve everything else verbatim.
     *
     * @param array<string, BotDefinition> $enabledBots
     */
    private function buildInjectContent(array $enabledBots, string $existingContent, ?int $storeId): string
    {
        $signalLines = $this->config->getContentSignalLines($storeId);
        $perBot      = $this->config->getSignalPlacement($storeId) === Config::PLACEMENT_PER_BOT;

        $cleaned = $this->stripExistingManagedBlocks($existingContent);
        $cleaned = $this->stripStandaloneBotEntries($enabledBots, $cleaned);

        if (!$perBot && $signalLines !== []) {
            $cleaned = $this->attachSignalsToWildcardGroup($cleaned, $signalLines);
        }

        $block = $this->buildAngeoBlock(
            $enabledBots,
            $perBot ? $signalLines : [],
            !$perBot && $signalLines !== [] && !$this->hasWildcardGroup($cleaned) ? $signalLines : [],
            $storeId
        );

        $licenseLine  = $this->buildLicenseLine($storeId, $cleaned);
        $sitemapBlock = $this->buildSitemapBlock($this->resolveSitemaps($storeId), $cleaned);

        $result = $licenseLine . $block . "\n" . ltrim($cleaned);
        if ($sitemapBlock !== '') {
            $result = rtrim($result, "\n") . "\n\n" . $sitemapBlock;
        }

        return $result;
    }

    /**
     * Remove the groups this module owns, editing the document in place.
     *
     * A group whose user-agents are ALL ours is cut whole. A group that mixes
     * our tokens with foreign ones keeps its rules and loses only the
     * "User-agent: <ours>" lines. Every other line — comments, spacing,
     * directives we do not manage — survives byte for byte.
     *
     * @param array<string, BotDefinition> $enabledBots
     */
    private function stripStandaloneBotEntries(array $enabledBots, string $content): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $ours = [];
        foreach ($enabledBots as $bot) {
            $ours[strtolower($bot->userAgent)] = true;
        }

        $parsed = $this->parser->parse($content);
        $drop   = [];

        foreach ($parsed->groups as $group) {
            if (!$group->hasSpan()) {
                continue;
            }

            $foreign = 0;
            foreach ($group->userAgents as $ua) {
                if (!isset($ours[strtolower(trim($ua))])) {
                    $foreign++;
                }
            }

            if ($foreign === 0) {
                for ($line = (int) $group->startLine; $line <= (int) $group->endLine; $line++) {
                    $drop[$line] = true;
                }
                continue;
            }

            // Mixed group: drop only our User-agent lines.
            foreach ($this->userAgentLinesIn($parsed, $group) as $line => $token) {
                if (isset($ours[strtolower($token)])) {
                    $drop[$line] = true;
                }
            }
        }

        if ($drop === []) {
            return $content;
        }

        $kept = [];
        foreach ($parsed->lines as $index => $line) {
            if (!isset($drop[$index])) {
                $kept[] = $line;
            }
        }

        return $this->collapseBlankRuns(implode("\n", $kept));
    }

    /**
     * Map line index => user-agent token for the User-agent lines of a group.
     *
     * @return array<int, string>
     */
    private function userAgentLinesIn(ParsedRobotsTxt $parsed, UserAgentGroup $group): array
    {
        $lines = [];

        for ($index = (int) $group->startLine; $index <= (int) $group->endLine; $index++) {
            $line = $parsed->lines[$index] ?? '';
            if (preg_match('/^\s*user-agent\s*:\s*([^#]*)/i', $line, $match)) {
                $lines[$index] = trim($match[1]);
            }
        }

        return $lines;
    }

    /**
     * Append the content signal lines to the existing wildcard group, in place.
     *
     * @param string[] $signalLines
     */
    private function attachSignalsToWildcardGroup(string $content, array $signalLines): string
    {
        if (trim($content) === '') {
            return $content;
        }

        $parsed = $this->parser->parse($content);
        $target = null;

        foreach ($parsed->groups as $group) {
            if ($group->matches('*') && $group->hasSpan()) {
                $target = $group; // last wildcard group wins
            }
        }

        if ($target === null) {
            return $content;
        }

        $missing = [];
        foreach ($signalLines as $line) {
            if (stripos($content, $line) === false) {
                $missing[] = $line;
            }
        }

        if ($missing === []) {
            return $content;
        }

        $lines  = $parsed->lines;
        $insert = (int) $target->endLine;

        array_splice($lines, $insert + 1, 0, $missing);

        return implode("\n", $lines);
    }

    private function hasWildcardGroup(string $content): bool
    {
        if (trim($content) === '') {
            return false;
        }

        foreach ($this->parser->parse($content)->groups as $group) {
            if ($group->matches('*')) {
                return true;
            }
        }

        return false;
    }

    // ─── REPLACE ─────────────────────────────────────────────────────────────

    /**
     * REPLACE mode: rebuild robots.txt from scratch.
     *
     * @param array<string, BotDefinition> $enabledBots
     */
    private function buildReplaceContent(array $enabledBots, string $existingContent, ?int $storeId): string
    {
        $signalLines = $this->config->getContentSignalLines($storeId);
        $perBot      = $this->config->getSignalPlacement($storeId) === Config::PLACEMENT_PER_BOT;
        $parsed      = $this->parser->parse($existingContent);

        $licenseLine = $this->buildLicenseLine($storeId, '');
        $preserved   = $this->preservedTopLevel($parsed, $licenseLine);

        $block = $licenseLine . $preserved . $this->buildAngeoBlock(
            $enabledBots,
            $perBot ? $signalLines : [],
            [],
            $storeId
        );

        $customContent = $this->sanitizer->block($this->config->getCustomContent($storeId));
        if (trim($customContent) !== '') {
            $wildcard = "\n" . trim($customContent) . "\n";
            if (!$perBot && $signalLines !== []) {
                $wildcard .= $this->signalGroup($signalLines, $storeId);
            }

            return $block . $wildcard . $this->buildSitemapBlock(
                $this->resolveSitemaps($storeId),
                $customContent
            );
        }

        $wildcard  = "\n# Default rules\n";
        $wildcard .= "User-agent: *\n";

        $custom = $this->sanitizer->paths($this->parser->getWildcardDisallows($parsed));
        if (!empty($custom)) {
            foreach ($custom as $disallow) {
                $wildcard .= 'Disallow: ' . $disallow . "\n";
            }
        } else {
            $wildcard .= "Disallow: /checkout/\n";
            $wildcard .= "Disallow: /customer/\n";
            $wildcard .= "Disallow: /catalog/product_compare/\n";
            $wildcard .= "Disallow: /catalogsearch/\n";
            $wildcard .= "Disallow: /search/\n";
        }

        $wildcard .= "Allow: /\n";

        if (!$perBot) {
            foreach ($this->policyComment($storeId, $signalLines) as $comment) {
                $wildcard .= $comment . "\n";
            }
        }
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
     * Top-level directives from the previous file that are not ours to manage.
     * REPLACE mode used to discard them; a License line an operator added by
     * hand should survive a rebuild exactly as it does in INJECT mode.
     *
     * @since 4.0.0
     */
    private function preservedTopLevel(ParsedRobotsTxt $parsed, string $licenseLine): string
    {
        $lines = [];

        foreach ($parsed->licenses as $license) {
            $value = $this->sanitizer->value($license);
            if ($value === '' || str_contains($licenseLine, 'License: ' . $value)) {
                continue;
            }
            $lines[] = 'License: ' . $value;
        }

        foreach ($parsed->unknownDirectives as $directive) {
            $value = $this->sanitizer->line($directive);
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        $lines = array_values(array_unique($lines));

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /**
     * A standalone wildcard group carrying only the content signals — used
     * when there is no wildcard group to attach them to.
     *
     * @param string[] $signalLines
     */
    private function signalGroup(array $signalLines, ?int $storeId): string
    {
        if ($signalLines === []) {
            return '';
        }

        $lines = [''];
        foreach ($this->policyComment($storeId, $signalLines) as $comment) {
            $lines[] = $comment;
        }
        $lines[] = 'User-agent: *';
        foreach ($signalLines as $signalLine) {
            $lines[] = $signalLine;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * The short explanatory comment Cloudflare's managed robots.txt carries
     * above its signals. Off-by-config; omitted when there is nothing to
     * explain.
     *
     * @param string[] $signalLines
     * @return string[]
     */
    private function policyComment(?int $storeId, array $signalLines): array
    {
        if ($signalLines === [] || !$this->config->isSignalPolicyCommentEnabled($storeId)) {
            return [];
        }

        return [
            '# Content signals: "yes" means allowed, "no" means not allowed,',
            '# and no signal means no preference has been expressed.',
            '# These preferences may carry legal significance.',
        ];
    }

    // ─── Shared rendering ────────────────────────────────────────────────────

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

        $url = $this->sanitizer->value($this->config->getRslLicenseUrl($storeId));
        if ($url === '') {
            return '';
        }
        if (stripos($existingContent, 'License: ' . $url) !== false) {
            return ''; // already present in the preserved content
        }

        return 'License: ' . $url . "\n";
    }

    /**
     * Build the managed Angeo block with per-bot Allow/Disallow/Crawl-delay.
     *
     * Audit-clean output guarantees:
     *  - No Allow: / + Disallow: / conflict on the same agent.
     *  - No Crawl-delay on bots that do not document support for it.
     *  - Every emitted value passes through the sanitiser.
     *
     * @param array<string, BotDefinition> $enabledBots
     * @param string[] $perBotSignals    signal lines repeated in every managed group
     * @param string[] $trailingSignals  signal lines emitted as a wildcard group after the bots
     */
    private function buildAngeoBlock(
        array $enabledBots,
        array $perBotSignals = [],
        array $trailingSignals = [],
        ?int $storeId = null,
    ): string {
        $lines   = [];
        $lines[] = self::BLOCK_HEADER;
        $lines[] = '# https://angeo.dev | module-robots-txt-aeo';
        $lines[] = '# Do not edit this block manually — manage via Stores > Config > Angeo > Robots.txt AEO';
        $lines[] = '';

        foreach ($enabledBots as $bot) {
            $token = $this->sanitizer->userAgentToken($bot->userAgent);
            if ($token === '') {
                continue;
            }

            $lines[] = 'User-agent: ' . $token;

            $disallow           = $this->sanitizer->paths($bot->disallowPaths);
            $disallowBlocksRoot = $this->pathListBlocksRoot($disallow);

            // Resolve Allow paths — when explicit Disallow: / is present we
            // omit the implicit Allow: / fallback (audit warns about
            // Allow: / + Disallow: / on the same agent).
            $allow = $this->sanitizer->paths($bot->allowPaths);
            if (empty($allow) && !$disallowBlocksRoot) {
                $allow = ['/'];
            } elseif ($disallowBlocksRoot) {
                $allow = array_values(array_filter($allow, static fn(string $p) => $p !== '/' && $p !== '/*'));
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
                $lines[] = 'Crawl-delay: ' . $this->formatDelay($bot->crawlDelay);
            }

            foreach ($perBotSignals as $signalLine) {
                $lines[] = $signalLine;
            }

            $lines[] = '';
        }

        if ($trailingSignals !== []) {
            foreach ($this->policyComment($storeId, $trailingSignals) as $comment) {
                $lines[] = $comment;
            }
            $lines[] = 'User-agent: *';
            foreach ($trailingSignals as $signalLine) {
                $lines[] = $signalLine;
            }
            $lines[] = '';
        }

        $lines[] = self::BLOCK_FOOTER;

        return implode("\n", $lines) . "\n";
    }

    private function formatDelay(float $delay): string
    {
        return $delay == (int) $delay ? (string) (int) $delay : (string) $delay;
    }

    /**
     * Resolve sitemaps for the given store, forcing https:// when the store
     * itself is HTTPS so we never emit http:// entries the audit warns about.
     *
     * @return string[]
     */
    private function resolveSitemaps(?int $storeId): array
    {
        $base        = $this->urlFetcher->getBaseUrl($storeId);
        $baseIsHttps = stripos($base, 'https://') === 0;

        $sitemaps = $this->sanitizer->paths($this->sitemapResolver->resolve($storeId));
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
     * Build the Sitemap block. Returns '' if no sitemaps are configured or all
     * of them are already present in $existingContent (avoid duplicates).
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
        $content = preg_replace(self::BLOCK_PATTERN, '', $content) ?? $content;
        $content = preg_replace(self::SITEMAP_PATTERN, '', $content) ?? $content;

        return $this->collapseBlankRuns($content);
    }

    private function collapseBlankRuns(string $content): string
    {
        return preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
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

        if ($this->config->getMode($storeId) === Config::MODE_REPLACE
            && $this->parser->wildcardBlocksSite($parsed)
        ) {
            $result['warnings'][] = 'Replace mode is standing down: this robots.txt blocks the whole '
                . 'site (User-agent: * with Disallow: /). The file is served unchanged so the module '
                . 'does not open a site you closed on purpose. Remove the site-wide Disallow, or use '
                . 'Inject mode.';
        }

        return $result;
    }
}
