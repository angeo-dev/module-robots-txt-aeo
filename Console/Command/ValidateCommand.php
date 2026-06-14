<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Console\Command;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento angeo:robots:validate
 *
 * Fetches the live robots.txt from the store URL and validates
 * that all enabled AI bot entries are present.
 */
class ValidateCommand extends Command
{
    public function __construct(
        private readonly RobotsInjector $injector,
        private readonly Config         $config,
        private readonly UrlFetcher     $urlFetcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:robots:validate')
            ->setDescription('Validate that AI crawler rules are present in the live robots.txt')
            ->addOption('url', 'u', InputOption::VALUE_OPTIONAL,
                'Store URL to fetch robots.txt from (default: base URL from store)')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL,
                'Store ID for multi-store installations')
            ->addOption('insecure', null, InputOption::VALUE_NONE,
                'Disable TLS verification (dev / self-signed certs only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>Angeo Robots.txt AEO — Validator</info>');
        $output->writeln(str_repeat('─', 55));

        $storeId = $input->getOption('store') !== null ? (int) $input->getOption('store') : null;

        if (!$this->config->isEnabled($storeId)) {
            $output->writeln('<comment>Module is disabled for this scope. Enable in Stores → Config → Angeo → Robots.txt AEO.</comment>');
            return Command::SUCCESS;
        }

        $url = $input->getOption('url') ?: $this->urlFetcher->getRobotsUrl($storeId);
        $output->writeln('Fetching: <comment>' . $url . '</comment>');
        $output->writeln('');

        $response = $this->urlFetcher->fetch(
            $url,
            UrlFetcher::DEFAULT_TIMEOUT,
            UrlFetcher::DEFAULT_RETRIES,
            (bool) $input->getOption('insecure')
        );

        if (!$response->isSuccess()) {
            $output->writeln('<error>Could not fetch robots.txt: ' . $response->error . '</error>');
            $output->writeln('Try passing --url=https://yourstore.com or --insecure for local dev.');
            return Command::FAILURE;
        }

        $result      = $this->injector->validate($response->body, $storeId);
        $enabledBots = $this->config->getEnabledBots($storeId);
        $allBots     = $this->config->getAllBots();

        $hasMissing = !empty($result['missing']);

        foreach ($allBots as $key => $bot) {
            /** @var BotDefinition $bot */
            $ua      = $bot->userAgent;
            $enabled = array_key_exists($key, $enabledBots);

            if (!$enabled) {
                $output->writeln(sprintf(
                    '  <comment>─ SKIP </comment> %-22s <fg=gray>%s (disabled in config)</>',
                    $ua,
                    $bot->description
                ));
                continue;
            }

            $effective   = $result['effective'][$ua] ?? null;
            $allowedRoot = $effective['allowed'] ?? null;

            if (in_array($ua, $result['present'], true) && $allowedRoot !== false) {
                $output->writeln(sprintf(
                    '  <info>✓ PASS </info> %-22s <fg=gray>%s</>',
                    $ua,
                    $bot->description
                ));
            } elseif ($allowedRoot === false) {
                // v3.0.0: present-but-blocked — the merged RFC 9309 rules deny "/".
                $output->writeln(sprintf(
                    '  <error>✗ FAIL </error> %-22s <fg=gray>root blocked by "%s"</>',
                    $ua,
                    (string) ($effective['matched_rule'] ?? 'unknown rule')
                ));
                $hasMissing = true;
            } else {
                $output->writeln(sprintf(
                    '  <error>✗ FAIL </error> %-22s <fg=gray>%s</>',
                    $ua,
                    $bot->description
                ));
            }
        }

        foreach ($result['warnings'] ?? [] as $warning) {
            $output->writeln('  <comment>⚠ ' . $warning . '</comment>');
        }

        $output->writeln('');

        if ($hasMissing) {
            $output->writeln('<error>Missing bot entries detected.</error>');
            $output->writeln('');
            $output->writeln('If the module is enabled and configured, check that:');
            $output->writeln('  1. <comment>bin/magento cache:flush</comment> was run after config changes');
            $output->writeln('  2. A CDN/Fastly is not serving a cached robots.txt — purge CDN cache');
            $output->writeln('  3. Run <comment>bin/magento angeo:robots:preview</comment> to see the expected output');
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln('<info>All enabled AI bot rules are present in robots.txt.</info>');
        $output->writeln('');
        return Command::SUCCESS;
    }
}
