<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Console\Command;

use Angeo\RobotsTxtAeo\Api\BotVerificationInterface;
use Angeo\RobotsTxtAeo\Model\Verify\VerificationResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:robots:verify-bot-ip <ip> [--bot=GPTBot]
 *
 * Checks an address against the vendor-published IP range lists in the bot
 * catalogue and reports which bot, if any, it belongs to.
 *
 * Use case: a request claims "GPTBot" in its User-Agent header. That header is
 * one line of text anyone can send; the published ranges are the vendor's own
 * verification rail. For crawlers that sign their requests, prefer
 * angeo:robots:verify-bot-request — a signature proves more than an address.
 *
 * @since 3.0.0
 * @since 4.0.0 — delegates to the shared verification API instead of doing the
 *                fetching and matching itself.
 */
class VerifyBotIpCommand extends Command
{
    public function __construct(
        private readonly BotVerificationInterface $botVerification,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:robots:verify-bot-ip')
            ->setDescription('Verify an IP address against vendor-published AI bot IP ranges')
            ->addArgument('ip', InputArgument::REQUIRED, 'IPv4 or IPv6 address to verify')
            ->addOption('bot', 'b', InputOption::VALUE_OPTIONAL, 'Check one product token only, e.g. GPTBot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ip  = trim((string) $input->getArgument('ip'));
        $bot = $input->getOption('bot');

        if (@inet_pton($ip) === false) {
            $output->writeln('<error>"' . $ip . '" is not a valid IPv4/IPv6 address.</error>');
            return Command::FAILURE;
        }

        $results = $this->botVerification->verifyIp($ip, $bot !== null ? (string) $bot : null);

        $output->writeln('');
        $output->writeln(sprintf(
            'IP <info>%s</info> — checked %d vendor-published range list(s).',
            $ip,
            count($results)
        ));

        $matched = false;

        foreach ($results as $result) {
            $state   = (string) ($result['state'] ?? VerificationResult::STATE_UNKNOWN);
            $label   = (string) ($result['bot'] ?? 'unknown bot');
            $message = (string) ($result['message'] ?? '');

            if ($state === VerificationResult::STATE_VERIFIED) {
                $matched = true;
                $output->writeln(sprintf('  <info>MATCH</info>   %s — %s', $label, $message));
            } elseif ($state === VerificationResult::STATE_UNKNOWN) {
                $output->writeln(sprintf('  <comment>UNKNOWN</comment> %s — %s', $label, $message));
            }
        }

        if (!$matched) {
            $output->writeln('  <comment>No match — the address is not in any published AI bot range.</comment>');
            $output->writeln('  A request from this IP claiming an AI bot User-Agent is likely spoofed.');
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
