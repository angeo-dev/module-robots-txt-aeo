<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Bot;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Bot\BotRegistry;
use Angeo\RobotsTxtAeo\Model\Cache\Type\RobotsTxtAeo as RobotsTxtAeoCache;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BotRegistryTest extends TestCase
{
    private RobotsTxtAeoCache&MockObject   $cache;
    private SerializerInterface&MockObject $serializer;
    private LoggerInterface&MockObject     $logger;

    protected function setUp(): void
    {
        $this->cache      = $this->createMock(RobotsTxtAeoCache::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->logger     = $this->createMock(LoggerInterface::class);
    }

    private function build(): BotRegistry
    {
        return new BotRegistry($this->cache, $this->serializer, $this->logger);
    }

    public function testBuiltinsContainsAllExpectedBots(): void
    {
        $builtins = $this->build()->builtins();
        $expected = [
            'oai_searchbot', 'gptbot', 'chatgpt_user', 'perplexitybot', 'perplexity_user',
            'google_extended', 'claudebot', 'anthropic_ai',
            'claude_user', 'applebot', 'cohere_ai', 'amazonbot', 'meta_external_agent',
        ];
        $this->assertCount(count($expected), $builtins);
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $builtins);
            $this->assertInstanceOf(BotDefinition::class, $builtins[$key]);
        }
    }

    public function testCriticalForAuditMetadataPropagates(): void
    {
        $builtins = $this->build()->builtins();
        $this->assertTrue($builtins['oai_searchbot']->criticalForAudit);
        $this->assertTrue($builtins['gptbot']->criticalForAudit);
        $this->assertTrue($builtins['google_extended']->criticalForAudit);
        $this->assertFalse($builtins['claudebot']->criticalForAudit);
        $this->assertFalse($builtins['applebot']->criticalForAudit);
    }

    public function testLowerTrafficBotsDefaultDisabled(): void
    {
        $builtins = $this->build()->builtins();
        $this->assertFalse($builtins['cohere_ai']->defaultEnabled);
        $this->assertFalse($builtins['amazonbot']->defaultEnabled);
        $this->assertFalse($builtins['meta_external_agent']->defaultEnabled);
        $this->assertTrue($builtins['gptbot']->defaultEnabled);
        $this->assertTrue($builtins['claude_user']->defaultEnabled);
    }

    public function testAllReturnsBuiltinsOnCacheMiss(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
        $this->serializer->method('serialize')->willReturn('{}');

        $all = $this->build()->all();
        $this->assertCount(count(BotRegistry::BUILTIN_BOTS), $all);
        $this->assertInstanceOf(BotDefinition::class, $all['gptbot']);
    }

    public function testAllRehydratesFromCacheOnHit(): void
    {
        $cachedPayload = '[serialized-data]';
        $this->cache->method('load')->willReturn($cachedPayload);
        $this->serializer->method('unserialize')->with($cachedPayload)->willReturn([
            'gptbot' => [
                'user_agent'  => 'GPTBot',
                'label'       => 'GPTBot',
                'description' => 'cached',
            ],
        ]);

        $all = $this->build()->all();
        $this->assertCount(1, $all);
        $this->assertSame('GPTBot', $all['gptbot']->userAgent);
        $this->assertSame('cached', $all['gptbot']->description);
    }

    public function testAllFallsBackToBuiltinsOnCorruptCache(): void
    {
        $this->cache->method('load')->willReturn('corrupt-data');
        $this->serializer->method('unserialize')->willThrowException(new \RuntimeException('corrupt'));
        $this->cache->method('save')->willReturn(true);
        $this->serializer->method('serialize')->willReturn('{}');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('corrupted'));

        $all = $this->build()->all();
        $this->assertCount(count(BotRegistry::BUILTIN_BOTS), $all);
    }

    public function testGetReturnsSingleBot(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
        $this->serializer->method('serialize')->willReturn('{}');

        $bot = $this->build()->get('claude_user');
        $this->assertInstanceOf(BotDefinition::class, $bot);
        $this->assertSame('Claude-User', $bot->userAgent);
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->cache->method('save')->willReturn(true);
        $this->serializer->method('serialize')->willReturn('{}');

        $this->assertNull($this->build()->get('nonexistent_bot'));
    }

    public function testInvalidateClearsCache(): void
    {
        $this->cache->expects($this->once())->method('remove')->with(BotRegistry::CACHE_KEY_PREFIX);
        $this->build()->invalidate();
    }
}
