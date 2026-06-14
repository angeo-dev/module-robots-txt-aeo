<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Source;

use Angeo\RobotsTxtAeo\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Tri-state option source for Cloudflare Content Signals:
 * yes | no | unset ("no expressed preference" per the policy — the signal is
 * simply omitted from the emitted Content-Signal line).
 *
 * @since 3.0.0
 */
class SignalState implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::SIGNAL_UNSET, 'label' => __('Unset (no expressed preference)')],
            ['value' => Config::SIGNAL_YES,   'label' => __('Yes (allowed)')],
            ['value' => Config::SIGNAL_NO,    'label' => __('No (not allowed)')],
        ];
    }
}
