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
 * bin/magento angeo:robots:preview
 *
 * Shows what robots.txt will look like after injection,
 * without making any changes. Safe to run at any time.
 */
class PreviewCommand extends Command
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
        $this->setName('angeo:robots:preview')
            ->setDescription('Preview the final robots.txt output after AI rule injection (no changes made)')
            ->addOption(
                'url',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Store URL to fetch current robots.txt from (default: base URL from config)'
            )
            ->addOption(
                'diff',
                'd',
                InputOption::VALUE_NONE,
                'Show only the lines that will be added (diff view)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<info>Angeo Robots.txt AEO — Preview</info>');
        $output->writeln(str_repeat('─', 55));

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
        $output->writeln('Source: <comment>' . $robotsUrl . '</comment>');
        $output->writeln('Mode:   <comment>' . $this->config->getMode() . '</comment>');
        $output->writeln('');

        // Fetch current content
        $current = @file_get_contents($robotsUrl);
        if ($current === false) {
            $output->writeln('<comment>Could not fetch live robots.txt — using empty string as base.</comment>');
            $current = '';
        }

        $preview = $this->injector->preview($current);

        if ($input->getOption('diff')) {
            $this->showDiff($output, $current, $preview);
        } else {
            $output->writeln('<comment>─── robots.txt (after injection) ───</comment>');
            $output->writeln('');
            $output->writeln($preview);
        }

        $output->writeln('');
        $output->writeln('<fg=gray>This is a preview only. No changes have been made.</>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function showDiff(OutputInterface $output, string $before, string $after): void
    {
        $beforeLines = explode("\n", $before);
        $afterLines  = explode("\n", $after);

        $added   = array_diff($afterLines, $beforeLines);
        $removed = array_diff($beforeLines, $afterLines);

        $output->writeln('<comment>─── Diff (lines to be added / removed) ───</comment>');
        $output->writeln('');

        foreach ($added as $line) {
            if (trim($line) !== '') {
                $output->writeln('<info>+ ' . $line . '</info>');
            }
        }

        foreach ($removed as $line) {
            if (trim($line) !== '') {
                $output->writeln('<e>- ' . $line . '</e>');
            }
        }
    }
}
