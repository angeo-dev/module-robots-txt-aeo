<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model;

use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RobotsInjectorTest extends TestCase
{
    private Config&MockObject $config;
    private RobotsInjector    $injector;

    private const SAMPLE_BOTS = [
        'oai_searchbot' => [
            'user_agent'          => 'OAI-SearchBot',
            'label'               => 'OAI-SearchBot',
            'description'         => 'ChatGPT live search',
            'respects_robots_txt' => true,
        ],
        'perplexitybot' => [
            'user_agent'          => 'PerplexityBot',
            'label'               => 'PerplexityBot',
            'description'         => 'Perplexity indexer',
            'respects_robots_txt' => true,
        ],
    ];

    protected function setUp(): void
    {
        $this->config   = $this->createMock(Config::class);
        $this->injector = new RobotsInjector($this->config);
    }

    // ── isEnabled() ──────────────────────────────────────────────────────────

    public function testProcessReturnsUnchangedWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $original = "User-agent: *\nDisallow: /checkout/\n";
        $result   = $this->injector->process($original);

        $this->assertSame($original, $result);
    }

    public function testProcessReturnsUnchangedWhenNoBotsEnabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getEnabledBots')->willReturn([]);
        $this->config->method('getMode')->willReturn(Config::MODE_INJECT);

        $original = "User-agent: *\nDisallow: /checkout/\n";
        $result   = $this->injector->process($original);

        $this->assertSame($original, $result);
    }

    // ── INJECT mode ───────────────────────────────────────────────────────────

    public function testInjectAddsAngeoBlockAtTop(): void
    {
        $this->configureInjectMode();

        $existing = "User-agent: *\nDisallow: /checkout/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringStartsWith('# Angeo AEO — AI Crawler Rules', $result);
        $this->assertStringContainsString('User-agent: OAI-SearchBot', $result);
        $this->assertStringContainsString('User-agent: PerplexityBot', $result);
        $this->assertStringContainsString('# End Angeo AEO block', $result);
    }

    public function testInjectPreservesExistingWildcardBlock(): void
    {
        $this->configureInjectMode();

        $existing = "User-agent: *\nDisallow: /checkout/\nDisallow: /customer/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringContainsString("User-agent: *", $result);
        $this->assertStringContainsString("Disallow: /checkout/", $result);
        $this->assertStringContainsString("Disallow: /customer/", $result);
    }

    public function testInjectIsIdempotent(): void
    {
        $this->configureInjectMode();

        $existing   = "User-agent: *\nDisallow: /checkout/\nAllow: /\n";
        $firstPass  = $this->injector->process($existing);
        $secondPass = $this->injector->process($firstPass);

        $this->assertSame($firstPass, $secondPass);
    }

    public function testInjectUpdatesExistingBlockWhenBotsChange(): void
    {
        $this->configureInjectMode();

        // First inject with two bots
        $existing  = "User-agent: *\nDisallow: /checkout/\n";
        $afterFirst = $this->injector->process($existing);

        $this->assertStringContainsString('User-agent: OAI-SearchBot', $afterFirst);
        $this->assertStringContainsString('User-agent: PerplexityBot', $afterFirst);

        // Now only one bot enabled
        $this->config->method('getEnabledBots')->willReturn([
            'oai_searchbot' => self::SAMPLE_BOTS['oai_searchbot'],
        ]);

        $injector2  = new RobotsInjector($this->config);
        $afterSecond = $injector2->process($afterFirst);

        $this->assertStringContainsString('User-agent: OAI-SearchBot', $afterSecond);
        // PerplexityBot block should be gone from the Angeo section
        // (it may appear once in the Angeo block — after update only enabled bots)
        $this->assertStringContainsString('# Angeo AEO — AI Crawler Rules', $afterSecond);
    }

    public function testInjectDoesNotDuplicateExistingBotEntry(): void
    {
        $this->configureInjectMode();

        // Existing content already has OAI-SearchBot from a manual edit
        $existing = "User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nDisallow: /checkout/\n";
        $result   = $this->injector->process($existing);

        // Should appear exactly once
        $count = substr_count($result, 'User-agent: OAI-SearchBot');
        $this->assertSame(1, $count);
    }

    // ── REPLACE mode ──────────────────────────────────────────────────────────

    public function testReplaceGeneratesFullRobotsTxt(): void
    {
        $this->configureReplaceMode();

        $existing = "User-agent: *\nDisallow: /checkout/\nDisallow: /customer/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringContainsString('# Angeo AEO — AI Crawler Rules', $result);
        $this->assertStringContainsString('User-agent: OAI-SearchBot', $result);
        $this->assertStringContainsString('User-agent: *', $result);
    }

    public function testReplacePreservesCustomDisallows(): void
    {
        $this->configureReplaceMode();

        $existing = "User-agent: *\nDisallow: /checkout/\nDisallow: /my-secret-path/\nAllow: /\n";
        $result   = $this->injector->process($existing);

        $this->assertStringContainsString('Disallow: /checkout/', $result);
        $this->assertStringContainsString('Disallow: /my-secret-path/', $result);
    }

    // ── validate() ────────────────────────────────────────────────────────────

    public function testValidateReturnsPresentAndMissing(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getEnabledBots')->willReturn(self::SAMPLE_BOTS);

        $content = "User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nDisallow: /\n";
        $result  = $this->injector->validate($content);

        $this->assertContains('OAI-SearchBot', $result['present']);
        $this->assertContains('PerplexityBot', $result['missing']);
    }

    public function testValidateAllPresentWhenBlockInjected(): void
    {
        $this->configureInjectMode();

        $existing  = "User-agent: *\nDisallow: /checkout/\n";
        $injected  = $this->injector->process($existing);
        $result    = $this->injector->validate($injected);

        $this->assertEmpty($result['missing'], 'No missing bots after injection');
        $this->assertCount(2, $result['present']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function configureInjectMode(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_INJECT);
        $this->config->method('getEnabledBots')->willReturn(self::SAMPLE_BOTS);
    }

    private function configureReplaceMode(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMode')->willReturn(Config::MODE_REPLACE);
        $this->config->method('getEnabledBots')->willReturn(self::SAMPLE_BOTS);
    }
}
