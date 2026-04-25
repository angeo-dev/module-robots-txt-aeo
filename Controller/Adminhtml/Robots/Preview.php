<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\Adminhtml\Robots;

use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_RobotsTxtAeo::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory    $jsonFactory,
        private readonly RobotsInjector $injector,
        private readonly UrlFetcher     $urlFetcher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $robotsUrl  = $this->urlFetcher->getBaseUrl() . '/robots.txt';
            $existing   = $this->urlFetcher->fetchUrl($robotsUrl);
            $preview    = $this->injector->preview($existing);
            $validation = $this->injector->validate($existing);

            return $result->setData([
                'success'    => true,
                'preview'    => $preview,
                'source_url' => $robotsUrl,
                'present'    => $validation['present'],
                'missing'    => $validation['missing'],
                'existing'   => $existing,
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
