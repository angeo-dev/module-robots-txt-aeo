<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP utility for fetching robots.txt and deriving store URLs.
 *
 * Uses Magento's native Curl client (libcurl bindings) instead of file_get_contents
 * because:
 *   - allow_url_fopen may be disabled in hardened PHP environments
 *   - file_get_contents has no native timeout enforcement for connect phase
 *   - PSR-compatible curl client is far easier to mock in unit tests
 *   - libcurl honours system CA bundles correctly
 *
 * TLS verification is ENABLED by default. The $insecure flag in fetch() is
 * provided only as an explicit opt-in for local dev with self-signed certs;
 * production code paths never set it.
 *
 * After v2.0.0 the only consumer of this fetcher is the admin Validate
 * controller / CLI ValidateCommand — they read the live robots.txt URL and
 * check that enabled bot rules are present. No body parsing, no header
 * inspection.
 */
class UrlFetcher
{
    public const DEFAULT_TIMEOUT       = 10;
    public const DEFAULT_RETRIES       = 2;
    public const DEFAULT_USER_AGENT    = 'Angeo-RobotsTxtAeo/2.0 (+https://angeo.dev)';
    public const ACCEPTED_STATUS_CODES = [200, 201];

    public function __construct(
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurlFactory           $curlFactory,
        private readonly LoggerInterface       $logger,
    ) {}

    /**
     * Base URL for the current (or requested) store, without trailing slash.
     *
     * In admin context, $storeId must be passed explicitly because the admin
     * store has no usable frontend base_url.
     */
    public function getBaseUrl(?int $storeId = null): string
    {
        try {
            $store = $this->storeManager->getStore($storeId);
            $url   = $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB, true);
        } catch (\Throwable) {
            $url = (string) (
                $this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE, $storeId)
                    ?: $this->scopeConfig->getValue('web/unsecure/base_url', ScopeInterface::SCOPE_STORE, $storeId)
            );
        }

        return rtrim($url, '/');
    }

    /**
     * Robots.txt URL for the given store.
     */
    public function getRobotsUrl(?int $storeId = null): string
    {
        return $this->getBaseUrl($storeId) . '/robots.txt';
    }

    /**
     * Fetch a URL with retries.
     *
     * Returns FetchResult with success flag, body, status code, error.
     * Does NOT throw — callers always get a structured result.
     *
     * @param int  $timeout   per-attempt timeout in seconds
     * @param int  $retries   number of retries on transient failure (5xx, network)
     * @param bool $insecure  disable TLS verification (dev/self-signed only)
     */
    public function fetch(
        string $url,
        int    $timeout  = self::DEFAULT_TIMEOUT,
        int    $retries  = self::DEFAULT_RETRIES,
        bool   $insecure = false,
    ): FetchResult {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return FetchResult::failure($url, 'Invalid URL: ' . $url);
        }

        $lastError  = '';
        $lastStatus = 0;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $curl = $this->curlFactory->create();
                $this->configureCurl($curl, $timeout, $insecure);

                $curl->get($url);

                $status = (int) $curl->getStatus();
                $body   = (string) $curl->getBody();

                if (in_array($status, self::ACCEPTED_STATUS_CODES, true)) {
                    return FetchResult::success($url, $body, $status);
                }

                $lastStatus = $status;
                $lastError  = sprintf('HTTP %d', $status);

                if ($status >= 400 && $status < 500) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->logger->debug(
                    sprintf('[Angeo_RobotsTxtAeo] Fetch attempt %d failed for %s: %s',
                        $attempt + 1, $url, $lastError)
                );
            }

            if ($attempt < $retries) {
                usleep((int) (100_000 * (2 ** $attempt)));
            }
        }

        return FetchResult::failure($url, $lastError ?: 'Unknown error', $lastStatus);
    }

    private function configureCurl(Curl $curl, int $timeout, bool $insecure): void
    {
        $curl->setOptions([
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => self::DEFAULT_USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_ENCODING       => '',
        ]);
    }
}
