<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Console\Command;

use Angeo\RobotsTxtAeo\Model\Bot\BotRegistry;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Angeo\RobotsTxtAeo\Model\Verify\CidrMatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:robots:verify-bot-ip <ip>
 *
 * Checks an IP address against the vendor-PUBLISHED IP range endpoints in
 * the bot catalogue (OpenAI: openai.com/{gptbot,searchbot,chatgpt-user}.json;
 * Perplexity: perplexity.com/{perplexitybot,perplexity-user}.json) and
 * reports which bot, if any, the address belongs to.
 *
 * Use case: a request claims "GPTBot" in its User-Agent header — UA strings
 * are trivially spoofed, the published IP ranges are the vendor-documented
 * verification rail. CLI-only by design: it performs one outbound HTTPS
 * fetch per catalogue entry with a published endpoint.
 *
 * @since 3.0.0
 */
class VerifyBotIpCommand extends Command
{
    public function __construct(
        private readonly BotRegistry $botRegistry,
        private readonly UrlFetcher  $urlFetcher,
        private readonly CidrMatcher $cidrMatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:robots:verify-bot-ip')
            ->setDescription('Verify an IP address against vendor-published AI bot IP ranges')
            ->addArgument('ip', InputArgument::REQUIRED, 'IPv4 or IPv6 address to verify');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ip = trim((string) $input->getArgument('ip'));

        if (@inet_pton($ip) === false) {
            $output->writeln('<error>"' . $ip . '" is not a valid IPv4/IPv6 address.</error>');
            return Command::FAILURE;
        }

        $matches = [];
        $checked = 0;
        $failed  = [];

        foreach ($this->botRegistry->all() as $bot) {
            if ($bot->ipRangesUrl === null || $bot->ipRangesUrl === '') {
                continue;
            }
            $checked++;

            $result = $this->urlFetcher->fetch($bot->ipRangesUrl);
            if (!$result->isSuccess()) {
                $failed[] = sprintf('%s (%s): %s', $bot->userAgent, $bot->ipRangesUrl, $result->error);
                continue;
            }

            $payload = json_decode($result->body, true);
            if (!is_array($payload)) {
                $failed[] = sprintf('%s: endpoint did not return valid JSON', $bot->userAgent);
                continue;
            }

            foreach ($this->cidrMatcher->extractRanges($payload) as $range) {
                if ($this->cidrMatcher->contains($range, $ip)) {
                    $matches[] = ['bot' => $bot->userAgent, 'range' => $range];
                    break;
                }
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('IP <info>%s</info> — checked %d vendor-published range list(s).', $ip, $checked));

        if (!empty($matches)) {
            foreach ($matches as $match) {
                $output->writeln(sprintf(
                    '  <info>✓ MATCH</info>  %s  (range %s)',
                    $match['bot'],
                    $match['range']
                ));
            }
        } else {
            $output->writeln('  <comment>✗ No match — the address is not in any published AI bot range.</comment>');
            $output->writeln('  A request from this IP claiming an AI bot User-Agent is likely spoofed.');
        }

        foreach ($failed as $failure) {
            $output->writeln('  <comment>! Could not check ' . $failure . '</comment>');
        }
        $output->writeln('');

        return Command::SUCCESS;
    }
}
