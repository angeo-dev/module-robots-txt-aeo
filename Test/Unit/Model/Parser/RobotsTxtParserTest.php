<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Parser;

use Angeo\RobotsTxtAeo\Model\Parser\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtParserTest extends TestCase
{
    private RobotsTxtParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RobotsTxtParser();
    }

    // ── Basics ──────────────────────────────────────────────────────────────

    public function testParsesEmptyContent(): void
    {
        $parsed = $this->parser->parse('');
        $this->assertEmpty($parsed->groups);
        $this->assertEmpty($parsed->sitemaps);
    }

    public function testParsesSingleSimpleGroup(): void
    {
        $content = "User-agent: *\nDisallow: /admin/\nAllow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(1, $parsed->groups);
        $group = $parsed->groups[0];
        $this->assertSame(['*'], $group->userAgents);
        $this->assertSame(['/admin/'], $group->disallow);
        $this->assertSame(['/'], $group->allow);
        $this->assertNull($group->crawlDelay);
    }

    public function testParsesCrawlDelay(): void
    {
        $content = "User-agent: GPTBot\nCrawl-delay: 30\nAllow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(1, $parsed->groups);
        $this->assertSame(30.0, $parsed->groups[0]->crawlDelay);
    }

    public function testParsesMultipleAllowDisallowLinesPerGroup(): void
    {
        // The regex-based version this replaces could only see one Allow/Disallow
        $content = <<<TXT
User-agent: GPTBot
Allow: /catalog/
Allow: /static/
Disallow: /admin/
Disallow: /customer/
Crawl-delay: 10
TXT;

        $parsed = $this->parser->parse($content);
        $this->assertCount(1, $parsed->groups);
        $g = $parsed->groups[0];
        $this->assertSame(['/catalog/', '/static/'], $g->allow);
        $this->assertSame(['/admin/', '/customer/'], $g->disallow);
        $this->assertSame(10.0, $g->crawlDelay);
    }

    public function testStacksMultipleUserAgentsBeforeDirectives(): void
    {
        // RFC 9309: consecutive User-agent lines share the same directive set
        $content = <<<TXT
User-agent: GPTBot
User-agent: ChatGPT-User
Allow: /
Disallow: /admin/
TXT;

        $parsed = $this->parser->parse($content);
        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['GPTBot', 'ChatGPT-User'], $parsed->groups[0]->userAgents);
    }

    public function testNewUserAgentStartsNewGroup(): void
    {
        $content = <<<TXT
User-agent: GPTBot
Allow: /
Disallow: /admin/

User-agent: ClaudeBot
Allow: /
TXT;

        $parsed = $this->parser->parse($content);
        $this->assertCount(2, $parsed->groups);
        $this->assertSame(['GPTBot'],    $parsed->groups[0]->userAgents);
        $this->assertSame(['ClaudeBot'], $parsed->groups[1]->userAgents);
    }

    // ── Edge cases ──────────────────────────────────────────────────────────

    public function testBlankLineInsideGroupDoesNotCloseIt(): void
    {
        // Per RFC 9309, blank lines are NOT separators — only User-agent lines or EOF
        $content = <<<TXT
User-agent: GPTBot
Allow: /

Disallow: /admin/
TXT;

        $parsed = $this->parser->parse($content);
        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['/'],       $parsed->groups[0]->allow);
        $this->assertSame(['/admin/'], $parsed->groups[0]->disallow);
    }

    public function testInlineCommentsStripped(): void
    {
        $content = "User-agent: GPTBot # OpenAI\nDisallow: /admin/ # secret\n";
        $parsed  = $this->parser->parse($content);

        $this->assertSame(['GPTBot'],    $parsed->groups[0]->userAgents);
        $this->assertSame(['/admin/'],   $parsed->groups[0]->disallow);
    }

    public function testCommentLinesPreserveTopLevel(): void
    {
        $content = "# Comment 1\n# Comment 2\nUser-agent: *\nDisallow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(2, $parsed->topComments);
        $this->assertSame('# Comment 1', $parsed->topComments[0]);
        $this->assertCount(1, $parsed->groups);
    }

    public function testHandlesCRLFLineEndings(): void
    {
        $content = "User-agent: *\r\nDisallow: /admin/\r\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['/admin/'], $parsed->groups[0]->disallow);
    }

    public function testStripsUtf8Bom(): void
    {
        $content = "\xEF\xBB\xBFUser-agent: *\nDisallow: /admin/\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['*'], $parsed->groups[0]->userAgents);
    }

    public function testMalformedLineIsSkipped(): void
    {
        $content = "User-agent: GPTBot\nthis is not a directive\nDisallow: /admin/\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['/admin/'], $parsed->groups[0]->disallow);
    }

    public function testDirectiveBeforeUserAgentIsIgnored(): void
    {
        $content = "Allow: /\nDisallow: /admin/\nUser-agent: *\nDisallow: /forbidden/\n";
        $parsed  = $this->parser->parse($content);

        // Orphan Allow/Disallow without UA — skipped
        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['/forbidden/'], $parsed->groups[0]->disallow);
    }

    public function testCaseInsensitiveDirectiveNames(): void
    {
        $content = "USER-AGENT: GPTBot\nDISALLOW: /admin/\nallow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertCount(1, $parsed->groups);
        $this->assertSame(['GPTBot'],   $parsed->groups[0]->userAgents);
        $this->assertSame(['/admin/'],  $parsed->groups[0]->disallow);
        $this->assertSame(['/'],        $parsed->groups[0]->allow);
    }

    public function testNonNumericCrawlDelayIgnored(): void
    {
        $content = "User-agent: GPTBot\nCrawl-delay: fast\nAllow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertNull($parsed->groups[0]->crawlDelay);
    }

    // ── Sitemap directive ───────────────────────────────────────────────────

    public function testSitemapDirectiveCollected(): void
    {
        $content = "Sitemap: https://example.com/sitemap.xml\nUser-agent: *\nDisallow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertSame(['https://example.com/sitemap.xml'], $parsed->sitemaps);
    }

    public function testMultipleSitemapsCollected(): void
    {
        $content = <<<TXT
Sitemap: https://example.com/sitemap-1.xml
Sitemap: https://example.com/sitemap-2.xml
User-agent: *
Disallow: /
TXT;
        $parsed = $this->parser->parse($content);

        $this->assertCount(2, $parsed->sitemaps);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function testHasUserAgentCaseInsensitive(): void
    {
        $content = "User-agent: GPTBot\nAllow: /\n";
        $parsed  = $this->parser->parse($content);

        $this->assertTrue($this->parser->hasUserAgent($parsed, 'GPTBot'));
        $this->assertTrue($this->parser->hasUserAgent($parsed, 'gptbot'));
        $this->assertTrue($this->parser->hasUserAgent($parsed, 'GPTBOT'));
        $this->assertFalse($this->parser->hasUserAgent($parsed, 'ClaudeBot'));
    }

    public function testGetWildcardDisallowsReturnsUniqueList(): void
    {
        $content = <<<TXT
User-agent: *
Disallow: /admin/
Disallow: /customer/
Disallow: /admin/
Allow: /

User-agent: GPTBot
Disallow: /not-this-one/
TXT;

        $parsed = $this->parser->parse($content);
        $paths  = $this->parser->getWildcardDisallows($parsed);

        $this->assertSame(['/admin/', '/customer/'], $paths);
        $this->assertNotContains('/not-this-one/', $paths);
    }

    public function testGetWildcardDisallowsExcludesEmptyAndRoot(): void
    {
        $content = "User-agent: *\nDisallow: /\nDisallow:\nDisallow: /admin/\n";
        $parsed  = $this->parser->parse($content);

        $paths = $this->parser->getWildcardDisallows($parsed);
        $this->assertSame(['/admin/'], $paths);
    }

    // ── Complex real-world fixture ──────────────────────────────────────────

    public function testRealWorldRobotsTxt(): void
    {
        $content = <<<TXT
# https://example-store.com/robots.txt
# Last updated: 2026-04-10

User-agent: *
Disallow: /checkout/
Disallow: /customer/
Disallow: /catalogsearch/
Allow: /

# AI crawlers — managed by Angeo_RobotsTxtAeo
User-agent: GPTBot
User-agent: ChatGPT-User
Allow: /
Crawl-delay: 5

User-agent: ClaudeBot
Allow: /
Disallow: /admin/

Sitemap: https://example-store.com/sitemap.xml
Sitemap: https://example-store.com/sitemap-products.xml
TXT;

        $parsed = $this->parser->parse($content);

        $this->assertCount(3, $parsed->groups, 'wildcard + GPTBot/ChatGPT-User + ClaudeBot');
        $this->assertCount(2, $parsed->sitemaps);

        $this->assertSame(['*'], $parsed->groups[0]->userAgents);
        $this->assertSame(['GPTBot', 'ChatGPT-User'], $parsed->groups[1]->userAgents);
        $this->assertSame(5.0, $parsed->groups[1]->crawlDelay);
        $this->assertSame(['ClaudeBot'], $parsed->groups[2]->userAgents);
        $this->assertSame(['/admin/'], $parsed->groups[2]->disallow);
    }
}
