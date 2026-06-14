<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Bot;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use PHPUnit\Framework\TestCase;

class BotDefinitionTest extends TestCase
{
    public function testFromArrayWithMinimalData(): void
    {
        $def = BotDefinition::fromArray('gptbot', [
            'user_agent'  => 'GPTBot',
            'description' => 'OpenAI crawler',
        ]);

        $this->assertSame('gptbot',         $def->key);
        $this->assertSame('GPTBot',         $def->userAgent);
        $this->assertSame('OpenAI crawler', $def->description);
        $this->assertSame(['/'],            $def->allowPaths, 'defaults to allow root');
        $this->assertSame([],               $def->disallowPaths);
        $this->assertNull($def->crawlDelay);
        $this->assertTrue($def->defaultEnabled);
    }

    public function testFromArrayWithFullData(): void
    {
        $def = BotDefinition::fromArray('gptbot', [
            'user_agent'          => 'GPTBot',
            'label'               => 'GPTBot Label',
            'description'         => 'desc',
            'respects_robots_txt' => false,
            'allow_paths'         => ['/catalog/', '/static/'],
            'disallow_paths'      => ['/admin/'],
            'crawl_delay'         => 30,
            'default_enabled'     => false,
            'source'              => 'remote',
        ]);

        $this->assertFalse($def->respectsRobotsTxt);
        $this->assertSame(['/catalog/', '/static/'], $def->allowPaths);
        $this->assertSame(['/admin/'], $def->disallowPaths);
        $this->assertSame(30.0, $def->crawlDelay);
        $this->assertFalse($def->defaultEnabled);
        $this->assertSame('remote', $def->source);
    }

    public function testFromArrayAcceptsStringPathLists(): void
    {
        $def = BotDefinition::fromArray('gptbot', [
            'user_agent'  => 'GPTBot',
            'allow_paths' => '/catalog/, /static/',
        ]);

        $this->assertSame(['/catalog/', '/static/'], $def->allowPaths);
    }

    public function testFromArrayIgnoresNonNumericCrawlDelay(): void
    {
        $def = BotDefinition::fromArray('gptbot', [
            'user_agent'  => 'GPTBot',
            'crawl_delay' => 'fast',
        ]);

        $this->assertNull($def->crawlDelay);
    }

    public function testToArrayRoundtrip(): void
    {
        $original = BotDefinition::fromArray('gptbot', [
            'user_agent'      => 'GPTBot',
            'label'           => 'GPTBot',
            'description'     => 'desc',
            'allow_paths'     => ['/catalog/'],
            'disallow_paths'  => ['/admin/'],
            'crawl_delay'     => 10,
            'default_enabled' => true,
            'source'          => 'builtin',
        ]);

        $roundtrip = BotDefinition::fromArray('gptbot', $original->toArray());

        $this->assertEquals($original, $roundtrip);
    }

    public function testFromArrayStripsVersionedUserAgent(): void
    {
        $def = BotDefinition::fromArray('gptbot', [
            'user_agent' => 'GPTBot/1.2',
        ]);

        $this->assertSame('GPTBot', $def->userAgent,
            'Versioned UA suffix is stripped — robots.txt match is exact-string');
    }

    public function testCrawlDelaySuppressedOnDocumentedNonSupport(): void
    {
        // v3 tri-state: supports_crawl_delay=false → suppress.
        $def = BotDefinition::fromArray('gptbot', [
            'user_agent'           => 'GPTBot',
            'supports_crawl_delay' => false,
        ]);
        $this->assertTrue($def->ignoresCrawlDelay());
    }

    public function testCrawlDelaySuppressedWhenSupportUnknown(): void
    {
        // v3 tri-state: null (unknown) is treated conservatively as suppress.
        $def = BotDefinition::fromArray('applebot', ['user_agent' => 'Applebot']);
        $this->assertNull($def->supportsCrawlDelay);
        $this->assertTrue($def->ignoresCrawlDelay());
    }

    public function testCrawlDelayEmittedOnDocumentedSupport(): void
    {
        // Anthropic documents Crawl-delay support (2026-02 docs update).
        $def = BotDefinition::fromArray('claudebot', [
            'user_agent'           => 'ClaudeBot',
            'supports_crawl_delay' => true,
        ]);
        $this->assertFalse($def->ignoresCrawlDelay());
    }

    public function testUnicodeDashesAreNormalisedInUserAgent(): void
    {
        // Vendor docs have been observed mixing U+2011 into agent names.
        $def = BotDefinition::fromArray('perplexity_user', [
            'user_agent' => "Perplexity\u{2011}User",
        ]);
        $this->assertSame('Perplexity-User', $def->userAgent);
    }

    public function testMetadataRoundTripsThroughToArrayFromArray(): void
    {
        $original = BotDefinition::fromArray('gptbot', [
            'user_agent'           => 'GPTBot',
            'category'             => BotDefinition::CATEGORY_TRAINING,
            'token_only'           => false,
            'deprecated'           => false,
            'supports_crawl_delay' => false,
            'ip_ranges_url'        => 'https://openai.com/gptbot.json',
            'docs_url'             => 'https://developers.openai.com/api/docs/bots',
        ]);

        $rehydrated = BotDefinition::fromArray('gptbot', $original->toArray());

        $this->assertSame($original->toArray(), $rehydrated->toArray());
        $this->assertSame(BotDefinition::CATEGORY_TRAINING, $rehydrated->category);
        $this->assertFalse($rehydrated->supportsCrawlDelay);
        $this->assertSame('https://openai.com/gptbot.json', $rehydrated->ipRangesUrl);
    }

    public function testCriticalForAuditFlag(): void
    {
        $critical = BotDefinition::fromArray('gptbot', [
            'user_agent'         => 'GPTBot',
            'critical_for_audit' => true,
        ]);
        $this->assertTrue($critical->criticalForAudit);

        $regular = BotDefinition::fromArray('applebot', [
            'user_agent' => 'Applebot',
        ]);
        $this->assertFalse($regular->criticalForAudit);
    }
}
