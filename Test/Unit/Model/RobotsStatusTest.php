<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\RobotsStatus;
use Angeo\RobotsTxtAeo\Model\SitemapResolver;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RobotsStatusTest extends TestCase
{
    private Config&MockObject                $config;
    private RobotsInjector&MockObject        $injector;
    private SitemapResolver&MockObject       $sitemapResolver;
    private UrlFetcher&MockObject            $urlFetcher;
    private StoreManagerInterface&MockObject $storeManager;
    private RobotsStatus                     $status;

    protected function setUp(): void
    {
        $this->config          = $this->createMock(Config::class);
        $this->injector        = $this->createMock(RobotsInjector::class);
        $this->sitemapResolver = $this->createMock(SitemapResolver::class);
        $this->urlFetcher      = $this->createMock(UrlFetcher::class);
        $this->storeManager    = $this->createMock(StoreManagerInterface::class);

        $this->status = new RobotsStatus(
            $this->config,
            $this->injector,
            $this->sitemapResolver,
            $this->urlFetcher,
            $this->storeManager,
        );
    }

    public function testGetEffectiveRobotsTxtDelegatesToInjector(): void
    {
        $this->injector->expects($this->once())
            ->method('preview')
            ->with('', 7)
            ->willReturn("User-agent: GPTBot\nAllow: /\n");

        $this->assertStringContainsString('GPTBot', $this->status->getEffectiveRobotsTxt(7));
    }

    public function testGetEnabledBotUserAgentsReturnsUaStrings(): void
    {
        $bots = [
            'gptbot'   => new BotDefinition('gptbot',   'GPTBot',   'GPTBot',   ''),
            'applebot' => new BotDefinition('applebot', 'Applebot', 'Applebot', ''),
        ];
        $this->config->method('getEnabledBots')->willReturn($bots);

        $this->assertSame(['GPTBot', 'Applebot'], $this->status->getEnabledBotUserAgents(1));
    }

    public function testGetEffectiveSitemapsUpgradesHttpOnHttpsStore(): void
    {
        $this->sitemapResolver->method('resolve')->willReturn([
            'http://example.test/sitemap.xml',
            'https://example.test/extra.xml',
        ]);
        $this->urlFetcher->method('getBaseUrl')->willReturn('https://example.test');

        $sitemaps = $this->status->getEffectiveSitemaps(1);
        $this->assertSame([
            'https://example.test/sitemap.xml',
            'https://example.test/extra.xml',
        ], $sitemaps);
    }

    public function testGetEffectiveSitemapsPreservesHttpOnHttpStore(): void
    {
        $this->sitemapResolver->method('resolve')->willReturn(['http://example.test/sitemap.xml']);
        $this->urlFetcher->method('getBaseUrl')->willReturn('http://example.test');

        $this->assertSame(['http://example.test/sitemap.xml'], $this->status->getEffectiveSitemaps(1));
    }

    public function testIsEnabledDelegatesToConfig(): void
    {
        $this->config->method('isEnabled')->with(5)->willReturn(true);
        $this->assertTrue($this->status->isEnabled(5));
    }

    public function testGetModeDelegatesToConfig(): void
    {
        $this->config->method('getMode')->with(5)->willReturn(Config::MODE_REPLACE);
        $this->assertSame(Config::MODE_REPLACE, $this->status->getMode(5));
    }

    public function testStoreIdResolvedFromStoreManagerWhenNull(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->config->expects($this->once())
            ->method('isEnabled')
            ->with(3)
            ->willReturn(true);

        $this->status->isEnabled(null);
    }
}
