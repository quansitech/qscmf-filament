<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Quansitech\Cmf\Media\Models\MediaUsage;

/**
 * 媒体被解除引用（usages 删除一行）。
 */
class MediaUsageDetached
{
    use Dispatchable;

    public function __construct(
        public MediaUsage $usage,
    ) {}
}
