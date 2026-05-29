<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Block\Adminhtml;

use Angeo\RobotsTxtAeo\Model\Bot\BotDefinition;
use Angeo\RobotsTxtAeo\Model\Config as ModuleConfig;
use Angeo\RobotsTxtAeo\Model\UrlFetcher;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

class Dashboard extends Template
{
    protected $_template = 'Angeo_RobotsTxtAeo::dashboard.phtml';

    public function __construct(
        Context $context,
        private readonly ModuleConfig $moduleConfig,
        private readonly UrlFetcher   $urlFetcher,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getValidateUrl(): string
    {
        return $this->getUrl('angeo_robots/robots/validate');
    }

    public function getPreviewUrl(): string
    {
        return $this->getUrl('angeo_robots/robots/preview');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'angeo_robots_txt_aeo']);
    }

    public function isModuleEnabled(): bool
    {
        return $this->moduleConfig->isEnabled();
    }

    public function getMode(): string
    {
        return $this->moduleConfig->getMode();
    }

    /**
     * @return array<string, BotDefinition>
     */
    public function getEnabledBots(): array
    {
        return $this->moduleConfig->getEnabledBots();
    }

    /**
     * @return array<string, BotDefinition>
     */
    public function getAllBots(): array
    {
        return $this->moduleConfig->getAllBots();
    }

    public function getRobotsUrl(): string
    {
        try {
            $storeId = (int) $this->_storeManager->getDefaultStoreView()?->getId();
        } catch (\Throwable) {
            $storeId = null;
        }
        return $this->urlFetcher->getRobotsUrl($storeId);
    }
}
