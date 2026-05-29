<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\Adminhtml\Robots;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;

class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_RobotsTxtAeo::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory           $jsonFactory,
        private readonly RobotsInjector        $injector,
        private readonly Config                $moduleConfig,
        private readonly UrlFetcher            $urlFetcher,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $storeId   = $this->resolveStoreId();
            $robotsUrl = $this->urlFetcher->getRobotsUrl($storeId);
            $response  = $this->urlFetcher->fetch($robotsUrl);

            if (!$response->isSuccess()) {
                return $result->setData([
                    'success'    => false,
                    'error'      => 'Could not fetch ' . $robotsUrl . ': ' . $response->error,
                    'robots_url' => $robotsUrl,
                ]);
            }

            $validation  = $this->injector->validate($response->body, $storeId);
            $allBots     = $this->moduleConfig->getAllBots();
            $enabledBots = $this->moduleConfig->getEnabledBots($storeId);

            $botStatuses = [];
            foreach ($allBots as $key => $bot) {
                /** @var BotDefinition $bot */
                $ua      = $bot->userAgent;
                $enabled = array_key_exists($key, $enabledBots);
                $present = in_array($ua, $validation['present'], true);

                $botStatuses[] = [
                    'key'         => $key,
                    'user_agent'  => $ua,
                    'label'       => $bot->label,
                    'description' => $bot->description,
                    'enabled'     => $enabled,
                    'present'     => $present,
                    'status'      => !$enabled ? 'disabled' : ($present ? 'pass' : 'fail'),
                ];
            }

            $enabledCount = count($enabledBots);
            $score = $enabledCount > 0
                ? (int) round(count($validation['present']) / $enabledCount * 100)
                : 0;

            return $result->setData([
                'success'    => true,
                'robots_url' => $robotsUrl,
                'bots'       => $botStatuses,
                'present'    => $validation['present'],
                'missing'    => $validation['missing'],
                'score'      => $score,
                'pass'       => empty($validation['missing']),
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
