<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

/**
 * Minimal, dependency-free IPv4/IPv6 CIDR containment check.
 *
 * Used by the bot IP verification command to test an address against the
 * vendor-published ranges (OpenAI / Perplexity JSON endpoints).
 *
 * @since 3.0.0
 */
class CidrMatcher
{
    /**
     * Whether $ip falls inside $cidr. Accepts a bare address as a /32 (v4)
     * or /128 (v6) range. Returns false on malformed input — never throws.
     */
    public function contains(string $cidr, string $ip): bool
    {
        $cidr = trim($cidr);
        $ip   = trim($ip);

        if ($cidr === '' || $ip === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            $cidr .= str_contains($cidr, ':') ? '/128' : '/32';
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        if (!ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Address families must match (4 bytes vs 16 bytes).
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0
            && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)
        ) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * Recursively collect every string in a decoded JSON payload that looks
     * like an IP address or CIDR range. Tolerant of the slightly different
     * shapes vendors use ({"prefixes":[{"ipv4Prefix": "..."}]}, flat lists,
     * nested objects).
     *
     * @param mixed $payload
     * @return string[]
     */
    public function extractRanges(mixed $payload): array
    {
        $ranges = [];
        $this->walk($payload, $ranges);
        return array_values(array_unique($ranges));
    }

    /** @param string[] $ranges */
    private function walk(mixed $node, array &$ranges): void
    {
        if (is_string($node)) {
            if ($this->looksLikeRange($node)) {
                $ranges[] = trim($node);
            }
            return;
        }
        if (is_array($node)) {
            foreach ($node as $child) {
                $this->walk($child, $ranges);
            }
        }
    }

    private function looksLikeRange(string $value): bool
    {
        $value = trim($value);
        $addr  = str_contains($value, '/') ? explode('/', $value, 2)[0] : $value;
        return @inet_pton($addr) !== false;
    }
}
