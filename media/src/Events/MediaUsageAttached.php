<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Quansitech\Cmf\Media\Models\MediaUsage;

/**
 * 媒体被某模型字段引用（usages 新增一行）。
 */
class MediaUsageAttached
{
    use Dispatchable;

    public function __construct(
        public MediaUsage $usage,
    ) {}
}
