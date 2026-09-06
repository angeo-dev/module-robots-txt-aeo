<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Web Bot Auth verification: RFC 9421 HTTP Message Signatures with in-band
 * key discovery through the Signature-Agent header
 * (draft-meunier-webbotauth-httpsig-protocol).
 *
 * A signed request carries three headers:
 *
 *   Signature-Agent: "https://chatgpt.com"
 *   Signature-Input: sig1=("@authority" "@method" "@path" "signature-agent");…
 *   Signature:       sig1=:BASE64:
 *
 * Verification: confirm the agent origin is one we trust for this bot, fetch
 * its key directory, rebuild the signature base, check the Ed25519 signature.
 *
 * The origin is NEVER taken on trust from the header alone. Fetching an
 * arbitrary attacker-supplied origin would turn this verifier into an SSRF
 * primitive and a signature that verifies against the attacker's own key
 * would "prove" whatever they like. The origin must appear either in the bot's
 * catalogue entry or in the operator-configured trusted list.
 *
 * @since 4.0.0
 */
class WebBotAuthVerifier implements BotVerifierInterface
{
    /** Tolerated clock skew, in seconds, for created/expires. */
    public const CLOCK_SKEW = 300;

    /** Reject signatures older than this even when they carry no expires. */
    public const MAX_AGE = 3600;

    public function __construct(
        private readonly SignatureInputParser $parser,
        private readonly SignatureBaseBuilder $baseBuilder,
        private readonly JwksDirectory        $directory,
        private readonly Config               $config,
        private readonly LoggerInterface      $logger,
    ) {}

    public function getMethod(): string
    {
        return BotDefinition::VERIFY_WEB_BOT_AUTH;
    }

    public function supports(BotDefinition $bot): bool
    {
        return $bot->supportsVerification(BotDefinition::VERIFY_WEB_BOT_AUTH)
            && $this->trustedOrigins($bot) !== [];
    }

    public function verify(BotDefinition $bot, SignedRequest $request): VerificationResult
    {
        $method = $this->getMethod();

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return VerificationResult::unknown(
                $method,
                'PHP ext-sodium is not available, Ed25519 signatures cannot be checked.',
                $bot->userAgent
            );
        }

        $agentHeader = $request->header('signature-agent');
        if ($agentHeader === null || $agentHeader === '') {
            return VerificationResult::unsupported(
                $method,
                'Request carries no Signature-Agent header — it is not a signed request.',
                $bot->userAgent
            );
        }

        $origin = $this->normaliseOrigin($agentHeader);
        if ($origin === null) {
            return VerificationResult::failed(
                $method,
                'Signature-Agent is not a valid https origin.',
                $bot->userAgent
            );
        }

        $trusted = $this->trustedOrigins($bot);
        if (!in_array($origin, $trusted, true)) {
            return VerificationResult::failed(
                $method,
                sprintf(
                    'Signature-Agent "%s" is not a trusted signing origin for %s.',
                    $origin,
                    $bot->userAgent
                ),
                $bot->userAgent,
                ['trusted_origins' => $trusted]
            );
        }

        $signatures = $this->parser->parse(
            (string) $request->header('signature-input'),
            (string) $request->header('signature')
        );

        if ($signatures === []) {
            return VerificationResult::failed(
                $method,
                'Signature / Signature-Input headers are missing or unparseable.',
                $bot->userAgent
            );
        }

        $lastFailure = null;

        foreach ($signatures as $signature) {
            $result = $this->verifyOne($bot, $request, $signature, $origin);
            if ($result->isVerified()) {
                return $result;
            }
            $lastFailure = $result;
        }

        return $lastFailure ?? VerificationResult::failed($method, 'No signature could be verified.', $bot->userAgent);
    }

    private function verifyOne(
        BotDefinition $bot,
        SignedRequest $request,
        HttpSignature $signature,
        string        $origin,
    ): VerificationResult {
        $method = $this->getMethod();

        $algorithm = $signature->algorithm();
        if ($algorithm !== null && $algorithm !== 'ed25519') {
            return VerificationResult::failed(
                $method,
                sprintf('Unsupported signature algorithm "%s" (only ed25519 is accepted).', $algorithm),
                $bot->userAgent
            );
        }

        // The signature must actually cover the agent claim and the target,
        // otherwise a valid signature for one site can be replayed at another.
        if (!$signature->covers('@authority')) {
            return VerificationResult::failed(
                $method,
                'Signature does not cover "@authority" — it could be replayed against any host.',
                $bot->userAgent
            );
        }
        if (!$signature->covers('signature-agent')) {
            return VerificationResult::failed(
                $method,
                'Signature does not cover the "signature-agent" header — the identity claim is unsigned.',
                $bot->userAgent
            );
        }

        $timing = $this->checkTiming($signature);
        if ($timing !== null) {
            return VerificationResult::failed($method, $timing, $bot->userAgent);
        }

        $base = $this->baseBuilder->build($request, $signature);
        if ($base === null) {
            return VerificationResult::unknown(
                $method,
                'Signature covers a component that was not supplied, so the signature base '
                . 'cannot be rebuilt. Capture the full header set and retry.',
                $bot->userAgent,
                ['components' => $signature->components]
            );
        }

        $directoryUrl = $bot->jwksUrl ?: $this->directory->directoryUrl($origin);
        $keys         = $this->directory->getKeys($directoryUrl);

        if ($keys === null) {
            return VerificationResult::unknown(
                $method,
                sprintf('Key directory %s could not be retrieved.', $directoryUrl),
                $bot->userAgent,
                ['directory' => $directoryUrl]
            );
        }
        if ($keys === []) {
            return VerificationResult::unknown(
                $method,
                sprintf('Key directory %s published no usable Ed25519 keys.', $directoryUrl),
                $bot->userAgent,
                ['directory' => $directoryUrl]
            );
        }

        $keyId      = $signature->keyId();
        $candidates = $keyId !== null && isset($keys[$keyId]) ? [$keyId => $keys[$keyId]] : $keys;

        if ($keyId !== null && !isset($keys[$keyId])) {
            $this->logger->debug(sprintf(
                '[Angeo_RobotsTxtAeo] keyid "%s" not present in %s; trying every published key.',
                $keyId,
                $directoryUrl
            ));
        }

        foreach ($candidates as $kid => $publicKey) {
            if ($this->verifySignature($signature->signature, $base, $publicKey)) {
                return VerificationResult::verified(
                    $method,
                    sprintf('Signature verified against %s (key %s).', $directoryUrl, $kid),
                    $bot->userAgent,
                    ['directory' => $directoryUrl, 'kid' => (string) $kid, 'origin' => $origin]
                );
            }
        }

        return VerificationResult::failed(
            $method,
            'Signature did not verify against any key published by the agent — treat the '
            . 'request as spoofed.',
            $bot->userAgent,
            ['directory' => $directoryUrl, 'kid' => $keyId]
        );
    }

    /**
     * @return string|null an error message, or null when the timing is fine
     */
    private function checkTiming(HttpSignature $signature): ?string
    {
        $now     = time();
        $created = $signature->created();
        $expires = $signature->expires();

        if ($created !== null) {
            if ($created > $now + self::CLOCK_SKEW) {
                return 'Signature is created in the future.';
            }
            if ($expires === null && $created < $now - self::MAX_AGE) {
                return 'Signature is older than the accepted maximum age.';
            }
        }

        if ($expires !== null && $expires < $now - self::CLOCK_SKEW) {
            return 'Signature has expired.';
        }

        return null;
    }

    private function verifySignature(string $signature, string $base, string $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $base, $publicKey);
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_RobotsTxtAeo] Ed25519 verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Signing origins we accept for this bot: the catalogue entry plus any the
     * operator added in configuration.
     *
     * @return string[]
     */
    private function trustedOrigins(BotDefinition $bot): array
    {
        $origins = [];

        foreach (array_merge($bot->signatureAgents, $this->config->getTrustedSignatureAgents()) as $origin) {
            $normalised = $this->normaliseOrigin((string) $origin);
            if ($normalised !== null) {
                $origins[] = $normalised;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * Structured-field string, angle brackets and trailing slashes stripped;
     * https scheme required.
     */
    private function normaliseOrigin(string $value): ?string
    {
        $value = trim($value);
        $value = trim($value, '"');
        $value = trim($value, '<>');
        $value = rtrim($value, '/');

        if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }

        $origin = 'https://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }
}
