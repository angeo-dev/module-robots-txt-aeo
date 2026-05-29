<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model;

use Angeo\RobotsTxtAeo\Model\Sitemap\MagentoSitemapProviderInterface;
use Angeo\RobotsTxtAeo\Model\Sitemap\NullMagentoSitemapProvider;
use Angeo\RobotsTxtAeo\Model\SitemapResolver;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SitemapResolverTest extends TestCase
{
    private ScopeConfigInterface&MockObject             $scopeConfig;
    private StoreManagerInterface&MockObject            $storeManager;
    private UrlFetcher&MockObject                       $urlFetcher;
    private MagentoSitemapProviderInterface&MockObject  $sitemapProvider;
    private SitemapResolver                             $resolver;

    protected function setUp(): void
    {
        $this->scopeConfig     = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager    = $this->createMock(StoreManagerInterface::class);
        $this->urlFetcher      = $this->createMock(UrlFetcher::class);
        $this->sitemapProvider = $this->createMock(MagentoSitemapProviderInterface::class);

        $this->resolver = new SitemapResolver(
            $this->scopeConfig,
            $this->storeManager,
            $this->urlFetcher,
            $this->sitemapProvider,
        );
    }

    public function testModeNoneReturnsEmpty(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('angeo_robots_txt_aeo/sitemap/mode', ScopeInterface::SCOPE_STORE, null)
            ->willReturn(SitemapResolver::SOURCE_NONE);

        $this->sitemapProvider->expects($this->never())->method('getSitemapUrls');

        $this->assertSame([], $this->resolver->resolve());
    }

    public function testAutoUsesMagentoSitemapWhenAvailable(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => $path === 'angeo_robots_txt_aeo/sitemap/mode' ? SitemapResolver::SOURCE_AUTO : null
        );

        $this->sitemapProvider->method('getSitemapUrls')->willReturn([
            'https://example.com/sitemap-products.xml',
            'https://example.com/sitemap-categories.xml',
        ]);

        $result = $this->resolver->resolve();

        $this->assertSame(
            ['https://example.com/sitemap-products.xml', 'https://example.com/sitemap-categories.xml'],
            $result
        );
    }

    public function testAutoFallsBackToBaseUrlConvention(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => $path === 'angeo_robots_txt_aeo/sitemap/mode' ? SitemapResolver::SOURCE_AUTO : null
        );

        $this->sitemapProvider->method('getSitemapUrls')->willReturn([]);
        $this->urlFetcher->method('getBaseUrl')->willReturn('https://example.com');

        $this->assertSame(['https://example.com/sitemap.xml'], $this->resolver->resolve());
    }

    public function testCustomModeReturnsParsedUrls(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => match ($path) {
                'angeo_robots_txt_aeo/sitemap/mode'        => SitemapResolver::SOURCE_CUSTOM,
                'angeo_robots_txt_aeo/sitemap/custom_urls' => "https://example.com/sitemap-1.xml\nhttps://example.com/sitemap-2.xml\n  \nnot-a-url",
                default                                    => null,
            }
        );

        $this->sitemapProvider->expects($this->never())->method('getSitemapUrls');

        $result = $this->resolver->resolve();

        $this->assertSame(
            ['https://example.com/sitemap-1.xml', 'https://example.com/sitemap-2.xml'],
            $result
        );
    }

    public function testCustomModeDedupes(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => match ($path) {
                'angeo_robots_txt_aeo/sitemap/mode'        => SitemapResolver::SOURCE_CUSTOM,
                'angeo_robots_txt_aeo/sitemap/custom_urls' => "https://example.com/sitemap.xml\nhttps://example.com/sitemap.xml",
                default                                    => null,
            }
        );

        $this->assertCount(1, $this->resolver->resolve());
    }

    public function testStoreScopeIsPropagated(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $store) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scope);
                $this->assertSame(2, $store);
                return $path === 'angeo_robots_txt_aeo/sitemap/mode' ? SitemapResolver::SOURCE_AUTO : null;
            }
        );

        $this->sitemapProvider
            ->method('getSitemapUrls')
            ->with(2)
            ->willReturn(['https://store2.example.com/sitemap.xml']);

        $result = $this->resolver->resolve(2);
        $this->assertSame(['https://store2.example.com/sitemap.xml'], $result);
    }

    public function testNullProviderDoesNotBreakResolution(): void
    {
        // Use the real NullMagentoSitemapProvider to verify wiring works
        $resolver = new SitemapResolver(
            $this->scopeConfig,
            $this->storeManager,
            $this->urlFetcher,
            new NullMagentoSitemapProvider(),
        );

        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn(string $path) => $path === 'angeo_robots_txt_aeo/sitemap/mode' ? SitemapResolver::SOURCE_AUTO : null
        );

        $this->urlFetcher->method('getBaseUrl')->willReturn('https://example.com');

        $this->assertSame(['https://example.com/sitemap.xml'], $resolver->resolve());
    }
}
