<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;

/**
 * Verification against the vendor-published IP range list.
 *
 * The weaker of the two rails: it depends on the vendor keeping a JSON file
 * current, it says nothing about a request that arrives through a proxy, and
 * a shared cloud range proves less than a signature. It is still the only
 * rail several vendors publish, and a mismatch is a strong negative signal.
 *
 * Vendors publish these lists in slightly different shapes — a flat array, or
 * {"prefixes":[{"ipv4Prefix":"…"}]} as OpenAI, Perplexity and Anthropic all do.
 * CidrMatcher walks whatever comes back and picks out anything that parses as
 * an address or range, so a vendor reshaping their JSON does not break the
 * check.
 *
 * @since 4.0.0 — extracted from VerifyBotIpCommand into a verifier so the
 *                same check is available to the API, the CLI and the audit
 *                module through one contract.
 */
class IpRangeVerifier implements BotVerifierInterface
{
    public const FETCH_TIMEOUT = 5;

    public function __construct(
        private readonly UrlFetcher  $urlFetcher,
        private readonly CidrMatcher $cidrMatcher,
    ) {}

    public function getMethod(): string
    {
        return BotDefinition::VERIFY_IP_RANGE;
    }

    public function supports(BotDefinition $bot): bool
    {
        return $bot->supportsVerification(BotDefinition::VERIFY_IP_RANGE)
            && $bot->ipRangesUrl !== null
            && $bot->ipRangesUrl !== '';
    }

    public function verify(BotDefinition $bot, SignedRequest $request): VerificationResult
    {
        $method = $this->getMethod();
        $ip     = $request->remoteAddress;

        if ($ip === null || $ip === '' || @inet_pton($ip) === false) {
            return VerificationResult::unknown(
                $method,
                'No source IP address was supplied, so the range check cannot run.',
                $bot->userAgent
            );
        }

        if (!$this->supports($bot)) {
            return VerificationResult::unsupported(
                $method,
                sprintf('%s publishes no IP range list.', $bot->userAgent),
                $bot->userAgent
            );
        }

        return $this->verifyAddress($bot, $ip);
    }

    /**
     * Check a bare address without a request context — used by
     * angeo:robots:verify-bot-ip.
     */
    public function verifyAddress(BotDefinition $bot, string $ip): VerificationResult
    {
        $method = $this->getMethod();
        $url    = (string) $bot->ipRangesUrl;

        $response = $this->urlFetcher->fetch($url, self::FETCH_TIMEOUT, 1);
        if (!$response->isSuccess()) {
            return VerificationResult::unknown(
                $method,
                sprintf('Range list %s could not be retrieved: %s', $url, $response->error),
                $bot->userAgent,
                ['source' => $url]
            );
        }

        $payload = json_decode($response->body, true);
        if (!is_array($payload)) {
            return VerificationResult::unknown(
                $method,
                sprintf('Range list %s did not return valid JSON.', $url),
                $bot->userAgent,
                ['source' => $url]
            );
        }

        foreach ($this->cidrMatcher->extractRanges($payload) as $range) {
            if ($this->cidrMatcher->contains($range, $ip)) {
                // Some vendors publish one list for their whole crawler fleet
                // (Anthropic does). A match then proves the operator, not which
                // of their bots sent the request — say so rather than implying
                // more than the list supports.
                $message = $bot->ipRangesShared
                    ? sprintf(
                        '%s is inside %s. That list is published for the vendor\'s crawlers as a '
                        . 'group, so it proves the request came from the operator of %s — not which '
                        . 'of its bots sent it.',
                        $ip,
                        $range,
                        $bot->userAgent
                    )
                    : sprintf('%s is inside %s, published by the vendor for %s.', $ip, $range, $bot->userAgent);

                return VerificationResult::verified(
                    $method,
                    $message,
                    $bot->userAgent,
                    [
                        'source' => $url,
                        'range'  => $range,
                        'ip'     => $ip,
                        'scope'  => $bot->ipRangesShared ? 'vendor' : 'bot',
                    ]
                );
            }
        }

        return VerificationResult::failed(
            $method,
            sprintf('%s is not in any range published for %s.', $ip, $bot->userAgent),
            $bot->userAgent,
            ['source' => $url, 'ip' => $ip]
        );
    }
}
