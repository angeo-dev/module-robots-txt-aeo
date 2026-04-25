<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Plugin;

use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Magento\Robots\Model\Robots;

/**
 * Intercepts Magento\Robots\Model\Robots::getData()
 *
 * This is the single injection point. Magento builds the robots.txt string
 * inside getData() and the controller just echoes it — intercepting here
 * guarantees modification before any output, including when the content
 * is empty (fresh install / custom robots.txt cleared in admin).
 */
class RobotsModelPlugin
{
    public function __construct(
        private readonly RobotsInjector $injector,
    ) {}

    public function afterGetData(Robots $subject, ?string $result): string
    {
        return $this->injector->process((string) $result);
    }
}
