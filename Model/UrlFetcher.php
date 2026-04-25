<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Shared HTTP utility used by admin controllers to fetch and derive URLs.
 *
 * Extracted to eliminate the identical getBaseUrl() / fetchUrl() duplication
 * that existed in Preview and Validate controllers.
 */
class UrlFetcher
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function getBaseUrl(): string
    {
        return rtrim(
            (string) ($this->scopeConfig->getValue('web/secure/base_url')
                ?: $this->scopeConfig->getValue('web/unsecure/base_url')),
            '/'
        );
    }

    public function fetchUrl(string $url): string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'       => 10,
                'ignore_errors' => true,
                'user_agent'    => 'Angeo-Admin/1.0',
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        return (string) @file_get_contents($url, false, $ctx);
    }
}
