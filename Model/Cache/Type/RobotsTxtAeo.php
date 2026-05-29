<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Dedicated cache type for Angeo Robots.txt AEO.
 *
 * Surfaces in System → Cache Management as "Angeo Robots.txt AEO" so admins
 * can flush this module's data in isolation from the Configuration cache.
 *
 * @since 2.0.0
 */
class RobotsTxtAeo extends TagScope
{
    public const TYPE_IDENTIFIER = 'angeo_robots_txt_aeo';
    public const CACHE_TAG       = 'ANGEO_ROBOTS_TXT_AEO';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct(
            $cacheFrontendPool->get(self::TYPE_IDENTIFIER),
            self::CACHE_TAG
        );
    }
}
