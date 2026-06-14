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
 * v2.0.1 SSRF hardening:
 *   - Only http:// and https:// URLs are accepted (scheme allow-list).
 *   - libcurl's CURLOPT_FOLLOWLOCATION is DISABLED. Redirects are followed
 *     manually (max MAX_REDIRECTS hops) and each hop is validated:
 *       * target scheme must be http/https,
 *       * target host must equal the original host (a leading "www." is the
 *         only tolerated difference, to support canonical-host redirects),
 *       * https:// -> http:// downgrades are refused.
 *     A redirect that violates the policy fails the fetch immediately and is
 *     never retried — the response can no longer be steered to an internal
 *     service via an open redirect or a compromised upstream.
 *
 * After v2.0.0 the only consumer of this fetcher is the admin Validate /
 * Preview controllers and the CLI commands — they read the live robots.txt
 * URL and check that enabled bot rules are present.
 */
class UrlFetcher
{
    public const DEFAULT_TIMEOUT       = 10;
    public const DEFAULT_RETRIES       = 2;
    public const MAX_REDIRECTS         = 3;
    public const DEFAULT_USER_AGENT    = 'Angeo-RobotsTxtAeo/2.0 (+https://angeo.dev)';
    public const ACCEPTED_STATUS_CODES = [200, 201];
    public const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];
    public const ALLOWED_SCHEMES       = ['http', 'https'];

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
     * Retry policy:
     *   - network-level errors and HTTP 5xx are retried with backoff,
     *   - HTTP 4xx fails immediately (deterministic),
     *   - redirect-policy violations fail immediately (deterministic).
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
        $validationError = $this->validateUrl($url);
        if ($validationError !== null) {
            return FetchResult::failure($url, $validationError);
        }

        $lastError  = '';
        $lastStatus = 0;

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $result = $this->attemptFetch($url, $timeout, $insecure);

                if ($result->isSuccess()) {
                    return $result;
                }

                $lastError  = $result->error;
                $lastStatus = $result->statusCode;

                // Only HTTP 5xx is considered transient; everything else
                // (4xx, redirect-policy violation, too many redirects) is
                // deterministic and must not be retried.
                if ($lastStatus < 500) {
                    return $result;
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

    /**
     * Single fetch attempt, following redirects manually under the policy
     * described in the class docblock.
     *
     * @throws \Throwable network-level errors bubble up to the retry loop
     */
    private function attemptFetch(string $url, int $timeout, bool $insecure): FetchResult
    {
        $currentUrl = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $curl = $this->curlFactory->create();
            $this->configureCurl($curl, $timeout, $insecure);

            $curl->get($currentUrl);

            $status = (int) $curl->getStatus();

            if (in_array($status, self::ACCEPTED_STATUS_CODES, true)) {
                return FetchResult::success($currentUrl, (string) $curl->getBody(), $status);
            }

            if (in_array($status, self::REDIRECT_STATUS_CODES, true)) {
                $location = $this->extractLocationHeader($curl->getHeaders());
                if ($location === '') {
                    return FetchResult::failure(
                        $currentUrl,
                        sprintf('HTTP %d redirect without Location header', $status),
                        $status
                    );
                }

                $next = $this->resolveRedirectTarget($url, $currentUrl, $location);
                if ($next === null) {
                    $this->logger->warning(sprintf(
                        '[Angeo_RobotsTxtAeo] Blocked redirect from %s to "%s" (cross-host, '
                        . 'disallowed scheme, or HTTPS downgrade).',
                        $currentUrl,
                        $location
                    ));
                    return FetchResult::failure(
                        $currentUrl,
                        sprintf('Redirect to disallowed location "%s" was blocked', $location),
                        $status
                    );
                }

                $currentUrl = $next;
                continue;
            }

            return FetchResult::failure($currentUrl, sprintf('HTTP %d', $status), $status);
        }

        return FetchResult::failure(
            $url,
            sprintf('Too many redirects (more than %d)', self::MAX_REDIRECTS)
        );
    }

    /**
     * Validate a URL before any network activity: well-formed, http/https only.
     */
    private function validateUrl(string $url): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'Invalid URL: ' . $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return sprintf('URL scheme "%s" is not allowed (http/https only): %s', $scheme, $url);
        }

        return null;
    }

    /**
     * Resolve and police a redirect target.
     *
     * @param string $originalUrl the URL the caller asked for (policy anchor)
     * @param string $currentUrl  the URL that produced the redirect
     * @param string $location    raw Location header value
     * @return string|null fully-qualified next URL, or null when the redirect
     *                     violates the policy
     */
    private function resolveRedirectTarget(string $originalUrl, string $currentUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        // Resolve relative Location against the current URL.
        if (str_starts_with($location, '/')) {
            $parts = parse_url($currentUrl);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return null;
            }
            $port     = isset($parts['port']) ? ':' . $parts['port'] : '';
            $location = $parts['scheme'] . '://' . $parts['host'] . $port . $location;
        }

        if (!filter_var($location, FILTER_VALIDATE_URL)) {
            return null;
        }

        $fromScheme = strtolower((string) parse_url($originalUrl, PHP_URL_SCHEME));
        $toScheme   = strtolower((string) parse_url($location, PHP_URL_SCHEME));
        if (!in_array($toScheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        // Refuse https:// -> http:// downgrades.
        if ($fromScheme === 'https' && $toScheme === 'http') {
            return null;
        }

        $fromHost = strtolower((string) parse_url($originalUrl, PHP_URL_HOST));
        $toHost   = strtolower((string) parse_url($location, PHP_URL_HOST));
        if (!$this->hostsEquivalent($fromHost, $toHost)) {
            return null;
        }

        return $location;
    }

    /**
     * Hosts are equivalent when identical, or when they differ only by a
     * leading "www." — the canonical-host redirect every second shop has.
     */
    private function hostsEquivalent(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }

        $strip = static fn (string $host): string =>
            str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return $strip($a) === $strip($b) && $strip($a) !== '';
    }

    /**
     * Case-insensitive lookup of the Location response header.
     *
     * @param mixed $headers whatever the Curl client returns (array expected)
     */
    private function extractLocationHeader(mixed $headers): string
    {
        if (!is_array($headers)) {
            return '';
        }
        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'location') {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }
        return '';
    }

    private function configureCurl(Curl $curl, int $timeout, bool $insecure): void
    {
        $curl->setOptions([
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            // SSRF hardening: never let libcurl follow redirects blindly.
            // Redirects are handled manually in attemptFetch() under a
            // same-host / no-downgrade policy.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => self::DEFAULT_USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_ENCODING       => '',
        ]);
    }
}
