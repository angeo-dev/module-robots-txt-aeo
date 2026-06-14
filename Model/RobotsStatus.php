<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Api\RobotsStatusInterface;
use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Parser\RobotsTxtParser;
use Angeo\RobotsTxtAeo\Model\Rep\RepMatcher;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Implementation of the public read-only API.
 *
 * Wraps Config + RobotsInjector + SitemapResolver and exposes their results
 * via the stable Api\RobotsStatusInterface contract. Consumer modules
 * (angeo/module-aeo-audit) wire to the interface, not this class.
 *
 * @since 2.0.0
 */
class RobotsStatus implements RobotsStatusInterface
{
    public function __construct(
        private readonly Config                $config,
        private readonly RobotsInjector        $injector,
        private readonly SitemapResolver       $sitemapResolver,
        private readonly UrlFetcher            $urlFetcher,
        private readonly StoreManagerInterface $storeManager,
        private readonly RobotsTxtParser       $parser,
        private readonly RepMatcher            $repMatcher,
    ) {}

    public function getEffectiveRobotsTxt(?int $storeId = null): string
    {
        $storeId = $this->resolveStoreId($storeId);
        return $this->injector->preview('', $storeId);
    }

    public function getEnabledBotUserAgents(?int $storeId = null): array
    {
        $storeId = $this->resolveStoreId($storeId);
        return array_values(array_map(
            fn(BotDefinition $b) => $b->userAgent,
            $this->config->getEnabledBots($storeId)
        ));
    }

    public function getEffectiveSitemaps(?int $storeId = null): array
    {
        $storeId = $this->resolveStoreId($storeId);
        $base    = $this->urlFetcher->getBaseUrl($storeId);
        $baseIsHttps = stripos($base, 'https://') === 0;

        $sitemaps = $this->sitemapResolver->resolve($storeId);

        if (!$baseIsHttps) {
            return $sitemaps;
        }

        return array_map(static function (string $url) {
            if (stripos($url, 'http://') === 0) {
                return 'https://' . substr($url, 7);
            }
            return $url;
        }, $sitemaps);
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->config->isEnabled($this->resolveStoreId($storeId));
    }

    public function getMode(?int $storeId = null): string
    {
        return $this->config->getMode($this->resolveStoreId($storeId));
    }

    /**
     * @inheritDoc
     * @since 3.0.0
     */
    public function getEffectiveAccess(?int $storeId = null): array
    {
        $storeId = $this->resolveStoreId($storeId);
        $parsed  = $this->parser->parse($this->injector->preview('', $storeId));

        $result = [];
        foreach ($this->config->getEnabledBots($storeId) as $bot) {
            $result[$bot->userAgent] = $this->repMatcher
                ->isAllowed($parsed, $bot->userAgent, '/')
                ->toArray();
        }
        return $result;
    }

    /**
     * @inheritDoc
     * @since 3.0.0
     */
    public function getContentSignalLines(?int $storeId = null): array
    {
        return $this->config->getContentSignalLines($this->resolveStoreId($storeId));
    }

    private function resolveStoreId(?int $storeId): ?int
    {
        if ($storeId !== null) {
            return $storeId;
        }
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            return null;
        }
    }
}
