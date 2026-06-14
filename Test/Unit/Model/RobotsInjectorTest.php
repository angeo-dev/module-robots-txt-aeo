<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\Parser\RobotsTxtParser;
use Angeo\RobotsTxtAeo\Model\Rep\RepMatcher;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\SitemapResolver;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RobotsInjectorTest extends TestCase
{
    private Config&MockObject          $config;
    private SitemapResolver&MockObject $sitemapResolver;
    private UrlFetcher&MockObject      $urlFetcher;
    private RobotsTxtParser            $parser;
    private RobotsInjector             $injector;

    protected function setUp(): void
    {
        $this->config          = $this->createMock(Config::class);
        $this->sitemapResolver = $this->createMock(SitemapResolver::class);
        $this->urlFetcher      = $this->createMock(UrlFetcher::class);
        $this->parser          = new RobotsTxtParser();
        $this->injector        = new RobotsInjector(
            $this->config,
            $this->parser,
            $this->sitemapResolver,
            $this->urlFetcher,
            new RepMatcher(),
        );

        $this->sitemapResolver->method('resolve')->willReturn([]);
        // Default base URL is HTTPS so the sitemap-https upgrade path is tested
        // by default. Specific tests can override.
        $this->urlFetcher->method('getBaseUrl')->willReturn('https://example.test');
    }

    // ── Short-circuit cases ─────────────────────────────────────────────────

    public function testReturnsUnchangedWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $original = "User-agent: *\nDisallow: /checkout/\n";
        $this->assertSame($original, $this->injector->process($original));
    }

    public function testReturnsUnchangedWhenNoBotsEnabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getEnabledBots')->willReturn([]);

        $original = "User-agent: *\nDisallow: /checkout/\n";
        $this->assertSame($original, $this->injector->process($original));
    }

    // ── INJECT mode ─────────────────────────────────────────────────────────

    public function testInjectAddsAngeoBlockAtTop(): void
    {
        $this->configureInjectMode($this->sampleBots());

        $existing = "User-agent: *\nDisallow: /checkout/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringStartsWith('# Angeo AEO — AI Crawler Rules', $result);
        $this->assertStringContainsString('User-agent: OAI-SearchBot', $result);
        $this->assertStringContainsString('User-agent: PerplexityBot', $result);
        $this->assertStringContainsString('# End Angeo AEO block', $result);
    }

    public function testInjectPreservesExistingWildcardBlock(): void
    {
        $this->configureInjectMode($this->sampleBots());

        $existing = "User-agent: *\nDisallow: /checkout/\nDisallow: /customer/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringContainsString('User-agent: *',         $result);
        $this->assertStringContainsString('Disallow: /checkout/',  $result);
        $this->assertStringContainsString('Disallow: /customer/',  $result);
    }

    public function testInjectIsIdempotent(): void
    {
        $this->configureInjectMode($this->sampleBots());

        $existing  = "User-agent: *\nDisallow: /checkout/\nAllow: /\n";
        $first  = $this->injector->process($existing);
        $second = $this->injector->process($first);

        $this->assertSame($first, $second);
    }

    public function testInjectRunsThreeTimesProducesSameResult(): void
    {
        $this->configureInjectMode($this->sampleBots());

        $existing = "User-agent: *\nDisallow: /checkout/\n";
        $first    = $this->injector->process($existing);
        $second   = $this->injector->process($first);
        $third    = $this->injector->process($second);

        $this->assertSame($first, $third);
    }

    public function testInjectDoesNotDuplicateExistingBotEntry(): void
    {
        $this->configureInjectMode($this->sampleBots());

        $existing = "User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nDisallow: /checkout/\n";
        $result   = $this->injector->process($existing);

        $this->assertSame(1, substr_count($result, 'User-agent: OAI-SearchBot'));
    }

    public function testInjectStripsExistingBotEntryWithMultipleDirectives(): void
    {
        // The new parser-based stripper handles the case the regex version got wrong:
        // bot block with both Allow and Disallow + Crawl-delay
        $this->configureInjectMode($this->sampleBots());

        $existing = <<<TXT
User-agent: OAI-SearchBot
Allow: /
Disallow: /old-path/
Crawl-delay: 60

User-agent: *
Disallow: /checkout/
TXT;
        $result = $this->injector->process($existing);

        // The stray block should be removed; only managed block has OAI-SearchBot
        $this->assertSame(1, substr_count($result, 'User-agent: OAI-SearchBot'));
        $this->assertStringNotContainsString('Disallow: /old-path/', $result);
        $this->assertStringNotContainsString('Crawl-delay: 60',      $result);
        // Wildcard block stays intact
        $this->assertStringContainsString('Disallow: /checkout/', $result);
    }

    // ── Per-bot path overrides ──────────────────────────────────────────────

    public function testInjectEmitsCustomAllowPaths(): void
    {
        $bots = [
            'gptbot' => new BotDefinition(
                key:        'gptbot',
                userAgent:  'GPTBot',
                label:      'GPTBot',
                description: 'test',
                allowPaths: ['/catalog/', '/static/'],
            ),
        ];
        $this->configureInjectMode($bots);

        $result = $this->injector->process('');

        $this->assertStringContainsString('User-agent: GPTBot', $result);
        $this->assertStringContainsString('Allow: /catalog/',   $result);
        $this->assertStringContainsString('Allow: /static/',    $result);
    }

    public function testInjectEmitsDisallowPaths(): void
    {
        $bots = [
            'gptbot' => new BotDefinition(
                key:           'gptbot',
                userAgent:     'GPTBot',
                label:         'GPTBot',
                description:   'test',
                allowPaths:    ['/'],
                disallowPaths: ['/admin/', '/customer/'],
            ),
        ];
        $this->configureInjectMode($bots);

        $result = $this->injector->process('');

        $this->assertStringContainsString('User-agent: GPTBot',      $result);
        $this->assertStringContainsString('Allow: /',                $result);
        $this->assertStringContainsString('Disallow: /admin/',       $result);
        $this->assertStringContainsString('Disallow: /customer/',    $result);
    }

    public function testInjectEmitsCrawlDelayAsInteger(): void
    {
        $bots = [
            'gptbot' => new BotDefinition(
                key:        'gptbot',
                userAgent:  'GPTBot',
                label:      'GPTBot',
                description: 'test',
                crawlDelay: 30.0,
            ),
        ];
        $this->configureInjectMode($bots);

        $result = $this->injector->process('');

        $this->assertStringContainsString('Crawl-delay: 30', $result);
        $this->assertStringNotContainsString('Crawl-delay: 30.0', $result);
    }

    public function testInjectOmitsCrawlDelayWhenNull(): void
    {
        $this->configureInjectMode($this->sampleBots()); // sample bots have no crawl delay

        $result = $this->injector->process('');
        $this->assertStringNotContainsString('Crawl-delay:', $result);
    }

    public function testInjectEmitsDecimalCrawlDelay(): void
    {
        $bots = [
            'gptbot' => new BotDefinition(
                key:         'gptbot',
                userAgent:   'GPTBot',
                label:       'GPTBot',
                description: 'test',
                crawlDelay:  2.5,
            ),
        ];
        $this->configureInjectMode($bots);

        $result = $this->injector->process('');
        $this->assertStringContainsString('Crawl-delay: 2.5', $result);
    }

    // ── Sitemap directive ───────────────────────────────────────────────────

    public function testInjectAppendsSitemapBlock(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_INJECT);
        $this->config->method('getEnabledBots')->willReturn($this->sampleBots());

        // Override the default-empty sitemap resolver from setUp by constructing a fresh injector
        $sitemapResolver = $this->createMock(SitemapResolver::class);
        $sitemapResolver->method('resolve')->willReturn([
            'https://example.com/sitemap.xml',
            'https://example.com/sitemap-products.xml',
        ]);
        $injector = new RobotsInjector($this->config, $this->parser, $sitemapResolver, $this->urlFetcher, new RepMatcher());

        $result = $injector->process("User-agent: *\nDisallow: /checkout/\n");

        $this->assertStringContainsString('# Angeo AEO — Sitemaps',                          $result);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml',        $result);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap-products.xml', $result);
        $this->assertStringContainsString('# End Angeo AEO sitemaps',                        $result);
    }

    public function testInjectDoesNotDuplicateExistingSitemap(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_INJECT);
        $this->config->method('getEnabledBots')->willReturn($this->sampleBots());

        $sitemapResolver = $this->createMock(SitemapResolver::class);
        $sitemapResolver->method('resolve')->willReturn(['https://example.com/sitemap.xml']);
        $injector = new RobotsInjector($this->config, $this->parser, $sitemapResolver, $this->urlFetcher, new RepMatcher());

        $existing = "Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nDisallow: /\n";
        $result   = $injector->process($existing);

        $this->assertSame(1, substr_count($result, 'Sitemap: https://example.com/sitemap.xml'));
    }

    public function testInjectSitemapBlockIsIdempotent(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_INJECT);
        $this->config->method('getEnabledBots')->willReturn($this->sampleBots());

        $sitemapResolver = $this->createMock(SitemapResolver::class);
        $sitemapResolver->method('resolve')->willReturn(['https://example.com/sitemap.xml']);
        $injector = new RobotsInjector($this->config, $this->parser, $sitemapResolver, $this->urlFetcher, new RepMatcher());

        $first  = $injector->process("User-agent: *\nDisallow: /\n");
        $second = $injector->process($first);
        $this->assertSame($first, $second);
    }

    // ── REPLACE mode ────────────────────────────────────────────────────────

    public function testReplaceGeneratesFullRobotsTxt(): void
    {
        $this->configureReplaceMode($this->sampleBots());
        $this->config->method('getCustomContent')->willReturn('');

        $existing = "User-agent: *\nDisallow: /checkout/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringContainsString('# Angeo AEO — AI Crawler Rules', $result);
        $this->assertStringContainsString('User-agent: OAI-SearchBot',      $result);
        $this->assertStringContainsString('User-agent: *',                  $result);
    }

    public function testReplacePreservesCustomDisallows(): void
    {
        $this->configureReplaceMode($this->sampleBots());
        $this->config->method('getCustomContent')->willReturn('');

        $existing = "User-agent: *\nDisallow: /checkout/\nDisallow: /my-secret-path/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringContainsString('Disallow: /checkout/',       $result);
        $this->assertStringContainsString('Disallow: /my-secret-path/', $result);
    }

    public function testReplaceUsesCustomContentWhenProvided(): void
    {
        $this->configureReplaceMode($this->sampleBots());
        $this->config->method('getCustomContent')->willReturn("User-agent: *\nDisallow: /custom-only/\n");

        $result = $this->injector->process('IGNORED EXISTING CONTENT');

        $this->assertStringContainsString('Disallow: /custom-only/', $result);
        $this->assertStringNotContainsString('IGNORED EXISTING',     $result);
    }

    public function testReplaceUsesSafeDefaultsWhenEverythingEmpty(): void
    {
        $this->configureReplaceMode($this->sampleBots());
        $this->config->method('getCustomContent')->willReturn('');

        $result = $this->injector->process('');

        $this->assertStringContainsString('Disallow: /checkout/',                $result);
        $this->assertStringContainsString('Disallow: /customer/',                $result);
        $this->assertStringContainsString('Disallow: /catalog/product_compare/', $result);
    }

    // ── validate() ──────────────────────────────────────────────────────────

    public function testValidateReturnsPresentAndMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getEnabledBots')->willReturn($this->sampleBots());

        $content = "User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nDisallow: /\n";
        $result  = $this->injector->validate($content);

        $this->assertContains('OAI-SearchBot', $result['present']);
        $this->assertContains('PerplexityBot', $result['missing']);
    }

    public function testValidateAllPresentAfterInjection(): void
    {
        $this->configureInjectMode($this->sampleBots());

        $existing = "User-agent: *\nDisallow: /checkout/\n";
        $injected = $this->injector->process($existing);
        $result   = $this->injector->validate($injected);

        $this->assertEmpty($result['missing']);
        $this->assertCount(2, $result['present']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, BotDefinition>
     */
    private function sampleBots(): array
    {
        return [
            'oai_searchbot' => new BotDefinition(
                key:         'oai_searchbot',
                userAgent:   'OAI-SearchBot',
                label:       'OAI-SearchBot',
                description: 'ChatGPT live search',
            ),
            'perplexitybot' => new BotDefinition(
                key:         'perplexitybot',
                userAgent:   'PerplexityBot',
                label:       'PerplexityBot',
                description: 'Perplexity indexer',
            ),
        ];
    }

    /** @param array<string, BotDefinition> $bots */
    private function configureInjectMode(array $bots): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_INJECT);
        $this->config->method('getEnabledBots')->willReturn($bots);
    }

    /** @param array<string, BotDefinition> $bots */
    private function configureReplaceMode(array $bots): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_REPLACE);
        $this->config->method('getEnabledBots')->willReturn($bots);
    }

    // ── v2.0.0 audit-clean output ───────────────────────────────────────────

    public function testCrawlDelayIsSuppressedForGptBot(): void
    {
        $bot = new BotDefinition(
            key:         'gptbot',
            userAgent:   'GPTBot',
            label:       'GPTBot',
            description: 'OpenAI training',
            crawlDelay:  30.0,
        );
        $this->configureInjectMode(['gptbot' => $bot]);

        $output = $this->injector->process('');

        $this->assertStringContainsString('User-agent: GPTBot', $output);
        $this->assertStringNotContainsString('Crawl-delay:', $output,
            'GPTBot ignores Crawl-delay — directive must be suppressed (audit-clean)');
    }

    public function testCrawlDelayIsSuppressedForClaudeBot(): void
    {
        $bot = new BotDefinition(
            key:         'claudebot',
            userAgent:   'ClaudeBot',
            label:       'ClaudeBot',
            description: 'Anthropic crawler',
            crawlDelay:  10.0,
        );
        $this->configureInjectMode(['claudebot' => $bot]);
        $output = $this->injector->process('');

        $this->assertStringNotContainsString('Crawl-delay:', $output);
    }

    public function testCrawlDelayIsEmittedForBotsThatHonourIt(): void
    {
        $bot = new BotDefinition(
            key:         'perplexitybot',
            userAgent:   'PerplexityBot',
            label:       'PerplexityBot',
            description: 'Perplexity indexer',
            crawlDelay:  5.0,
        );
        $this->configureInjectMode(['perplexitybot' => $bot]);
        $output = $this->injector->process('');

        $this->assertStringContainsString('Crawl-delay: 5', $output);
    }

    public function testAllowSlashIsDroppedWhenDisallowSlashIsSet(): void
    {
        $bot = new BotDefinition(
            key:           'oai_searchbot',
            userAgent:     'OAI-SearchBot',
            label:         'OAI-SearchBot',
            description:   '',
            allowPaths:    ['/'],          // implicit default
            disallowPaths: ['/'],          // explicit block
        );
        $this->configureInjectMode(['oai_searchbot' => $bot]);
        $output = $this->injector->process('');

        // Block should contain Disallow: / but NOT Allow: /
        $block = $this->extractAngeoBlock($output);
        $this->assertStringContainsString('Disallow: /', $block);
        $this->assertStringNotContainsString("Allow: /\n", $block,
            'Allow: / + Disallow: / on the same agent is an audit syntax warning');
    }

    public function testSitemapHttpIsUpgradedToHttpsWhenStoreIsHttps(): void
    {
        $this->sitemapResolver = $this->createMock(SitemapResolver::class);
        $this->sitemapResolver->method('resolve')->willReturn([
            'http://example.test/sitemap.xml',
            'https://example.test/sitemap_index.xml',
        ]);
        $this->urlFetcher = $this->createMock(UrlFetcher::class);
        $this->urlFetcher->method('getBaseUrl')->willReturn('https://example.test');

        $this->injector = new RobotsInjector(
            $this->config,
            $this->parser,
            $this->sitemapResolver,
            $this->urlFetcher,
            new RepMatcher(),
        );

        $this->configureInjectMode($this->sampleBots());
        $output = $this->injector->process('');

        $this->assertStringContainsString('Sitemap: https://example.test/sitemap.xml', $output);
        $this->assertStringContainsString('Sitemap: https://example.test/sitemap_index.xml', $output);
        $this->assertStringNotContainsString('Sitemap: http://example.test/', $output);
    }

    public function testSitemapHttpIsPreservedWhenStoreIsHttp(): void
    {
        $this->sitemapResolver = $this->createMock(SitemapResolver::class);
        $this->sitemapResolver->method('resolve')->willReturn([
            'http://example.test/sitemap.xml',
        ]);
        $this->urlFetcher = $this->createMock(UrlFetcher::class);
        $this->urlFetcher->method('getBaseUrl')->willReturn('http://example.test');

        $this->injector = new RobotsInjector(
            $this->config,
            $this->parser,
            $this->sitemapResolver,
            $this->urlFetcher,
            new RepMatcher(),
        );

        $this->configureInjectMode($this->sampleBots());
        $output = $this->injector->process('');

        $this->assertStringContainsString('Sitemap: http://example.test/sitemap.xml', $output);
    }

    private function extractAngeoBlock(string $output): string
    {
        if (preg_match('/# Angeo AEO — AI Crawler Rules.*?# End Angeo AEO block/s', $output, $m)) {
            return $m[0];
        }
        return '';
    }
}
