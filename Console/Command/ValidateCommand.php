<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Console\Command;

use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Magento\Framework\App\Config\ScopeConfigInterface;
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
        private readonly RobotsInjector       $injector,
        private readonly Config               $config,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:robots:validate')
            ->setDescription('Validate that AI crawler rules are present in the live robots.txt')
            ->addOption(
                'url',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Store URL to fetch robots.txt from (default: base URL from config)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>Angeo Robots.txt AEO — Validator</info>');
        $output->writeln(str_repeat('─', 55));

        if (!$this->config->isEnabled()) {
            $output->writeln('<comment>Module is disabled. Enable in Stores → Config → Angeo → Robots.txt AEO.</comment>');
            return Command::SUCCESS;
        }

        // Resolve URL
        $url = $input->getOption('url');
        if (!$url) {
            $url = rtrim(
                (string) $this->scopeConfig->getValue('web/secure/base_url')
                    ?: (string) $this->scopeConfig->getValue('web/unsecure/base_url'),
                '/'
            );
        }

        $robotsUrl = rtrim($url, '/') . '/robots.txt';
        $output->writeln('Fetching: <comment>' . $robotsUrl . '</comment>');
        $output->writeln('');

        // Fetch robots.txt
        $content = @file_get_contents($robotsUrl);
        if ($content === false) {
            $output->writeln('<error>Could not fetch robots.txt from ' . $robotsUrl . '</error>');
            $output->writeln('Try passing --url=https://yourstore.com');
            return Command::FAILURE;
        }

        // Validate
        $result  = $this->injector->validate($content);
        $allBots = $this->config->getAllBots();

        $hasMissing = !empty($result['missing']);

        foreach ($allBots as $key => $bot) {
            $ua      = $bot['user_agent'];
            $enabled = in_array($ua, array_column($this->config->getEnabledBots(), 'user_agent'));

            if (!$enabled) {
                $output->writeln(sprintf(
                    '  <comment>─ SKIP </comment> %-22s <fg=gray>%s (disabled in config)</>',
                    $ua,
                    $bot['description']
                ));
                continue;
            }

            if (in_array($ua, $result['present'])) {
                $output->writeln(sprintf(
                    '  <info>✓ PASS </info> %-22s <fg=gray>%s</>',
                    $ua,
                    $bot['description']
                ));
            } else {
                $output->writeln(sprintf(
                    '  <error>✗ FAIL </error> %-22s <fg=gray>%s</>',
                    $ua,
                    $bot['description']
                ));
            }
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
