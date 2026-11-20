<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Config\Backend;

use Angeo\RobotsTxtAeo\Model\Config\Backend\QueryParamList;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

class QueryParamListTest extends TestCase
{
    private function model(string $value): QueryParamList
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $model = new QueryParamList(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(AbstractResource::class),
            $this->createMock(AbstractDb::class),
        );
        $model->setValue($value);

        return $model;
    }

    public function testNormalisesList(): void
    {
        $model = $this->model("Color, size\n\nprice\ncolor");
        $model->beforeSave();

        $this->assertSame("color\nsize\nprice", $model->getValue());
    }

    public function testRejectsNonCodes(): void
    {
        $this->expectException(LocalizedException::class);
        $this->model('price=10')->beforeSave();
    }
}
