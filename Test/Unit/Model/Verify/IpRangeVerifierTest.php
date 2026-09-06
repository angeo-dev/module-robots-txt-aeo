<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\FetchResult;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Angeo\RobotsTxtAeo\Model\Verify\CidrMatcher;
use Angeo\RobotsTxtAeo\Model\Verify\IpRangeVerifier;
use Angeo\RobotsTxtAeo\Model\Verify\SignedRequest;
use Angeo\RobotsTxtAeo\Model\Verify\VerificationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\RobotsTxtAeo\Model\Verify\IpRangeVerifier
 */
class IpRangeVerifierTest extends TestCase
{
    /** The shape OpenAI, Perplexity and Anthropic all publish. */
    private const PAYLOAD = '{"creationTime":"2026-08-18T23:56:36Z","prefixes":['
        . '{"ipv4Prefix":"216.73.216.0/22"},{"ipv4Prefix":"34.162.230.222/32"},'
        . '{"ipv6Prefix":"2600:1f1c::/32"}]}';

    private UrlFetcher&MockObject $urlFetcher;
    private IpRangeVerifier       $verifier;

    protected function setUp(): void
    {
        $this->urlFetcher = $this->createMock(UrlFetcher::class);
        $this->verifier   = new IpRangeVerifier($this->urlFetcher, new CidrMatcher());
    }

    public function testAddressInsideAPublishedRangeVerifies(): void
    {
        $this->respondWith(self::PAYLOAD);

        $result = $this->verifier->verifyAddress($this->anthropicBot(), '216.73.216.9');

        $this->assertSame(VerificationResult::STATE_VERIFIED, $result->state);
        $this->assertSame('216.73.216.0/22', $result->details['range']);
    }

    public function testSharedListReportsTheOperatorNotTheBot(): void
    {
        // Anthropic publishes one list for ClaudeBot, Claude-User and
        // Claude-SearchBot. A match proves Anthropic sent it, nothing finer.
        $this->respondWith(self::PAYLOAD);

        $result = $this->verifier->verifyAddress($this->anthropicBot(), '216.73.216.9');

        $this->assertSame('vendor', $result->details['scope']);
        $this->assertStringContainsString('not which', $result->message);
    }

    public function testPerBotListSaysSo(): void
    {
        $this->respondWith(self::PAYLOAD);

        $result = $this->verifier->verifyAddress($this->openAiBot(), '216.73.216.9');

        $this->assertSame('bot', $result->details['scope']);
    }

    public function testIpv6InsideAPublishedRangeVerifies(): void
    {
        $this->respondWith(self::PAYLOAD);

        $this->assertSame(
            VerificationResult::STATE_VERIFIED,
            $this->verifier->verifyAddress($this->anthropicBot(), '2600:1f1c::abcd')->state
        );
    }

    public function testAddressOutsideEveryRangeFails(): void
    {
        $this->respondWith(self::PAYLOAD);

        $this->assertSame(
            VerificationResult::STATE_FAILED,
            $this->verifier->verifyAddress($this->anthropicBot(), '198.51.100.7')->state
        );
    }

    public function testUnreachableListIsUnknownNotFailed(): void
    {
        $this->urlFetcher->method('fetch')
            ->willReturn(FetchResult::failure('https://claude.com/crawling/bots.json', 'timeout'));

        $this->assertSame(
            VerificationResult::STATE_UNKNOWN,
            $this->verifier->verifyAddress($this->anthropicBot(), '216.73.216.9')->state
        );
    }

    public function testMalformedJsonIsUnknown(): void
    {
        $this->respondWith('<html>maintenance</html>');

        $this->assertSame(
            VerificationResult::STATE_UNKNOWN,
            $this->verifier->verifyAddress($this->anthropicBot(), '216.73.216.9')->state
        );
    }

    public function testRequestWithoutASourceAddressIsUnknown(): void
    {
        $request = new SignedRequest('GET', 'https', 'shop.example', '/');

        $this->assertSame(
            VerificationResult::STATE_UNKNOWN,
            $this->verifier->verify($this->anthropicBot(), $request)->state
        );
    }

    public function testBotWithoutAPublishedListIsUnsupported(): void
    {
        $bot = BotDefinition::fromArray('bytespider', ['user_agent' => 'Bytespider']);

        $this->assertFalse($this->verifier->supports($bot));
        $this->assertSame(
            VerificationResult::STATE_UNSUPPORTED,
            $this->verifier->verify($bot, new SignedRequest('GET', 'https', 'shop.example', '/', [], '1.2.3.4'))->state
        );
    }

    private function respondWith(string $body): void
    {
        $this->urlFetcher->method('fetch')
            ->willReturn(FetchResult::success('https://example.test/list.json', $body, 200));
    }

    private function anthropicBot(): BotDefinition
    {
        return BotDefinition::fromArray('claudebot', [
            'user_agent'       => 'ClaudeBot',
            'ip_ranges_url'    => 'https://claude.com/crawling/bots.json',
            'ip_ranges_shared' => true,
        ]);
    }

    private function openAiBot(): BotDefinition
    {
        return BotDefinition::fromArray('gptbot', [
            'user_agent'    => 'GPTBot',
            'ip_ranges_url' => 'https://openai.com/gptbot.json',
        ]);
    }
}
