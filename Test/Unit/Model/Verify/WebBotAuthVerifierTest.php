<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\Verify\JwksDirectory;
use Angeo\RobotsTxtAeo\Model\Verify\SignatureBaseBuilder;
use Angeo\RobotsTxtAeo\Model\Verify\SignatureInputParser;
use Angeo\RobotsTxtAeo\Model\Verify\SignedRequest;
use Angeo\RobotsTxtAeo\Model\Verify\VerificationResult;
use Angeo\RobotsTxtAeo\Model\Verify\WebBotAuthVerifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end signature checks against a real Ed25519 keypair — no mocking of
 * the cryptography, only of the network hop that fetches the key directory.
 *
 * @covers \Angeo\RobotsTxtAeo\Model\Verify\WebBotAuthVerifier
 */
class WebBotAuthVerifierTest extends TestCase
{
    private const ORIGIN    = 'https://chatgpt.com';
    private const AUTHORITY = 'shop.example';
    private const PATH      = '/product.html';

    private string $secretKey;
    private string $publicKey;

    private JwksDirectory&MockObject $directory;
    private Config&MockObject        $config;
    private WebBotAuthVerifier       $verifier;

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('ext-sodium is required for Web Bot Auth verification.');
        }

        $keypair         = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKey = sodium_crypto_sign_publickey($keypair);

        $this->directory = $this->createMock(JwksDirectory::class);
        $this->directory->method('directoryUrl')
            ->willReturnCallback(static fn(string $origin): string => $origin . JwksDirectory::WELL_KNOWN_PATH);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getTrustedSignatureAgents')->willReturn([]);

        $this->verifier = new WebBotAuthVerifier(
            new SignatureInputParser(),
            new SignatureBaseBuilder(),
            $this->directory,
            $this->config,
            new NullLogger()
        );
    }

    public function testVerifiesAGenuineSignature(): void
    {
        $this->directory->method('getKeys')->willReturn(['test-key' => $this->publicKey]);

        $result = $this->verifier->verify($this->bot(), $this->signedRequest());

        $this->assertSame(VerificationResult::STATE_VERIFIED, $result->state);
        $this->assertSame(BotDefinition::VERIFY_WEB_BOT_AUTH, $result->method);
    }

    public function testRejectsASignatureMadeWithAnotherKey(): void
    {
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        $this->directory->method('getKeys')->willReturn(['test-key' => $other]);

        $result = $this->verifier->verify($this->bot(), $this->signedRequest());

        $this->assertSame(VerificationResult::STATE_FAILED, $result->state);
    }

    public function testRejectsAReplayAgainstADifferentHost(): void
    {
        $this->directory->method('getKeys')->willReturn(['test-key' => $this->publicKey]);

        // Signature was made for shop.example; the request arrives at another
        // shop. @authority is covered, so the base no longer matches.
        $request = $this->signedRequest(authority: 'other-shop.example');

        $this->assertSame(VerificationResult::STATE_FAILED, $this->verifier->verify($this->bot(), $request)->state);
    }

    public function testRejectsAnUntrustedSigningOrigin(): void
    {
        $this->directory->expects($this->never())->method('getKeys');

        $request = $this->signedRequest(agent: 'https://attacker.example');
        $result  = $this->verifier->verify($this->bot(), $request);

        $this->assertSame(VerificationResult::STATE_FAILED, $result->state);
        $this->assertStringContainsString('not a trusted signing origin', $result->message);
    }

    public function testAcceptsAnOriginAddedByTheOperator(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getTrustedSignatureAgents')->willReturn(['https://newbot.example']);

        $directory = $this->createMock(JwksDirectory::class);
        $directory->method('directoryUrl')
            ->willReturnCallback(static fn(string $o): string => $o . JwksDirectory::WELL_KNOWN_PATH);
        $directory->method('getKeys')->willReturn(['test-key' => $this->publicKey]);

        $verifier = new WebBotAuthVerifier(
            new SignatureInputParser(),
            new SignatureBaseBuilder(),
            $directory,
            $config,
            new NullLogger()
        );

        $result = $verifier->verify($this->bot(), $this->signedRequest(agent: 'https://newbot.example'));

        $this->assertSame(VerificationResult::STATE_VERIFIED, $result->state);
    }

    public function testExpiredSignatureFails(): void
    {
        $this->directory->method('getKeys')->willReturn(['test-key' => $this->publicKey]);

        $created = time() - 7200;
        $result  = $this->verifier->verify(
            $this->bot(),
            $this->signedRequest(created: $created, expires: $created + 600)
        );

        $this->assertSame(VerificationResult::STATE_FAILED, $result->state);
        $this->assertStringContainsString('expired', $result->message);
    }

    public function testUnreachableDirectoryIsUnknownNotFailed(): void
    {
        // "We could not check" must never be reported as "this is forged".
        $this->directory->method('getKeys')->willReturn(null);

        $result = $this->verifier->verify($this->bot(), $this->signedRequest());

        $this->assertSame(VerificationResult::STATE_UNKNOWN, $result->state);
    }

    public function testUnsignedRequestIsUnsupported(): void
    {
        $request = new SignedRequest('GET', 'https', self::AUTHORITY, self::PATH, []);

        $this->assertSame(
            VerificationResult::STATE_UNSUPPORTED,
            $this->verifier->verify($this->bot(), $request)->state
        );
    }

    public function testSignatureThatDoesNotCoverTheAgentIsRejected(): void
    {
        $this->directory->method('getKeys')->willReturn(['test-key' => $this->publicKey]);

        $created    = time();
        $definition = '("@authority" "@method" "@path");created=' . $created
            . ';keyid="test-key";expires=' . ($created + 600) . ';tag="web-bot-auth";alg="ed25519"';

        $base = '"@authority": ' . self::AUTHORITY . "\n"
            . '"@method": GET' . "\n"
            . '"@path": ' . self::PATH . "\n"
            . '"@signature-params": ' . $definition;

        $request = new SignedRequest('GET', 'https', self::AUTHORITY, self::PATH, [
            'Signature-Agent' => '"' . self::ORIGIN . '"',
            'Signature-Input' => 'sig1=' . $definition,
            'Signature'       => 'sig1=:' . base64_encode(sodium_crypto_sign_detached($base, $this->secretKey)) . ':',
        ]);

        $result = $this->verifier->verify($this->bot(), $request);

        $this->assertSame(VerificationResult::STATE_FAILED, $result->state);
        $this->assertStringContainsString('signature-agent', $result->message);
    }

    public function testSupportsOnlyBotsWithATrustedOrigin(): void
    {
        $this->assertTrue($this->verifier->supports($this->bot()));
        $this->assertFalse($this->verifier->supports(
            BotDefinition::fromArray('claudebot', ['user_agent' => 'ClaudeBot'])
        ));
    }

    private function bot(): BotDefinition
    {
        return BotDefinition::fromArray('chatgpt_user', [
            'user_agent'       => 'ChatGPT-User',
            'signature_agents' => [self::ORIGIN],
        ]);
    }

    private function signedRequest(
        string $agent = self::ORIGIN,
        string $authority = self::AUTHORITY,
        ?int $created = null,
        ?int $expires = null,
    ): SignedRequest {
        $created ??= time();
        $expires ??= $created + 600;

        $definition = '("@authority" "@method" "@path" "signature-agent");created=' . $created
            . ';keyid="test-key";expires=' . $expires . ';tag="web-bot-auth";alg="ed25519"';

        // Signed for self::AUTHORITY on purpose: the replay test changes the
        // authority of the delivered request, not of the signed base.
        $base = '"@authority": ' . self::AUTHORITY . "\n"
            . '"@method": GET' . "\n"
            . '"@path": ' . self::PATH . "\n"
            . '"signature-agent": "' . $agent . '"' . "\n"
            . '"@signature-params": ' . $definition;

        $signature = sodium_crypto_sign_detached($base, $this->secretKey);

        return new SignedRequest('GET', 'https', $authority, self::PATH, [
            'User-Agent'      => 'Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)',
            'Signature-Agent' => '"' . $agent . '"',
            'Signature-Input' => 'sig1=' . $definition,
            'Signature'       => 'sig1=:' . base64_encode($signature) . ':',
        ]);
    }
}
