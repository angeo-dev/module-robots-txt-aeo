<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

/**
 * One parsed RFC 9421 signature: the covered components, the raw parameter
 * string exactly as it appeared (it must be reproduced byte for byte in the
 * signature base) and the parsed parameters.
 *
 * @since 4.0.0
 */
final class HttpSignature
{
    /**
     * @param string[]             $components covered component identifiers, in order
     * @param string               $rawDefinition the value after "label=", byte for byte
     * @param array<string, mixed> $parameters created / expires / keyid / alg / tag / nonce
     */
    public function __construct(
        public readonly string $label,
        public readonly array  $components,
        public readonly string $rawComponentList,
        public readonly string $rawDefinition,
        public readonly array  $parameters,
        public readonly string $signature,
    ) {}

    public function keyId(): ?string
    {
        $keyId = $this->parameters['keyid'] ?? null;
        return is_string($keyId) && $keyId !== '' ? $keyId : null;
    }

    public function algorithm(): ?string
    {
        $alg = $this->parameters['alg'] ?? null;
        return is_string($alg) && $alg !== '' ? strtolower($alg) : null;
    }

    public function tag(): ?string
    {
        $tag = $this->parameters['tag'] ?? null;
        return is_string($tag) && $tag !== '' ? $tag : null;
    }

    public function created(): ?int
    {
        return isset($this->parameters['created']) && is_numeric($this->parameters['created'])
            ? (int) $this->parameters['created']
            : null;
    }

    public function expires(): ?int
    {
        return isset($this->parameters['expires']) && is_numeric($this->parameters['expires'])
            ? (int) $this->parameters['expires']
            : null;
    }

    public function covers(string $component): bool
    {
        return in_array(strtolower($component), array_map('strtolower', $this->components), true);
    }
}
