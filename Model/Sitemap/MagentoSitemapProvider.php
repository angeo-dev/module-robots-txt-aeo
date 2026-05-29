<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Sitemap;

use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Framework\ObjectManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads sitemap entries from the Magento_Sitemap module.
 *
 * Magento_Sitemap is a soft dependency — when it's absent, getSitemapUrls()
 * returns an empty list. We rely on ObjectManagerInterface (an injected
 * service-locator) rather than typed constructor dependencies because typing
 * the CollectionFactory would create a hard composer dependency we do not want.
 */
class MagentoSitemapProvider implements MagentoSitemapProviderInterface
{
    private const COLLECTION_FACTORY = \Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory::class;

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly UrlFetcher             $urlFetcher,
        private readonly LoggerInterface        $logger,
    ) {}

    public function getSitemapUrls(?int $storeId = null): array
    {
        if (!class_exists(self::COLLECTION_FACTORY)) {
            return [];
        }

        try {
            $collectionFactory = $this->objectManager->get(self::COLLECTION_FACTORY);
            $collection        = $collectionFactory->create();
            if ($storeId !== null) {
                $collection->addStoreFilter([$storeId]);
            }

            $base = $this->urlFetcher->getBaseUrl($storeId);
            $urls = [];
            foreach ($collection as $sitemap) {
                $path     = trim((string) $sitemap->getData('sitemap_path'), '/');
                $filename = (string) $sitemap->getData('sitemap_filename');
                if ($filename === '') {
                    continue;
                }
                $relative = ($path === '' ? '' : '/' . $path) . '/' . ltrim($filename, '/');
                $urls[]   = $base . $relative;
            }
            return array_values(array_unique($urls));
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_RobotsTxtAeo] Magento_Sitemap lookup failed: ' . $e->getMessage());
            return [];
        }
    }
}
