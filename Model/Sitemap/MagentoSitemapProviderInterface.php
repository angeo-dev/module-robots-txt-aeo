<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Sitemap;

/**
 * Returns absolute sitemap URLs known to the Magento_Sitemap module.
 *
 * Two implementations are provided:
 *   - MagentoSitemapProvider — real implementation that talks to Magento_Sitemap.
 *   - NullMagentoSitemapProvider — fallback when Magento_Sitemap is not installed.
 *
 * The bound implementation is selected in di.xml using a condition on the
 * presence of Magento_Sitemap's collection factory class.
 */
interface MagentoSitemapProviderInterface
{
    /**
     * @return string[] absolute sitemap URLs
     */
    public function getSitemapUrls(?int $storeId = null): array;
}
