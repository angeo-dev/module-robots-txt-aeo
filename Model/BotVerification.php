<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Angeo\RobotsTxtAeo\Api\BotVerificationInterface;
use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Bot\BotRegistry;
use Angeo\RobotsTxtAeo\Model\Verify\BotVerifierInterface;
use Angeo\RobotsTxtAeo\Model\Verify\IpRangeVerifier;
use Angeo\RobotsTxtAeo\Model\Verify\SignedRequest;
use Angeo\RobotsTxtAeo\Model\Verify\VerificationResult;

/**
 * Implementation of the public verification API.
 *
 * Orchestration only: it picks the bot, picks the rails that bot actually
 * publishes, and returns what each verifier reported. The cryptography lives
 * in WebBotAuthVerifier, the range matching in IpRangeVerifier.
 *
 * @since 4.0.0
 */
class BotVerification implements BotVerificationInterface
{
    /**
     * @param BotVerifierInterface[] $verifiers keyed by method, wired in di.xml
     */
    public function __construct(
        private readonly BotRegistry     $botRegistry,
        private readonly IpRangeVerifier $ipRangeVerifier,
        private readonly array           $verifiers = [],
    ) {}

    /**
     * @inheritDoc
     */
    public function verifyIp(string $ip, ?string $userAgent = null): array
    {
        $ip = trim($ip);
        if ($ip === '' || @inet_pton($ip) === false) {
            return [
                VerificationResult::unknown(
                    BotDefinition::VERIFY_IP_RANGE,
                    sprintf('"%s" is not a valid IPv4 or IPv6 address.', $ip)
                )->toArray(),
            ];
        }

        $results = [];

        foreach ($this->botRegistry->all() as $bot) {
            if ($userAgent !== null && strtolower($bot->userAgent) !== strtolower(trim($userAgent))) {
                continue;
            }
            if (!$this->ipRangeVerifier->supports($bot)) {
                continue;
            }

            $results[] = $this->ipRangeVerifier->verifyAddress($bot, $ip)->toArray();
        }

        return $results;
    }

    /**
     * @inheritDoc
     */
    public function verifyRequest(
        array $headers,
        string $authority,
        string $path = '/',
        string $method = 'GET',
        string $scheme = 'https',
        ?string $ip = null
    ): array {
        $request = SignedRequest::fromHeaders($headers, $method, $scheme, $authority, $path, $ip);

        $bot = $this->resolveBot($request);
        if ($bot === null) {
            return VerificationResult::unsupported(
                BotDefinition::VERIFY_NONE,
                'The request does not claim any AI crawler in the catalogue.'
            )->toArray();
        }

        $best = null;

        foreach ($this->orderedVerifiers() as $verifier) {
            if (!$verifier->supports($bot)) {
                continue;
            }

            $result = $verifier->verify($bot, $request);
            if ($result->isVerified()) {
                return $result->toArray();
            }

            // A definite failure outranks an inconclusive one: "the signature
            // is forged" is more useful than "the directory timed out".
            if ($best === null
                || ($result->state === VerificationResult::STATE_FAILED
                    && $best->state !== VerificationResult::STATE_FAILED)
            ) {
                $best = $result;
            }
        }

        return ($best ?? VerificationResult::unsupported(
            BotDefinition::VERIFY_NONE,
            sprintf('%s publishes no verification rail — the User-agent cannot be proven.', $bot->userAgent),
            $bot->userAgent
        ))->toArray();
    }

    /**
     * @inheritDoc
     */
    public function getVerificationSupport(): array
    {
        $support = [];

        foreach ($this->botRegistry->all() as $bot) {
            $support[$bot->userAgent] = $bot->verification;
        }

        return $support;
    }

    /**
     * Which catalogue bot the request claims to be. The User-agent header is
     * matched case-insensitively as a substring, because real crawler headers
     * wrap the product token in a browser-style string.
     */
    private function resolveBot(SignedRequest $request): ?BotDefinition
    {
        $userAgent = strtolower((string) $request->header('user-agent'));
        if ($userAgent === '') {
            return null;
        }

        $match = null;
        foreach ($this->botRegistry->all() as $bot) {
            $token = strtolower($bot->userAgent);
            if ($token !== '' && str_contains($userAgent, $token)) {
                // Prefer the longest matching token: "Claude-SearchBot" must
                // not lose to a shorter token that is a substring of it.
                if ($match === null || strlen($token) > strlen($match->userAgent)) {
                    $match = $bot;
                }
            }
        }

        return $match;
    }

    /**
     * Signature first: a cryptographic proof beats a range list, and it is
     * the only rail that survives a proxied request.
     *
     * @return BotVerifierInterface[]
     */
    private function orderedVerifiers(): array
    {
        $ordered = [];

        foreach ([BotDefinition::VERIFY_WEB_BOT_AUTH, BotDefinition::VERIFY_IP_RANGE] as $method) {
            foreach ($this->verifiers as $verifier) {
                if ($verifier instanceof BotVerifierInterface && $verifier->getMethod() === $method) {
                    $ordered[] = $verifier;
                }
            }
        }

        return $ordered;
    }
}
