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

    public function testIgnoresCrawlDelayForKnownBots(): void
    {
        foreach (['GPTBot', 'ClaudeBot', 'Google-Extended'] as $ua) {
            $def = BotDefinition::fromArray(strtolower($ua), ['user_agent' => $ua]);
            $this->assertTrue($def->ignoresCrawlDelay(), $ua . ' should be in IGNORES_CRAWL_DELAY');
        }
    }

    public function testHonoursCrawlDelayForOthers(): void
    {
        $def = BotDefinition::fromArray('applebot', ['user_agent' => 'Applebot']);
        $this->assertFalse($def->ignoresCrawlDelay());
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
