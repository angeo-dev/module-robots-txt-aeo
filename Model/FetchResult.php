<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

/**
 * Immutable result object returned by UrlFetcher::fetch().
 *
 * Use the static factories success() / failure() to construct instances —
 * the constructor is intentionally not for direct use from calling code.
 *
 * @since 2.0.0 — simplified after removing the remote-registry feature.
 *                Previous versions carried response headers for signature
 *                verification; that surface is no longer needed.
 */
final class FetchResult
{
    /**
     * @internal Use FetchResult::success() or FetchResult::failure() instead.
     */
    public function __construct(
        public readonly string $url,
        public readonly bool   $success,
        public readonly string $body,
        public readonly int    $statusCode,
        public readonly string $error,
    ) {}

    public static function success(string $url, string $body, int $statusCode): self
    {
        return new self($url, true, $body, $statusCode, '');
    }

    public static function failure(string $url, string $error, int $statusCode = 0): self
    {
        return new self($url, false, '', $statusCode, $error);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Human-readable summary, e.g. for CLI / log output.
     */
    public function describe(): string
    {
        if ($this->success) {
            return sprintf('OK %d, %d bytes from %s', $this->statusCode, strlen($this->body), $this->url);
        }
        return sprintf('FAIL %s — %s', $this->url, $this->error ?: 'unknown error');
    }
}
