<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Model\Http\IpGuard;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP utility for fetching robots.txt, bot IP-range lists and Web Bot Auth
 * key directories, and for deriving store URLs.
 *
 * Uses Magento's native Curl client (libcurl bindings) rather than
 * file_get_contents: allow_url_fopen may be disabled, there is no connect
 * timeout on the stream wrapper, and libcurl honours the system CA bundle.
 *
 * TLS verification is ENABLED by default. The $insecure flag exists only for
 * local development against self-signed certificates; production code paths
 * never set it.
 *
 * SSRF policy (2.0.1, extended in 4.0.0):
 *   - scheme allow-list (http/https) plus CURLOPT_PROTOCOLS;
 *   - CURLOPT_FOLLOWLOCATION disabled; redirects are followed manually, max
 *     MAX_REDIRECTS hops, each hop must stay on the original host (a leading
 *     "www." is the only tolerated difference) with no HTTPS→HTTP downgrade;
 *   - every hop's host is resolved and each resulting address must be
 *     globally routable — loopback, RFC 1918, link-local (169.254.169.254
 *     cloud metadata), CGNAT and their IPv6 equivalents are refused;
 *   - the validated address is PINNED for the request via CURLOPT_RESOLVE, so
 *     the name cannot resolve to something else between the check and the
 *     connection (DNS rebinding);
 *   - responses are capped at MAX_RESPONSE_BYTES and truncated beyond it, so
 *     a hostile or broken upstream cannot exhaust memory on an admin request.
 *
 * @since 4.0.0 — per-hop IP validation, address pinning, response size cap.
 */
class UrlFetcher
{
    public const DEFAULT_TIMEOUT       = 10;
    public const DEFAULT_RETRIES       = 2;
    public const MAX_REDIRECTS         = 3;
    public const DEFAULT_USER_AGENT    = 'Angeo-RobotsTxtAeo/4.0 (+https://angeo.dev)';
    public const ACCEPTED_STATUS_CODES = [200, 201];
    public const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];
    public const ALLOWED_SCHEMES       = ['http', 'https'];

    /** Hard cap on a single response body (1 MiB). */
    public const MAX_RESPONSE_BYTES = 1048576;

    public function __construct(
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurlFactory           $curlFactory,
        private readonly LoggerInterface       $logger,
        private readonly IpGuard               $ipGuard,
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
     *   - policy violations (scheme, host, address) fail immediately.
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

                // Only HTTP 5xx is transient; everything else (4xx, policy
                // violation, too many redirects) is deterministic.
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
            $addresses = $this->resolvePinnedAddresses($currentUrl);
            if ($addresses === []) {
                $this->logger->warning(sprintf(
                    '[Angeo_RobotsTxtAeo] Refused to fetch %s — the host does not resolve to a '
                    . 'publicly routable address.',
                    $currentUrl
                ));
                return FetchResult::failure(
                    $currentUrl,
                    'Host does not resolve to a publicly routable address'
                );
            }

            $curl = $this->curlFactory->create();
            $this->configureCurl($curl, $timeout, $insecure, $currentUrl, $addresses);

            $curl->get($currentUrl);

            $status = (int) $curl->getStatus();

            if (in_array($status, self::ACCEPTED_STATUS_CODES, true)) {
                return FetchResult::success($currentUrl, $this->cap((string) $curl->getBody()), $status);
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

        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            return 'URL has no host: ' . $url;
        }

        return null;
    }

    /**
     * Resolve the host of $url and return the addresses that passed the guard.
     *
     * @return string[]
     */
    private function resolvePinnedAddresses(string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return [];
        }

        return $this->ipGuard->resolveAllowedAddresses($host);
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

    /**
     * Truncate an over-sized body. CURLOPT_MAXFILESIZE only fires when the
     * upstream declares Content-Length, so the cap is also enforced here.
     */
    private function cap(string $body): string
    {
        if (strlen($body) <= self::MAX_RESPONSE_BYTES) {
            return $body;
        }

        $this->logger->warning(sprintf(
            '[Angeo_RobotsTxtAeo] Response exceeded %d bytes and was truncated.',
            self::MAX_RESPONSE_BYTES
        ));

        return substr($body, 0, self::MAX_RESPONSE_BYTES);
    }

    /**
     * @param string[] $addresses validated addresses to pin the host to
     */
    private function configureCurl(
        Curl   $curl,
        int    $timeout,
        bool   $insecure,
        string $url,
        array  $addresses,
    ): void {
        $options = [
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            // SSRF hardening: never let libcurl follow redirects blindly.
            // Redirects are handled manually in attemptFetch() under a
            // same-host / no-downgrade / validated-address policy.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => self::DEFAULT_USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => !$insecure,
            CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXFILESIZE    => self::MAX_RESPONSE_BYTES,
        ];

        $resolve = $this->buildResolveEntries($url, $addresses);
        if ($resolve !== []) {
            $options[CURLOPT_RESOLVE]          = $resolve;
            $options[CURLOPT_DNS_CACHE_TIMEOUT] = 0;
        }

        $curl->setOptions($options);
    }

    /**
     * CURLOPT_RESOLVE entries ("host:port:addr") pinning the request to the
     * addresses that passed the guard.
     *
     * @param string[] $addresses
     * @return string[]
     */
    private function buildResolveEntries(string $url, array $addresses): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return [];
        }

        $host = strtolower((string) $parts['host']);
        if (@inet_pton(trim($host, '[]')) !== false) {
            return []; // literal address — nothing to pin
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port   = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));

        $entries = [];
        foreach ($addresses as $address) {
            $entries[] = sprintf('%s:%d:%s', $host, $port, $address);
        }

        return $entries;
    }
}
