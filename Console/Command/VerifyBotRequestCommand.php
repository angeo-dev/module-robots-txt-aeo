<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Console\Command;

use Angeo\RobotsTxtAeo\Api\BotVerificationInterface;
use Angeo\RobotsTxtAeo\Model\Verify\VerificationResult;
use Magento\Framework\Filesystem\Driver\File;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:robots:verify-bot-request
 *
 * Verifies a captured request against the Web Bot Auth signature it carries
 * (RFC 9421 + Signature-Agent key discovery), falling back to the vendor IP
 * ranges when no signature is present.
 *
 *   bin/magento angeo:robots:verify-bot-request \
 *     --headers-file=/tmp/headers.txt \
 *     --authority=example.com --path=/product.html --ip=1.2.3.4
 *
 * The headers file is a plain "Name: value" block, one header per line — what
 * a proxy log or a debug dump gives you. Headers can also be passed inline
 * with repeated --header options.
 *
 * This command answers a question; it does not block anything. Enforcement
 * belongs at the WAF or CDN, where it happens before PHP is reached.
 *
 * @since 4.0.0
 */
class VerifyBotRequestCommand extends Command
{
    public function __construct(
        private readonly BotVerificationInterface $botVerification,
        private readonly File                     $fileDriver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:robots:verify-bot-request')
            ->setDescription('Verify a captured request against its Web Bot Auth signature')
            ->addOption('header', 'H', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Request header as "Name: value" (repeatable)')
            ->addOption('headers-file', 'f', InputOption::VALUE_OPTIONAL,
                'File containing the request headers, one "Name: value" per line')
            ->addOption('authority', 'a', InputOption::VALUE_OPTIONAL,
                'Host the request was addressed to (defaults to the Host header)')
            ->addOption('path', 'p', InputOption::VALUE_OPTIONAL, 'Request path, query string included', '/')
            ->addOption('method', 'm', InputOption::VALUE_OPTIONAL, 'HTTP method', 'GET')
            ->addOption('scheme', 's', InputOption::VALUE_OPTIONAL, 'http or https', 'https')
            ->addOption('ip', 'i', InputOption::VALUE_OPTIONAL, 'Source IP address, when known');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $headers = $this->collectHeaders($input, $output);
        if ($headers === null) {
            return Command::FAILURE;
        }

        if ($headers === []) {
            $output->writeln('<error>No headers supplied. Use --header or --headers-file.</error>');
            return Command::FAILURE;
        }

        $authority = (string) ($input->getOption('authority') ?: ($headers['Host'] ?? $headers['host'] ?? ''));
        if ($authority === '') {
            $output->writeln('<error>No authority. Pass --authority or include a Host header.</error>');
            return Command::FAILURE;
        }

        $result = $this->botVerification->verifyRequest(
            $headers,
            $authority,
            (string) $input->getOption('path'),
            strtoupper((string) $input->getOption('method')),
            strtolower((string) $input->getOption('scheme')),
            $input->getOption('ip') !== null ? (string) $input->getOption('ip') : null
        );

        $state   = (string) ($result['state'] ?? VerificationResult::STATE_UNKNOWN);
        $method  = (string) ($result['method'] ?? '-');
        $bot     = (string) ($result['bot'] ?? 'unidentified');
        $message = (string) ($result['message'] ?? '');

        $output->writeln('');
        $output->writeln(sprintf('Claimed bot: <info>%s</info>', $bot));
        $output->writeln(sprintf('Method:      %s', $method));

        switch ($state) {
            case VerificationResult::STATE_VERIFIED:
                $output->writeln('<info>VERIFIED</info> — ' . $message);
                break;
            case VerificationResult::STATE_FAILED:
                $output->writeln('<error>FAILED</error> — ' . $message);
                break;
            case VerificationResult::STATE_UNSUPPORTED:
                $output->writeln('<comment>UNSUPPORTED</comment> — ' . $message);
                break;
            default:
                $output->writeln('<comment>UNKNOWN</comment> — ' . $message);
        }

        if (!empty($result['details']) && is_array($result['details'])) {
            foreach ($result['details'] as $key => $value) {
                $output->writeln(sprintf(
                    '  %-16s %s',
                    $key . ':',
                    is_scalar($value) ? (string) $value : json_encode($value)
                ));
            }
        }

        $output->writeln('');

        return $state === VerificationResult::STATE_FAILED ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<string, string>|null null on a read error
     */
    private function collectHeaders(InputInterface $input, OutputInterface $output): ?array
    {
        $headers = [];

        $file = $input->getOption('headers-file');
        if ($file !== null && $file !== '') {
            try {
                $contents = (string) $this->fileDriver->fileGetContents((string) $file);
            } catch (\Throwable $e) {
                $output->writeln('<error>Could not read ' . $file . ': ' . $e->getMessage() . '</error>');
                return null;
            }

            foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
                $this->addHeader($headers, $line);
            }
        }

        foreach ((array) $input->getOption('header') as $line) {
            $this->addHeader($headers, (string) $line);
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function addHeader(array &$headers, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $colon = strpos($line, ':');
        if ($colon === false || $colon === 0) {
            return;
        }

        $name  = trim(substr($line, 0, $colon));
        $value = trim(substr($line, $colon + 1));

        if ($name !== '') {
            $headers[$name] = $value;
        }
    }
}
