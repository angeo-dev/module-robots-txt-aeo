<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Cache\Type\RobotsTxtAeo as RobotsTxtAeoCache;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches and caches a Web Bot Auth key directory — the JWK Set an agent
 * publishes at <origin>/.well-known/http-message-signatures-directory.
 *
 * Only Ed25519 (OKP) keys are accepted. Keys carrying nbf/exp outside the
 * current time are ignored. The directory is fetched through UrlFetcher, so
 * it inherits the SSRF policy: https only, validated and pinned addresses,
 * response size cap.
 *
 * @since 4.0.0
 */
class JwksDirectory
{
    public const WELL_KNOWN_PATH = '/.well-known/http-message-signatures-directory';
    public const CACHE_KEY_PREFIX = 'angeo_robots_txt_aeo_jwks_v1_';
    public const CACHE_TTL = 86400;
    public const FETCH_TIMEOUT = 5;

    public function __construct(
        private readonly UrlFetcher          $urlFetcher,
        private readonly RobotsTxtAeoCache   $cache,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface     $logger,
    ) {}

    /**
     * The directory URL for an origin ("https://chatgpt.com").
     */
    public function directoryUrl(string $origin): string
    {
        return rtrim($origin, '/') . self::WELL_KNOWN_PATH;
    }

    /**
     * Load the usable Ed25519 keys of a directory, keyed by kid.
     *
     * @return array<string, string>|null raw 32-byte public keys, or null when
     *                                    the directory could not be retrieved
     */
    public function getKeys(string $directoryUrl): ?array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . sha1($directoryUrl);

        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            try {
                $decoded = $this->serializer->unserialize($cached);
                if (is_array($decoded)) {
                    return $this->decodeCached($decoded);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[Angeo_RobotsTxtAeo] Cached key directory unreadable: ' . $e->getMessage());
            }
        }

        $response = $this->urlFetcher->fetch($directoryUrl, self::FETCH_TIMEOUT, 1);
        if (!$response->isSuccess()) {
            $this->logger->warning(sprintf(
                '[Angeo_RobotsTxtAeo] Key directory %s unreachable: %s',
                $directoryUrl,
                $response->error
            ));
            return null;
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload) || !isset($payload['keys']) || !is_array($payload['keys'])) {
            $this->logger->warning('[Angeo_RobotsTxtAeo] Key directory did not contain a JWK Set: ' . $directoryUrl);
            return null;
        }

        $keys = $this->extractKeys($payload['keys']);

        try {
            $this->cache->save(
                $this->serializer->serialize(array_map('base64_encode', $keys)),
                $cacheKey,
                [RobotsTxtAeoCache::CACHE_TAG],
                self::CACHE_TTL
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_RobotsTxtAeo] Failed to cache key directory: ' . $e->getMessage());
        }

        return $keys;
    }

    /**
     * @param array<int, mixed> $jwks
     * @return array<string, string>
     */
    private function extractKeys(array $jwks): array
    {
        $now  = time();
        $keys = [];

        foreach ($jwks as $jwk) {
            if (!is_array($jwk)) {
                continue;
            }
            if (($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519') {
                continue;
            }
            if (isset($jwk['use']) && $jwk['use'] !== 'sig') {
                continue;
            }
            if (isset($jwk['nbf']) && is_numeric($jwk['nbf']) && $now < (int) $jwk['nbf']) {
                continue;
            }
            if (isset($jwk['exp']) && is_numeric($jwk['exp']) && $now > (int) $jwk['exp']) {
                continue;
            }

            $x = $jwk['x'] ?? null;
            if (!is_string($x) || $x === '') {
                continue;
            }

            $raw = $this->base64UrlDecode($x);
            if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                continue;
            }

            $kid = isset($jwk['kid']) && is_string($jwk['kid']) && $jwk['kid'] !== ''
                ? $jwk['kid']
                : $this->thumbprint($raw);

            $keys[$kid] = $raw;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, string>
     */
    private function decodeCached(array $decoded): array
    {
        $keys = [];
        foreach ($decoded as $kid => $encoded) {
            if (!is_string($encoded)) {
                continue;
            }
            $raw = base64_decode($encoded, true);
            if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[(string) $kid] = $raw;
            }
        }
        return $keys;
    }

    /**
     * RFC 8037 JWK thumbprint of an Ed25519 public key — the value operators
     * see as "kid" when a directory omits it.
     */
    private function thumbprint(string $rawKey): string
    {
        $json = sprintf(
            '{"crv":"Ed25519","kty":"OKP","x":"%s"}',
            rtrim(strtr(base64_encode($rawKey), '+/', '-_'), '=')
        );

        return rtrim(strtr(base64_encode(hash('sha256', $json, true)), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded  = strtr($value, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
