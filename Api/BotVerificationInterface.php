<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Api;

/**
 * Public, read-only API for proving that a request claiming to be an AI bot
 * really came from that vendor.
 *
 * robots.txt is a request, not a control: a User-agent header is one line of
 * text anyone can send. This interface exposes the two rails that actually
 * carry proof — the vendor's published IP ranges, and Web Bot Auth request
 * signatures (RFC 9421) — behind one contract, so consumers such as
 * angeo/module-aeo-audit do not have to re-implement either.
 *
 * Nothing here blocks traffic. Enforcement belongs at the WAF or CDN, where
 * it can happen before PHP is reached; this module answers the question and
 * leaves the decision to the operator.
 *
 * @api
 * @since 4.0.0
 */
interface BotVerificationInterface
{
    /**
     * Check an address against every catalogue bot that publishes IP ranges.
     *
     * @param string      $ip        IPv4 or IPv6 address seen in the access log
     * @param string|null $userAgent limit the check to one product token
     * @return array<int, array<string, mixed>> one result per bot checked
     */
    public function verifyIp(string $ip, ?string $userAgent = null): array;

    /**
     * Check a captured request against the Web Bot Auth signature it carries.
     *
     * @param array<string, string> $headers   header name => value, Signature,
     *                                         Signature-Input and Signature-Agent included
     * @param string                $authority the Host the request was sent to
     * @param string                $path      request path, query string included
     * @param string                $method    HTTP method
     * @param string                $scheme    http or https
     * @param string|null           $ip        source address, when known
     * @return array<string, mixed> a single verification result
     */
    public function verifyRequest(
        array $headers,
        string $authority,
        string $path = '/',
        string $method = 'GET',
        string $scheme = 'https',
        ?string $ip = null
    ): array;

    /**
     * Which verification rails each known bot publishes.
     *
     * @return array<string, string[]> product token => method identifiers
     */
    public function getVerificationSupport(): array;
}
