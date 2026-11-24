<?php
declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller;

use Angeo\RobotsTxtAeo\Controller\SecurityTxt\Index as SecurityTxt;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * /.well-known/security.txt → SecurityTxt controller (4.2.0). Runs late
 * (sortOrder 75), so a real file or another module always wins.
 *
 * @since 4.2.0
 */
class Router implements RouterInterface
{
    public const SECURITY_TXT = '.well-known/security.txt';

    public function __construct(private readonly ActionFactory $actionFactory)
    {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (trim((string) $request->getPathInfo(), '/') !== self::SECURITY_TXT) {
            return null;
        }
        $request->setModuleName('angeo_robots')->setControllerName('securitytxt')->setActionName('index');
        $request->setDispatched(true);

        return $this->actionFactory->create(SecurityTxt::class);
    }
}
