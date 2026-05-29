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

/**
 * Live preview of the AEO block, embedded in the admin system config page.
 *
 * v2.0.0 — all inline styles moved to view/adminhtml/web/css/angeo-robots-admin.css
 * for CSP compatibility. The CSS is loaded via the adminhtml default_head_blocks
 * layout.
 */
class Preview extends Fieldset
{
    public function __construct(
        Context                         $context,
        Session                         $authSession,
        Js                              $jsHelper,
        private readonly Config         $moduleConfig,
        private readonly RobotsInjector $injector,
        array $data = [],
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data);
    }

    public function render(AbstractElement $element): string
    {
        $enabledBots = $this->moduleConfig->getEnabledBots();

        if (empty($enabledBots)) {
            return $this->renderNote('No AI bots enabled. Enable bots in the "AI Crawlers" section above.');
        }

        $preview = $this->injector->preview('');
        $mode    = $this->moduleConfig->getMode();

        $note = $mode === Config::MODE_INJECT
            ? 'This block will be <strong>prepended</strong> to your existing robots.txt. Your existing rules are preserved.'
            : 'In <strong>Replace</strong> mode the full robots.txt is regenerated. '
              . 'Custom content from the textarea above (or Disallow rules from the live file) are preserved.';

        $html  = '<tr id="row_' . $element->getHtmlId() . '">';
        $html .= '<td colspan="4">';
        $html .= '<div class="angeo-robots-preview">';
        $html .= '<p class="angeo-robots-preview-title">Preview — AI block that will be injected</p>';
        $html .= '<pre class="angeo-robots-preview-pre">' . htmlspecialchars($preview) . '</pre>';
        $html .= '</div>';
        $html .= '<p class="angeo-robots-preview-note">' . $note . '</p>';
        $html .= '<p class="angeo-robots-preview-note">';
        $html .= 'CLI: <code>bin/magento angeo:robots:preview</code> &nbsp;|&nbsp; '
              .  '<code>bin/magento angeo:robots:validate</code>';
        $html .= '</p>';
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private function renderNote(string $message): string
    {
        return '<tr><td colspan="4"><p class="angeo-robots-preview-empty">' . $message . '</p></td></tr>';
    }
}
