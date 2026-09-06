<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

/**
 * The parts of an inbound HTTP request that a Web Bot Auth signature covers.
 *
 * Deliberately a plain value object rather than a Magento RequestInterface:
 * the request being checked is usually NOT the current one. It is a line an
 * admin pulled out of an access log, or a captured header set, examined after
 * the fact.
 *
 * @since 4.0.0
 */
final class SignedRequest
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     * @param string|null           $remoteAddress source address of the request, when known
     */
    public function __construct(
        public readonly string $method,
        public readonly string $scheme,
        public readonly string $authority,
        public readonly string $path,
        array $headers = [],
        public readonly ?string $remoteAddress = null,
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower(trim((string) $name))] = trim((string) $value);
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower(trim($name))] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function targetUri(): string
    {
        return $this->scheme . '://' . $this->authority . $this->path;
    }

    /**
     * Build from a map of headers plus the request line details.
     *
     * @param array<string, string> $headers
     */
    public static function fromHeaders(
        array $headers,
        string $method = 'GET',
        string $scheme = 'https',
        string $authority = '',
        string $path = '/',
        ?string $remoteAddress = null,
    ): self {
        return new self($method, $scheme, $authority, $path, $headers, $remoteAddress);
    }
}
