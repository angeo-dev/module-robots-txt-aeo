<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Plugin;

use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Magento\Robots\Model\Robots;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Intercepts Magento\Robots\Model\Robots::getData()
 *
 * This is the single injection point. Magento builds the robots.txt string
 * inside getData() and the controller just echoes it — intercepting here
 * guarantees modification before any output, including when the content
 * is empty (fresh install / custom robots.txt cleared in admin).
 *
 * Store scope is resolved from the StoreManager at request time so that
 * multi-store installations get the correct per-store configuration.
 *
 * @since 2.0.0 — short-circuits before resolving the injector pipeline when
 *                the module is disabled for the current store scope.
 */
class RobotsModelPlugin
{
    public function __construct(
        private readonly Config                $config,
        private readonly RobotsInjector        $injector,
        private readonly StoreManagerInterface $storeManager,
    ) {}

    public function afterGetData(Robots $subject, ?string $result): string
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = null;
        }

        // Fast path: when disabled, return the original content without
        // building the injector graph or resolving sitemaps.
        if (!$this->config->isEnabled($storeId)) {
            return (string) $result;
        }

        return $this->injector->process((string) $result, $storeId);
    }
}
