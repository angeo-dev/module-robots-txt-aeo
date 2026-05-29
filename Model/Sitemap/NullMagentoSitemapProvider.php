<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Sitemap;

/**
 * Fallback implementation used when Magento_Sitemap is not installed.
 *
 * Always returns an empty list — SitemapResolver then falls back to the
 * `<base_url>/sitemap.xml` convention.
 */
class NullMagentoSitemapProvider implements MagentoSitemapProviderInterface
{
    public function getSitemapUrls(?int $storeId = null): array
    {
        return [];
    }
}
