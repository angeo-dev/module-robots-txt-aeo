<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

/**
 * Parser for the Signature-Input / Signature header pair (RFC 9421, encoded
 * as RFC 9651 structured fields).
 *
 *   Signature-Input: sig1=("@authority" "@method" "@path" "signature-agent")\
 *                    ;created=1755779377;keyid="…";expires=1755782977\
 *                    ;tag="web-bot-auth";alg="ed25519"
 *   Signature:       sig1=:BASE64…:
 *
 * Only the subset the Web Bot Auth profile uses is supported: inner lists of
 * quoted strings and bare/string/integer parameters. Component parameters
 * (";req", ";sf") are out of scope and cause the entry to be refused rather
 * than silently mis-verified.
 *
 * @since 4.0.0
 */
class SignatureInputParser
{
    /** Guard against pathological header values. */
    public const MAX_HEADER_LENGTH = 8192;

    /**
     * @return HttpSignature[] one entry per label present in both headers
     */
    public function parse(string $signatureInput, string $signature): array
    {
        if ($signatureInput === '' || $signature === ''
            || strlen($signatureInput) > self::MAX_HEADER_LENGTH
            || strlen($signature) > self::MAX_HEADER_LENGTH
        ) {
            return [];
        }

        $signatures = $this->parseSignatureHeader($signature);
        $result     = [];

        foreach ($this->splitEntries($signatureInput) as $label => $definition) {
            if (!isset($signatures[$label])) {
                continue;
            }

            $parsed = $this->parseDefinition($definition);
            if ($parsed === null) {
                continue;
            }

            [$components, $rawComponentList, $parameters] = $parsed;

            $result[] = new HttpSignature(
                $label,
                $components,
                $rawComponentList,
                trim($definition),
                $parameters,
                $signatures[$label]
            );
        }

        return $result;
    }

    /**
     * Split "sig1=…, sig2=…" into label => definition, respecting quotes and
     * parentheses so a comma inside a value does not split the entry.
     *
     * @return array<string, string>
     */
    private function splitEntries(string $header): array
    {
        $entries  = [];
        $buffer   = '';
        $inQuotes = false;
        $depth    = 0;
        $length   = strlen($header);

        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];

            if ($char === '"' && ($i === 0 || $header[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $char === '(') {
                $depth++;
            } elseif (!$inQuotes && $char === ')') {
                $depth = max(0, $depth - 1);
            } elseif (!$inQuotes && $depth === 0 && $char === ',') {
                $entries[] = $buffer;
                $buffer    = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $entries[] = $buffer;
        }

        $result = [];
        foreach ($entries as $entry) {
            $entry    = trim($entry);
            $equalPos = strpos($entry, '=');
            if ($equalPos === false || $equalPos === 0) {
                continue;
            }
            $label = trim(substr($entry, 0, $equalPos));
            if (!preg_match('/^[A-Za-z0-9_.\-*]+$/', $label)) {
                continue;
            }
            $result[$label] = trim(substr($entry, $equalPos + 1));
        }

        return $result;
    }

    /**
     * Parse "(components);param=value;…".
     *
     * @return array{0: string[], 1: string, 2: array<string, mixed>}|null
     */
    private function parseDefinition(string $definition): ?array
    {
        if (!str_starts_with($definition, '(')) {
            return null;
        }

        $closing = strpos($definition, ')');
        if ($closing === false) {
            return null;
        }

        $rawComponentList = substr($definition, 0, $closing + 1);
        $inner            = substr($definition, 1, $closing - 1);
        $components       = [];

        if (trim($inner) !== '') {
            if (!preg_match_all('/"([^"]*)"(\;[^\s"]+)?/', $inner, $matches, PREG_SET_ORDER)) {
                return null;
            }
            foreach ($matches as $match) {
                if (isset($match[2]) && $match[2] !== '') {
                    return null; // component parameters are not supported
                }
                $components[] = $match[1];
            }

            // Every token inside the list must have been a quoted string.
            $stripped = preg_replace('/"[^"]*"/', '', $inner) ?? '';
            if (trim($stripped) !== '') {
                return null;
            }
        }

        $parameters = $this->parseParameters(substr($definition, $closing + 1));

        return [$components, $rawComponentList, $parameters];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseParameters(string $raw): array
    {
        $parameters = [];

        foreach ($this->splitParameters($raw) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $equalPos = strpos($chunk, '=');
            if ($equalPos === false) {
                $parameters[strtolower($chunk)] = true;
                continue;
            }

            $name  = strtolower(trim(substr($chunk, 0, $equalPos)));
            $value = trim(substr($chunk, $equalPos + 1));

            if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            } elseif (is_numeric($value)) {
                $value = str_contains($value, '.') ? (float) $value : (int) $value;
            } elseif ($value === '?1' || $value === '?0') {
                $value = $value === '?1';
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * @return string[]
     */
    private function splitParameters(string $raw): array
    {
        $parts    = [];
        $buffer   = '';
        $inQuotes = false;
        $length   = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];

            if ($char === '"' && ($i === 0 || $raw[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $char === ';') {
                $parts[] = $buffer;
                $buffer  = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    /**
     * Parse "sig1=:base64:" into label => raw signature bytes.
     *
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $result = [];

        foreach ($this->splitEntries($header) as $label => $value) {
            $value = trim($value);
            if (strlen($value) < 2 || !str_starts_with($value, ':') || !str_ends_with($value, ':')) {
                continue;
            }

            $decoded = base64_decode(substr($value, 1, -1), true);
            if ($decoded === false || $decoded === '') {
                continue;
            }

            $result[$label] = $decoded;
        }

        return $result;
    }
}
