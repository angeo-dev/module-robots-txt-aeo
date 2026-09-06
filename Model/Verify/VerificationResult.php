<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Verify;

/**
 * Outcome of one identity check for a claimed bot.
 *
 * The states are deliberately more than a boolean. "We could not reach the
 * key directory" is not the same claim as "this signature is forged", and a
 * merchant acting on the difference deserves to be told which one happened.
 *
 * @since 4.0.0
 */
final class VerificationResult
{
    /** Proof succeeded — the request really is from the vendor. */
    public const STATE_VERIFIED = 'verified';

    /** Proof failed — signature does not verify, or the address is not theirs. */
    public const STATE_FAILED = 'failed';

    /** Could not be decided: endpoint unreachable, key unknown, no rail. */
    public const STATE_UNKNOWN = 'unknown';

    /** The bot has no verification rail published by its vendor at all. */
    public const STATE_UNSUPPORTED = 'unsupported';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $state,
        public readonly string $method,
        public readonly string $message = '',
        public readonly ?string $botUserAgent = null,
        public readonly array $details = [],
    ) {}

    public static function verified(string $method, string $message, ?string $bot = null, array $details = []): self
    {
        return new self(self::STATE_VERIFIED, $method, $message, $bot, $details);
    }

    public static function failed(string $method, string $message, ?string $bot = null, array $details = []): self
    {
        return new self(self::STATE_FAILED, $method, $message, $bot, $details);
    }

    public static function unknown(string $method, string $message, ?string $bot = null, array $details = []): self
    {
        return new self(self::STATE_UNKNOWN, $method, $message, $bot, $details);
    }

    public static function unsupported(string $method, string $message, ?string $bot = null): self
    {
        return new self(self::STATE_UNSUPPORTED, $method, $message, $bot);
    }

    public function isVerified(): bool
    {
        return $this->state === self::STATE_VERIFIED;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state'      => $this->state,
            'method'     => $this->method,
            'message'    => $this->message,
            'bot'        => $this->botUserAgent,
            'details'    => $this->details,
        ];
    }
}
