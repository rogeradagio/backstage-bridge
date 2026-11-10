<?php
declare(strict_types=1);

namespace Backstage\Bridge\Model\Config\Source;

use Backstage\Bridge\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

class Mode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::MODE_DISABLED, 'label' => __('Disabled')],
            ['value' => Config::MODE_DRY_RUN, 'label' => __('Dry run (validate only)')],
            ['value' => Config::MODE_LIVE, 'label' => __('Live')],
        ];
    }
}
