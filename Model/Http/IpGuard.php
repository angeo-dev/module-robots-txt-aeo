<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Http;

/**
 * SSRF guard: resolves a hostname and decides whether the resulting addresses
 * are safe to talk to.
 *
 * Every address that is not globally routable is refused — loopback, RFC 1918
 * private space, link-local (including the 169.254.169.254 cloud metadata
 * endpoint), CGNAT, multicast, reserved space, and their IPv6 equivalents
 * (::1, fc00::/7, fe80::/10, IPv4-mapped forms).
 *
 * The resolved addresses are handed back to the caller so the request can be
 * PINNED to the exact address that was validated (CURLOPT_RESOLVE). Without
 * pinning, the name is resolved a second time inside libcurl and a DNS entry
 * with a short TTL can return a private address after the check has passed
 * (DNS rebinding).
 *
 * @since 4.0.0
 */
class IpGuard
{
    /** IPv4 ranges that must never be reached by an outbound module request. */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',          // "this" network
        '10.0.0.0/8',         // RFC 1918
        '100.64.0.0/10',      // CGNAT
        '127.0.0.0/8',        // loopback
        '169.254.0.0/16',     // link-local + cloud metadata
        '172.16.0.0/12',      // RFC 1918
        '192.0.0.0/24',       // IETF protocol assignments
        '192.0.2.0/24',       // TEST-NET-1
        '192.168.0.0/16',     // RFC 1918
        '198.18.0.0/15',      // benchmarking
        '198.51.100.0/24',    // TEST-NET-2
        '203.0.113.0/24',     // TEST-NET-3
        '224.0.0.0/4',        // multicast
        '240.0.0.0/4',        // reserved + broadcast
    ];

    /** IPv6 ranges that must never be reached. */
    private const BLOCKED_V6 = [
        '::/128',             // unspecified
        '::1/128',            // loopback
        '64:ff9b::/96',       // NAT64
        '100::/64',           // discard-only
        '2001:db8::/32',      // documentation
        'fc00::/7',           // unique local
        'fe80::/10',          // link-local
        'ff00::/8',           // multicast
    ];

    public function __construct(
        private readonly \Angeo\RobotsTxtAeo\Model\Verify\CidrMatcher $cidrMatcher,
    ) {}

    /**
     * Whether a single address is safe to connect to.
     */
    public function isAllowedAddress(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || @inet_pton($ip) === false) {
            return false;
        }

        // Normalise IPv4-mapped and IPv4-compatible IPv6 forms (::ffff:127.0.0.1)
        // so they cannot be used to smuggle a blocked v4 address past the v6 list.
        $mapped = $this->unmapIpv4($ip);
        if ($mapped !== null) {
            $ip = $mapped;
        }

        $ranges = str_contains($ip, ':') ? self::BLOCKED_V6 : self::BLOCKED_V4;
        foreach ($ranges as $range) {
            if ($this->cidrMatcher->contains($range, $ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a host to the addresses we are willing to connect to.
     *
     * A literal IP is validated directly. A name is resolved once; if ANY of
     * the resolved addresses is blocked, the whole host is refused — a mixed
     * answer is exactly the shape a rebinding attack takes.
     *
     * @return string[] validated addresses (empty = do not connect)
     */
    public function resolveAllowedAddresses(string $host): array
    {
        $host = trim($host, " \t\n\r\0\x0B[]");
        if ($host === '') {
            return [];
        }

        if (@inet_pton($host) !== false) {
            return $this->isAllowedAddress($host) ? [$host] : [];
        }

        $addresses = $this->lookup($host);
        if ($addresses === []) {
            return [];
        }

        foreach ($addresses as $address) {
            if (!$this->isAllowedAddress($address)) {
                return [];
            }
        }

        return $addresses;
    }

    /**
     * DNS lookup, A and AAAA. Kept in its own method so tests can stub it.
     *
     * @return string[]
     */
    protected function lookup(string $host): array
    {
        $addresses = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $addresses = $v4;
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique(array_filter($addresses, 'strlen')));
    }

    /**
     * Return the embedded IPv4 address of an IPv4-mapped / IPv4-compatible
     * IPv6 address, or null when there is none.
     */
    private function unmapIpv4(string $ip): ?string
    {
        if (!str_contains($ip, ':')) {
            return null;
        }

        $binary = @inet_pton($ip);
        if ($binary === false || strlen($binary) !== 16) {
            return null;
        }

        // ::ffff:a.b.c.d
        if (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($binary, 12)) ?: null;
        }

        // ::a.b.c.d (deprecated IPv4-compatible form), excluding :: and ::1
        if (str_starts_with($binary, str_repeat("\0", 12)) && substr($binary, 12) !== "\0\0\0\0") {
            return inet_ntop(substr($binary, 12)) ?: null;
        }

        return null;
    }
}
