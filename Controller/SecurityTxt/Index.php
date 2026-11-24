<?php
declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Controller\SecurityTxt;

use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\SecurityTxt\Builder;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * GET /.well-known/security.txt (4.2.0). 404 when off or with no contact.
 *
 * @since 4.2.0
 */
class Index implements HttpGetActionInterface
{
    private const XML_PATH_SUPPORT_EMAIL = 'trans_email/ident_support/email';

    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function execute(): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        try {
            $store = $this->storeManager->getStore();
            $storeId = (int) $store->getId();
            if (!$this->config->isSecurityTxtEnabled($storeId)) {
                return $this->notFound($result);
            }
            $contacts = $this->config->getSecurityTxtContacts($storeId);
            if ($contacts === []) {
                $support = (string) $this->scopeConfig->getValue(self::XML_PATH_SUPPORT_EMAIL, ScopeInterface::SCOPE_STORE, $storeId);
                $contacts = $support !== '' ? [$support] : [];
            }
            $parts = parse_url((string) $store->getBaseUrl());
            $origin = is_array($parts) && isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : '';
            $body = Builder::build(
                $contacts,
                $this->config->getSecurityTxtExpiresDays($storeId),
                new \DateTimeImmutable('now'),
                $origin . '/.well-known/security.txt',
                $this->config->getSecurityTxtLanguages($storeId),
                $this->config->getSecurityTxtPolicyUrl($storeId)
            );
            if ($body === null) {
                return $this->notFound($result);
            }
            $result->setHeader('Cache-Control', 'public, max-age=86400', true);
            $result->setHttpResponseCode(200);
            $result->setContents($body);
        } catch (\Throwable) {
            return $this->notFound($result);
        }

        return $result;
    }

    private function notFound(Raw $result): Raw
    {
        $result->setHttpResponseCode(404);
        $result->setContents('Not found');

        return $result;
    }
}
