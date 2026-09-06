<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

/**
 * Reconstructs the RFC 9421 signature base — the exact byte string the signer
 * signed — from a request and a parsed signature.
 *
 * Layout (§2.5): one line per covered component,
 *
 *   "@authority": example.com
 *   "@method": GET
 *   "signature-agent": "https://chatgpt.com"
 *   "@signature-params": ("@authority" "@method" "signature-agent");created=…
 *
 * lines joined with LF, no trailing newline. The "@signature-params" line
 * repeats the Signature-Input value byte for byte, which is why the parser
 * keeps the raw definition instead of re-serialising the parsed form.
 *
 * @since 4.0.0
 */
class SignatureBaseBuilder
{
    /** Derived components this implementation knows how to reproduce. */
    public const SUPPORTED_DERIVED = [
        '@method',
        '@authority',
        '@path',
        '@scheme',
        '@target-uri',
        '@query',
    ];

    /**
     * @return string|null null when a covered component cannot be reproduced —
     *                     an unverifiable signature must never be treated as
     *                     a valid one
     */
    public function build(SignedRequest $request, HttpSignature $signature): ?string
    {
        $lines = [];

        foreach ($signature->components as $component) {
            $name  = strtolower($component);
            $value = $this->resolveComponent($request, $name);

            if ($value === null) {
                return null;
            }

            $lines[] = '"' . $name . '": ' . $value;
        }

        $lines[] = '"@signature-params": ' . $signature->rawDefinition;

        return implode("\n", $lines);
    }

    private function resolveComponent(SignedRequest $request, string $name): ?string
    {
        if (!str_starts_with($name, '@')) {
            // Header field. Missing headers cannot be reproduced.
            return $request->header($name);
        }

        if (!in_array($name, self::SUPPORTED_DERIVED, true)) {
            return null;
        }

        return match ($name) {
            '@method'     => strtoupper($request->method),
            '@authority'  => strtolower($request->authority),
            '@scheme'     => strtolower($request->scheme),
            '@path'       => $this->pathOnly($request->path),
            '@query'      => $this->query($request->path),
            '@target-uri' => $request->targetUri(),
            default       => null,
        };
    }

    private function pathOnly(string $path): string
    {
        $queryPos = strpos($path, '?');
        $path     = $queryPos === false ? $path : substr($path, 0, $queryPos);

        return $path === '' ? '/' : $path;
    }

    private function query(string $path): string
    {
        $queryPos = strpos($path, '?');

        return $queryPos === false ? '?' : substr($path, $queryPos);
    }
}
