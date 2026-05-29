<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model;

use Angeo\RobotsTxtAeo\Model\FetchResult;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UrlFetcherTest extends TestCase
{
    private ScopeConfigInterface&MockObject  $scopeConfig;
    private StoreManagerInterface&MockObject $storeManager;
    private CurlFactory&MockObject           $curlFactory;
    private LoggerInterface&MockObject       $logger;
    private UrlFetcher                       $fetcher;

    protected function setUp(): void
    {
        $this->scopeConfig  = $this->createMock(ScopeConfigInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->curlFactory  = $this->createMock(CurlFactory::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        $this->fetcher = new UrlFetcher(
            $this->scopeConfig,
            $this->storeManager,
            $this->curlFactory,
            $this->logger,
        );
    }

    // ── getBaseUrl / getRobotsUrl ──────────────────────────────────────────

    public function testGetBaseUrlFromStoreManager(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB, true)
            ->willReturn('https://example.com/');

        $this->storeManager->method('getStore')->willReturn($store);

        $this->assertSame('https://example.com', $this->fetcher->getBaseUrl());
    }

    public function testGetBaseUrlStripsTrailingSlash(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $this->storeManager->method('getStore')->willReturn($store);

        $this->assertSame('https://example.com', $this->fetcher->getBaseUrl());
    }

    public function testGetBaseUrlFallbackToScopeConfig(): void
    {
        $this->storeManager->method('getStore')->willThrowException(new \RuntimeException('no store'));

        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['web/secure/base_url',   ScopeInterface::SCOPE_STORE, null, 'https://example.com/'],
                ['web/unsecure/base_url', ScopeInterface::SCOPE_STORE, null, 'http://example.com/'],
            ]);

        $this->assertSame('https://example.com', $this->fetcher->getBaseUrl());
    }

    public function testGetRobotsUrlAppendsPath(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        $this->storeManager->method('getStore')->willReturn($store);

        $this->assertSame('https://example.com/robots.txt', $this->fetcher->getRobotsUrl());
    }

    // ── fetch() success cases ──────────────────────────────────────────────

    public function testFetchSuccess(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('get')->with('https://example.com/robots.txt');
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn("User-agent: *\nAllow: /\n");

        $this->curlFactory->method('create')->willReturn($curl);

        $result = $this->fetcher->fetch('https://example.com/robots.txt');

        $this->assertInstanceOf(FetchResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(200, $result->statusCode);
        $this->assertStringContainsString('User-agent: *', $result->body);
        $this->assertSame('', $result->error);
    }

    public function testFetchAccepts201(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(201);
        $curl->method('getBody')->willReturn('created');

        $this->curlFactory->method('create')->willReturn($curl);

        $result = $this->fetcher->fetch('https://example.com/robots.txt');
        $this->assertTrue($result->isSuccess());
    }

    // ── fetch() failure cases ──────────────────────────────────────────────

    public function testFetchInvalidUrl(): void
    {
        $result = $this->fetcher->fetch('not-a-url');

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Invalid URL', $result->error);
    }

    public function testFetch404DoesNotRetry(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(404);
        $curl->method('getBody')->willReturn('not found');

        // Only ONE create() call because 4xx skips retries
        $this->curlFactory->expects($this->once())->method('create')->willReturn($curl);

        $result = $this->fetcher->fetch('https://example.com/robots.txt', 5, 3);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(404, $result->statusCode);
    }

    public function testFetch500TriggersRetries(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->method('getStatus')->willReturn(500);
        $curl->method('getBody')->willReturn('server error');

        // 1 + 2 retries = 3 calls
        $this->curlFactory->expects($this->exactly(3))->method('create')->willReturn($curl);

        $result = $this->fetcher->fetch('https://example.com/robots.txt', 5, 2);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('HTTP 500', $result->error);
    }

    public function testFetchNetworkExceptionIsRetried(): void
    {
        $callCount = 0;
        $this->curlFactory->method('create')->willReturnCallback(function () use (&$callCount) {
            $callCount++;
            $curl = $this->createMock(Curl::class);
            if ($callCount < 3) {
                $curl->method('get')->willThrowException(new \RuntimeException('Connection refused'));
                $curl->method('getStatus')->willReturn(0);
                $curl->method('getBody')->willReturn('');
            } else {
                $curl->method('getStatus')->willReturn(200);
                $curl->method('getBody')->willReturn('ok');
            }
            return $curl;
        });

        $result = $this->fetcher->fetch('https://example.com/robots.txt', 5, 3);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(3, $callCount, '3rd attempt succeeded after 2 failures');
    }

    public function testFetchConfiguresTlsByDefault(): void
    {
        $capturedOptions = null;
        $curl = $this->createMock(Curl::class);
        $curl->method('setOptions')->willReturnCallback(function ($opts) use (&$capturedOptions) {
            $capturedOptions = $opts;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('');

        $this->curlFactory->method('create')->willReturn($curl);

        $this->fetcher->fetch('https://example.com/robots.txt');

        $this->assertTrue($capturedOptions[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $capturedOptions[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testFetchInsecureDisablesTls(): void
    {
        $capturedOptions = null;
        $curl = $this->createMock(Curl::class);
        $curl->method('setOptions')->willReturnCallback(function ($opts) use (&$capturedOptions) {
            $capturedOptions = $opts;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('');

        $this->curlFactory->method('create')->willReturn($curl);

        $this->fetcher->fetch('https://example.com/robots.txt', 5, 0, true);

        $this->assertFalse($capturedOptions[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $capturedOptions[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testFetchSetsTimeout(): void
    {
        $capturedOptions = null;
        $curl = $this->createMock(Curl::class);
        $curl->method('setOptions')->willReturnCallback(function ($opts) use (&$capturedOptions) {
            $capturedOptions = $opts;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('');

        $this->curlFactory->method('create')->willReturn($curl);

        $this->fetcher->fetch('https://example.com/robots.txt', 25);

        $this->assertSame(25, $capturedOptions[CURLOPT_TIMEOUT]);
        $this->assertSame(5,  $capturedOptions[CURLOPT_CONNECTTIMEOUT]);
    }
}
