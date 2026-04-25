<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Mode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'inject',  'label' => __('Inject (recommended) — prepend AI rules, preserve existing content')],
            ['value' => 'replace', 'label' => __('Replace — generate full robots.txt (preserves Disallow rules)')],
        ];
    }
}
