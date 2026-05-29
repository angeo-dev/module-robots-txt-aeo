<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Config\Backend;

use Angeo\RobotsTxtAeo\Model\Config\Backend\PathList;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Event\ManagerInterface;
use PHPUnit\Framework\TestCase;

class PathListTest extends TestCase
{
    private function build(string $value): PathList
    {
        $context = $this->createMock(Context::class);
        $eventManager = $this->createMock(ManagerInterface::class);
        $context->method('getEventDispatcher')->willReturn($eventManager);

        $model = new PathList(
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

    public function testEmptyValuePassesThrough(): void
    {
        $m = $this->build('');
        $m->beforeSave();
        $this->assertSame('', $m->getValue());
    }

    public function testTrimsAndAnchorsPaths(): void
    {
        $m = $this->build("catalog/\n  /admin/  \nfoo");
        $m->beforeSave();
        $this->assertSame("/catalog/\n/admin/\n/foo", $m->getValue());
    }

    public function testStripsInlineComments(): void
    {
        $m = $this->build("/catalog/ # public\n/admin/ # block this");
        $m->beforeSave();
        $this->assertSame("/catalog/\n/admin/", $m->getValue());
    }

    public function testDeduplicates(): void
    {
        $m = $this->build("/catalog/\n/catalog/\n/catalog/");
        $m->beforeSave();
        $this->assertSame("/catalog/", $m->getValue());
    }

    public function testAcceptsCommaSeparated(): void
    {
        $m = $this->build('/a/, /b/, /c/');
        $m->beforeSave();
        $this->assertSame("/a/\n/b/\n/c/", $m->getValue());
    }

    public function testRejectsFullUrls(): void
    {
        $this->expectException(LocalizedException::class);
        $m = $this->build('https://example.com/catalog/');
        $m->beforeSave();
    }

    public function testAllowsWildcardPaths(): void
    {
        $m = $this->build('*.pdf');
        $m->beforeSave();
        $this->assertSame('*.pdf', $m->getValue());
    }
}
