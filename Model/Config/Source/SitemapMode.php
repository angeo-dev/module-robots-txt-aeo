<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Source;

use Angeo\RobotsTxtAeo\Model\SitemapResolver;
use Magento\Framework\Data\OptionSourceInterface;

class SitemapMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => SitemapResolver::SOURCE_AUTO,   'label' => __('Auto — detect from Magento Sitemap module or use /sitemap.xml')],
            ['value' => SitemapResolver::SOURCE_CUSTOM, 'label' => __('Custom — use URLs from the textarea below')],
            ['value' => SitemapResolver::SOURCE_NONE,   'label' => __('Do not emit Sitemap directives')],
        ];
    }
}
