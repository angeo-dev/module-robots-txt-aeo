<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;

/**
 * One way of proving that a request claiming to be a given bot really is.
 *
 * @since 4.0.0
 */
interface BotVerifierInterface
{
    /**
     * The verification method this verifier implements — one of the
     * BotDefinition::VERIFY_* constants.
     */
    public function getMethod(): string;

    /**
     * Whether this verifier can say anything at all about the given bot.
     */
    public function supports(BotDefinition $bot): bool;

    /**
     * Check the request against this bot's published identity rail.
     */
    public function verify(BotDefinition $bot, SignedRequest $request): VerificationResult;
}
