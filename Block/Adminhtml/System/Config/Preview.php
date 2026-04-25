<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Block\Adminhtml\System\Config;

use Angeo\RobotsTxtAeo\Model\Config;
use Angeo\RobotsTxtAeo\Model\RobotsInjector;
use Magento\Backend\Block\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\Js;

class Preview extends Fieldset
{
    public function __construct(
        Context                         $context,
        Session                         $authSession,
        Js                              $jsHelper,
        private readonly Config         $moduleConfig,
        private readonly RobotsInjector $injector,
        array $data = []
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data);
    }

    public function render(AbstractElement $element): string
    {
        $enabledBots = $this->moduleConfig->getEnabledBots();

        if (empty($enabledBots)) {
            return $this->renderNote('No AI bots enabled. Enable bots in the "AI Crawlers" section above.');
        }

        // Reuse injector to build the preview block (single source of truth)
        $preview = $this->injector->preview('');
        $mode    = $this->moduleConfig->getMode();

        $note = $mode === Config::MODE_INJECT
            ? 'This block will be <strong>prepended</strong> to your existing robots.txt. Your existing rules are preserved.'
            : 'In <strong>Replace</strong> mode the full robots.txt is regenerated. '
              . 'Custom content from the textarea above (or Disallow rules from the live file) are preserved.';

        $html  = '<tr id="row_' . $element->getHtmlId() . '">';
        $html .= '<td colspan="4">';
        $html .= '<div style="background:#f5f5f5;border:1px solid #ddd;border-radius:4px;padding:16px 20px;margin:8px 0;">';
        $html .= '<p style="margin:0 0 10px;font-weight:600;color:#333;">Preview — AI block that will be injected</p>';
        $html .= '<pre style="margin:0;font-size:12px;line-height:1.8;color:#333;background:none;border:none;padding:0;">'
            . htmlspecialchars($preview) . '</pre>';
        $html .= '</div>';
        $html .= '<p style="color:#666;font-size:12px;margin:6px 0 0;">' . $note . '</p>';
        $html .= '<p style="color:#666;font-size:12px;margin:4px 0 0;">';
        $html .= 'CLI: <code>bin/magento angeo:robots:preview</code> &nbsp;|&nbsp; <code>bin/magento angeo:robots:validate</code>';
        $html .= '</p>';
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderNote(string $message): string
    {
        return '<tr><td colspan="4"><p style="color:#888;padding:8px 0;">' . $message . '</p></td></tr>';
    }
}
