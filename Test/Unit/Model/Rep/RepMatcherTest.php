<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Rep;

use Angeo\RobotsTxtAeo\Model\Parser\RobotsTxtParser;
use Angeo\RobotsTxtAeo\Model\Rep\AccessDecision;
use Angeo\RobotsTxtAeo\Model\Rep\RepMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Verifies RepMatcher against the normative semantics of RFC 9309 §2.2:
 * group selection, merged same-token groups, wildcard fallback,
 * longest-match-wins, Allow tie-break, * / $ patterns, case sensitivity.
 */
class RepMatcherTest extends TestCase
{
    private RobotsTxtParser $parser;
    private RepMatcher      $matcher;

    protected function setUp(): void
    {
        $this->parser  = new RobotsTxtParser();
        $this->matcher = new RepMatcher();
    }

    private function decide(string $robots, string $token, string $path): AccessDecision
    {
        return $this->matcher->isAllowed($this->parser->parse($robots), $token, $path);
    }

    // ── Rule precedence (RFC 9309 §2.2.2) ───────────────────────────────────

    public function testLongestMatchWinsRegardlessOfOrder(): void
    {
        // Disallow first in the file — order must not matter.
        $robots = "User-agent: Googlebot\nDisallow: /private/\nAllow: /private/public/\n";

        $d = $this->decide($robots, 'Googlebot', '/private/public/page');
        $this->assertTrue($d->allowed, 'longer Allow pattern must win');
        $this->assertSame('Allow: /private/public/', $d->matchedRule);

        $d = $this->decide($robots, 'Googlebot', '/private/secret');
        $this->assertFalse($d->allowed);
        $this->assertSame('Disallow: /private/', $d->matchedRule);
    }

    public function testAllowWinsOnEqualLengthTie(): void
    {
        $robots = "User-agent: Bot\nDisallow: /page\nAllow: /page\n";
        $this->assertTrue($this->decide($robots, 'Bot', '/page')->allowed);
    }

    public function testNoMatchingRuleMeansAllowed(): void
    {
        $robots = "User-agent: Bot\nDisallow: /private/\n";
        $this->assertTrue($this->decide($robots, 'Bot', '/public/page')->allowed);
    }

    public function testEmptyDisallowImposesNoRestriction(): void
    {
        $robots = "User-agent: Bot\nDisallow:\n";
        $this->assertTrue($this->decide($robots, 'Bot', '/anything')->allowed);
    }

    // ── Pattern syntax ──────────────────────────────────────────────────────

    public function testWildcardPattern(): void
    {
        $robots = "User-agent: Bot\nDisallow: /*.pdf$\n";
        $this->assertFalse($this->decide($robots, 'Bot', '/docs/manual.pdf')->allowed);
        $this->assertTrue($this->decide($robots, 'Bot', '/docs/manual.pdf.html')->allowed,
            '$ anchor must require end-of-URL');
    }

    public function testMidPatternWildcard(): void
    {
        $robots = "User-agent: Bot\nDisallow: /catalog/*/compare\n";
        $this->assertFalse($this->decide($robots, 'Bot', '/catalog/shoes/compare')->allowed);
        $this->assertTrue($this->decide($robots, 'Bot', '/catalog/shoes/view')->allowed);
    }

    public function testPathMatchingIsCaseSensitive(): void
    {
        $robots = "User-agent: Bot\nDisallow: /Example/\n";
        $this->assertFalse($this->decide($robots, 'Bot', '/Example/page')->allowed);
        $this->assertTrue($this->decide($robots, 'Bot', '/example/page')->allowed,
            'RFC 9309: URL path matching is case-sensitive');
    }

    public function testRegexMetacharactersInPathsAreLiteral(): void
    {
        $robots = "User-agent: Bot\nDisallow: /a+b(c)/\n";
        $this->assertFalse($this->decide($robots, 'Bot', '/a+b(c)/x')->allowed);
        $this->assertTrue($this->decide($robots, 'Bot', '/aab(c)/x')->allowed);
    }

    // ── Group selection (RFC 9309 §2.2.1) ───────────────────────────────────

    public function testSpecificGroupOverridesWildcardEntirely(): void
    {
        // Per RFC 9309 the * group is IGNORED when a named group matches.
        $robots = "User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /\n";

        $gpt = $this->decide($robots, 'GPTBot', '/product/1');
        $this->assertTrue($gpt->allowed);
        $this->assertSame(AccessDecision::SOURCE_EXACT, $gpt->source);

        $other = $this->decide($robots, 'RandomBot', '/product/1');
        $this->assertFalse($other->allowed);
        $this->assertSame(AccessDecision::SOURCE_WILDCARD, $other->source);
    }

    public function testGroupsWithSameTokenAreMerged(): void
    {
        // RFC 9309 Figure 2 — two ExampleBot groups behave as one merged group.
        $robots = "User-agent: ExampleBot\nDisallow: /foo\nDisallow: /bar\n\n"
                . "User-agent: ExampleBot\nDisallow: /baz\n";

        $this->assertFalse($this->decide($robots, 'ExampleBot', '/baz/page')->allowed);
        $this->assertFalse($this->decide($robots, 'ExampleBot', '/foo')->allowed);
    }

    public function testUserAgentTokenMatchIsCaseInsensitive(): void
    {
        $robots = "User-agent: gptbot\nDisallow: /\n";
        $this->assertFalse($this->decide($robots, 'GPTBot', '/')->allowed);
    }

    public function testNoGroupAtAllMeansAllowed(): void
    {
        $d = $this->decide("Sitemap: https://example.com/sitemap.xml\n", 'GPTBot', '/');
        $this->assertTrue($d->allowed);
        $this->assertSame(AccessDecision::SOURCE_DEFAULT, $d->source);
    }

    public function testSharedGroupAppliesToAllItsAgents(): void
    {
        $robots = "User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /checkout/\n";
        $this->assertFalse($this->decide($robots, 'ClaudeBot', '/checkout/cart')->allowed);
        $this->assertFalse($this->decide($robots, 'GPTBot', '/checkout/cart')->allowed);
        $this->assertTrue($this->decide($robots, 'ClaudeBot', '/product/1')->allowed);
    }

    // ── v4.0.0 hardening ────────────────────────────────────────────────────

    public function testPathologicalPatternDoesNotHangTheMatcher(): void
    {
        // robots.txt is untrusted input. "/a*a*a*…b" compiled naively is an
        // exponential matcher; the wildcard cap makes it a no-op instead.
        $parsed = $this->parser->parse("User-agent: *\nDisallow: /" . str_repeat('a*', 25) . "b\n");

        $started  = microtime(true);
        $decision = $this->matcher->isAllowed($parsed, 'AnyBot', '/' . str_repeat('a', 400));
        $elapsed  = microtime(true) - $started;

        $this->assertLessThan(1.0, $elapsed, 'Pattern matching must stay bounded.');
        $this->assertTrue($decision->allowed, 'A refused pattern must not block by accident.');
    }

    public function testRepeatedWildcardsBehaveLikeOne(): void
    {
        $parsed = $this->parser->parse("User-agent: *\nDisallow: /a**b\n");

        $this->assertFalse($this->matcher->isAllowed($parsed, 'AnyBot', '/axxb')->allowed);
        $this->assertTrue($this->matcher->isAllowed($parsed, 'AnyBot', '/axx')->allowed);
    }

    public function testOverlongPatternIsIgnored(): void
    {
        $pattern = '/' . str_repeat('a', RepMatcher::MAX_PATTERN_LENGTH + 10);
        $parsed  = $this->parser->parse("User-agent: *\nDisallow: {$pattern}\n");

        $this->assertTrue($this->matcher->isAllowed($parsed, 'AnyBot', $pattern)->allowed);
    }
}
