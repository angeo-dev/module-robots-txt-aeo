<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Model\Sitemap\MagentoSitemapProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves the Sitemap: directives to emit in robots.txt.
 *
 * Three sources, in priority:
 *   1. Explicit URLs configured in admin (textarea, one per line).
 *   2. Auto-detect via MagentoSitemapProviderInterface (no-op if Magento_Sitemap not installed).
 *   3. Convention: <base_url>/sitemap.xml — only used when 'auto' mode is on
 *      AND nothing else resolved.
 *
 * The Magento_Sitemap dependency is injected via an interface so that:
 *   - this class stays unit-testable without ObjectManager hacks,
 *   - users without Magento_Sitemap can wire a NullSitemapProvider via di.xml.
 */
class SitemapResolver
{
    public const SOURCE_NONE   = 'none';
    public const SOURCE_AUTO   = 'auto';
    public const SOURCE_CUSTOM = 'custom';

    public function __construct(
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly StoreManagerInterface           $storeManager,
        private readonly UrlFetcher                      $urlFetcher,
        private readonly MagentoSitemapProviderInterface $magentoSitemapProvider,
    ) {}

    /**
     * Return absolute Sitemap URLs to write into robots.txt.
     *
     * @return string[]
     */
    public function resolve(?int $storeId = null): array
    {
        $mode = (string) (
            $this->scopeConfig->getValue('angeo_robots_txt_aeo/sitemap/mode', ScopeInterface::SCOPE_STORE, $storeId)
                ?: self::SOURCE_AUTO
        );

        if ($mode === self::SOURCE_NONE) {
            return [];
        }

        if ($mode === self::SOURCE_CUSTOM) {
            return $this->parseCustomList((string) $this->scopeConfig->getValue(
                'angeo_robots_txt_aeo/sitemap/custom_urls',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ));
        }

        // AUTO
        $fromMagento = $this->magentoSitemapProvider->getSitemapUrls($storeId);
        if (!empty($fromMagento)) {
            return $fromMagento;
        }

        return [$this->urlFetcher->getBaseUrl($storeId) . '/sitemap.xml'];
    }

    /**
     * @return string[]
     */
    private function parseCustomList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out   = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (filter_var($line, FILTER_VALIDATE_URL)) {
                $out[] = $line;
            }
        }
        return array_values(array_unique($out));
    }
}
