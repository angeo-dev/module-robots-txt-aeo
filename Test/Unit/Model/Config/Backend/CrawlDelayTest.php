<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Config\Backend;

use Angeo\RobotsTxtAeo\Model\Config\Backend\CrawlDelay;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class CrawlDelayTest extends TestCase
{
    private function build(string $value): CrawlDelay
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $m = new CrawlDelay(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(AbstractResource::class),
            $this->createMock(AbstractDb::class),
        );
        $m->setValue($value);
        return $m;
    }

    public function testEmptyValueAccepted(): void
    {
        $m = $this->build('');
        $m->beforeSave();
        $this->assertSame('', $m->getValue());
    }

    public function testIntegerIsKeptAsInt(): void
    {
        $m = $this->build('30');
        $m->beforeSave();
        $this->assertSame('30', $m->getValue());
    }

    public function testDecimalIsKept(): void
    {
        $m = $this->build('2.5');
        $m->beforeSave();
        $this->assertSame('2.5', $m->getValue());
    }

    public function testNegativeRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $m = $this->build('-1');
        $m->beforeSave();
    }

    public function testNonNumericRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $m = $this->build('fast');
        $m->beforeSave();
    }
}
