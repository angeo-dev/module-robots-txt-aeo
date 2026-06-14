<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\Adminhtml\Robots;

use Angeo\RobotsTxtAeo\Model\Adminhtml\StoreIdResolver;
use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * @since 2.0.1 — store parameter is validated via StoreIdResolver; unexpected
 *                exceptions are logged server-side and a generic message is
 *                returned instead of the raw exception text.
 */
class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_RobotsTxtAeo::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory     $jsonFactory,
        private readonly RobotsInjector  $injector,
        private readonly Config          $moduleConfig,
        private readonly UrlFetcher      $urlFetcher,
        private readonly StoreIdResolver $storeIdResolver,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $storeId   = $this->storeIdResolver->resolve($this->getRequest());
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

                $effective   = $validation['effective'][$ua] ?? null;
                $allowedRoot = $effective['allowed'] ?? null;

                // v3.0.0: "present" alone is not enough — a bot whose merged
                // RFC 9309 rules block "/" is a failure even when its group
                // exists in the file.
                $status = 'disabled';
                if ($enabled) {
                    $status = ($present && $allowedRoot !== false) ? 'pass' : 'fail';
                }

                $botStatuses[] = [
                    'key'          => $key,
                    'user_agent'   => $ua,
                    'label'        => $bot->label,
                    'description'  => $bot->description,
                    'enabled'      => $enabled,
                    'present'      => $present,
                    'allowed_root' => $allowedRoot,
                    'matched_rule' => $effective['matched_rule'] ?? null,
                    'deprecated'   => $bot->deprecated,
                    'category'     => $bot->category,
                    'status'       => $status,
                ];
            }

            $enabledCount = count($enabledBots);
            $score = $enabledCount > 0
                ? (int) round(count($validation['present']) / $enabledCount * 100)
                : 0;

            $blockedRoot = array_values(array_filter(
                $botStatuses,
                static fn(array $row) => $row['enabled'] && $row['allowed_root'] === false
            ));

            return $result->setData([
                'success'    => true,
                'robots_url' => $robotsUrl,
                'bots'       => $botStatuses,
                'present'    => $validation['present'],
                'missing'    => $validation['missing'],
                'warnings'   => $validation['warnings'] ?? [],
                'score'      => $score,
                'pass'       => empty($validation['missing']) && empty($blockedRoot),
            ]);
        } catch (LocalizedException $e) {
            // Safe, intentionally user-facing message (e.g. invalid store).
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_RobotsTxtAeo] Validate action failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $result->setData([
                'success' => false,
                'error'   => (string) __('An unexpected error occurred. Check the Magento logs for details.'),
            ]);
        }
    }
}
