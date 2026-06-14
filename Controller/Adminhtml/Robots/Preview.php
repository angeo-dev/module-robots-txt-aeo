<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\Adminhtml\Robots;

use Angeo\RobotsTxtAeo\Model\Adminhtml\StoreIdResolver;
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
class Preview extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Angeo_RobotsTxtAeo::dashboard';

    public function __construct(
        Context $context,
        private readonly JsonFactory     $jsonFactory,
        private readonly RobotsInjector  $injector,
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
            $storeId = $this->storeIdResolver->resolve($this->getRequest());
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
        } catch (LocalizedException $e) {
            // Safe, intentionally user-facing message (e.g. invalid store).
            return $result->setData([
                'success' => false,
                'error'   => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[Angeo_RobotsTxtAeo] Preview action failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return $result->setData([
                'success' => false,
                'error'   => (string) __('An unexpected error occurred. Check the Magento logs for details.'),
            ]);
        }
    }
}
