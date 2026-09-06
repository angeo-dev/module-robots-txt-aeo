<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Source;

use Angeo\RobotsTxtAeo\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Where Content-Signal / Content-Usage lines are written.
 *
 * @since 4.0.0
 */
class SignalPlacement implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::PLACEMENT_WILDCARD,
                'label' => __('Wildcard group (recommended) — one site-wide statement'),
            ],
            [
                'value' => Config::PLACEMENT_PER_BOT,
                'label' => __('Every managed bot group — repeats the line per crawler'),
            ],
        ];
    }
}
