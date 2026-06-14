<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Adminhtml;

use Angeo\RobotsTxtAeo\Model\Adminhtml\StoreIdResolver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StoreIdResolverTest extends TestCase
{
    private StoreRepositoryInterface&MockObject $storeRepository;
    private StoreManagerInterface&MockObject    $storeManager;
    private RequestInterface&MockObject         $request;
    private StoreIdResolver                     $resolver;

    protected function setUp(): void
    {
        $this->storeRepository = $this->createMock(StoreRepositoryInterface::class);
        $this->storeManager    = $this->createMock(StoreManagerInterface::class);
        $this->request         = $this->createMock(RequestInterface::class);

        $this->resolver = new StoreIdResolver($this->storeRepository, $this->storeManager);
    }

    public function testResolvesValidExplicitStoreId(): void
    {
        $this->request->method('getParam')->with('store')->willReturn('3');
        $this->storeRepository->expects($this->once())
            ->method('getById')->with(3)
            ->willReturn($this->createMock(StoreInterface::class));

        $this->assertSame(3, $this->resolver->resolve($this->request));
    }

    public function testThrowsOnNonNumericStoreParam(): void
    {
        $this->request->method('getParam')->with('store')->willReturn('3; DROP TABLE');

        $this->expectException(LocalizedException::class);
        $this->resolver->resolve($this->request);
    }

    public function testThrowsOnNegativeStoreParam(): void
    {
        $this->request->method('getParam')->with('store')->willReturn('-1');

        $this->expectException(LocalizedException::class);
        $this->resolver->resolve($this->request);
    }

    public function testThrowsOnNonExistentStore(): void
    {
        $this->request->method('getParam')->with('store')->willReturn('999');
        $this->storeRepository->method('getById')->with(999)
            ->willThrowException(NoSuchEntityException::singleField('store_id', 999));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/999/');
        $this->resolver->resolve($this->request);
    }

    public function testFallsBackToDefaultStoreView(): void
    {
        $this->request->method('getParam')->with('store')->willReturn(null);

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $this->storeManager->method('getDefaultStoreView')->willReturn($store);

        $this->assertSame(1, $this->resolver->resolve($this->request));
    }

    public function testReturnsNullWhenDefaultStoreUnavailable(): void
    {
        $this->request->method('getParam')->with('store')->willReturn('');
        $this->storeManager->method('getDefaultStoreView')
            ->willThrowException(new \RuntimeException('no store'));

        $this->assertNull($this->resolver->resolve($this->request));
    }
}
