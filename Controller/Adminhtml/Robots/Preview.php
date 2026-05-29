<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\Adminhtml\Robots;

use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_RobotsTxtAeo::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory           $jsonFactory,
        private readonly RobotsInjector        $injector,
        private readonly UrlFetcher            $urlFetcher,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $storeId = $this->resolveStoreId();
            $url     = $this->urlFetcher->getRobotsUrl($storeId);

            $response   = $this->urlFetcher->fetch($url);
            $existing   = $response->isSuccess() ? $response->body : '';
            $preview    = $this->injector->preview($existing, $storeId);
            $validation = $this->injector->validate($existing, $storeId);

            return $result->setData([
                'success'    => true,
                'preview'    => $preview,
                'source_url' => $url,
                'fetched'    => $response->isSuccess(),
                'fetch_error'=> $response->isSuccess() ? '' : $response->error,
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

    private function resolveStoreId(): ?int
    {
        $param = $this->getRequest()->getParam('store');
        if ($param !== null && $param !== '') {
            return (int) $param;
        }
        try {
            return (int) $this->storeManager->getDefaultStoreView()?->getId();
        } catch (\Throwable) {
            return null;
        }
    }
}
