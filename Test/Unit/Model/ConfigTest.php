<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Bot\BotRegistry;
use Angeo\RobotsTxtAeo\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private BotRegistry&MockObject          $botRegistry;
    private Config                          $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->botRegistry = $this->createMock(BotRegistry::class);
        $this->config      = new Config($this->scopeConfig, $this->botRegistry);
    }

    // ── General getters ─────────────────────────────────────────────────────

    public function testIsEnabledDefaultsTrueWhenUnset(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledReturnsConfiguredValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('angeo_robots_txt_aeo/general/enabled', ScopeInterface::SCOPE_STORE, 5)
            ->willReturn('0');

        $this->assertFalse($this->config->isEnabled(5));
    }

    public function testGetModeFallsBackToInject(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);
        $this->assertSame(Config::MODE_INJECT, $this->config->getMode());
    }

    // ── getEnabledBots ──────────────────────────────────────────────────────

    public function testGetEnabledBotsRespectsConfig(): void
    {
        $gptbot   = $this->makeBot('gptbot',   'GPTBot');
        $claude   = $this->makeBot('claudebot','ClaudeBot');

        $this->botRegistry->method('all')->willReturn([
            'gptbot'    => $gptbot,
            'claudebot' => $claude,
        ]);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path) {
                if ($path === 'angeo_robots_txt_aeo/bots/gptbot')    return '1';
                if ($path === 'angeo_robots_txt_aeo/bots/claudebot') return '0';
                return null;
            }
        );

        $enabled = $this->config->getEnabledBots();

        $this->assertArrayHasKey('gptbot', $enabled);
        $this->assertArrayNotHasKey('claudebot', $enabled);
    }

    public function testGetEnabledBotsUsesDefaultEnabledForUnconfiguredBot(): void
    {
        // Remote-overlay bot without admin config row yet
        $bytespider = $this->makeBot('bytespider', 'Bytespider', defaultEnabled: false);
        $this->botRegistry->method('all')->willReturn(['bytespider' => $bytespider]);

        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertArrayNotHasKey('bytespider', $this->config->getEnabledBots());
    }

    public function testGetEnabledBotsAppliesPathOverrides(): void
    {
        $gptbot = $this->makeBot('gptbot', 'GPTBot');
        $this->botRegistry->method('all')->willReturn(['gptbot' => $gptbot]);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path) {
                return match ($path) {
                    'angeo_robots_txt_aeo/bots/gptbot'                       => '1',
                    'angeo_robots_txt_aeo/bot_overrides/gptbot/allow'        => "/catalog/\n/static/",
                    'angeo_robots_txt_aeo/bot_overrides/gptbot/disallow'     => '/admin/, /customer/',
                    'angeo_robots_txt_aeo/bot_overrides/gptbot/crawl_delay'  => '15',
                    default => null,
                };
            }
        );

        $enabled = $this->config->getEnabledBots();
        $resolved = $enabled['gptbot'];

        $this->assertSame(['/catalog/', '/static/'],   $resolved->allowPaths);
        $this->assertSame(['/admin/', '/customer/'],   $resolved->disallowPaths);
        $this->assertSame(15.0, $resolved->crawlDelay);
    }

    public function testGetEnabledBotsFallsBackToDefaultsWhenOverridesEmpty(): void
    {
        $gptbot = $this->makeBot('gptbot', 'GPTBot', allowPaths: ['/'], crawlDelay: null);
        $this->botRegistry->method('all')->willReturn(['gptbot' => $gptbot]);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => $path === 'angeo_robots_txt_aeo/bots/gptbot' ? '1' : null
        );

        $enabled  = $this->config->getEnabledBots();
        $resolved = $enabled['gptbot'];

        $this->assertSame(['/'], $resolved->allowPaths);
        $this->assertNull($resolved->crawlDelay);
    }

    public function testNonNumericCrawlDelayOverrideIsIgnored(): void
    {
        $gptbot = $this->makeBot('gptbot', 'GPTBot');
        $this->botRegistry->method('all')->willReturn(['gptbot' => $gptbot]);

        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => match ($path) {
                'angeo_robots_txt_aeo/bots/gptbot'                      => '1',
                'angeo_robots_txt_aeo/bot_overrides/gptbot/crawl_delay' => 'fast',
                default                                                 => null,
            }
        );

        $resolved = $this->config->getEnabledBots()['gptbot'];
        $this->assertNull($resolved->crawlDelay);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeBot(
        string $key,
        string $ua,
        array  $allowPaths     = ['/'],
        array  $disallowPaths  = [],
        ?float $crawlDelay     = null,
        bool   $defaultEnabled = true,
    ): BotDefinition {
        return new BotDefinition(
            key:            $key,
            userAgent:      $ua,
            label:          $ua,
            description:    'test',
            allowPaths:     $allowPaths,
            disallowPaths:  $disallowPaths,
            crawlDelay:     $crawlDelay,
            defaultEnabled: $defaultEnabled,
        );
    }
}
