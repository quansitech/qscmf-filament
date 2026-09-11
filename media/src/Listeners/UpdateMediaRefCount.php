<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Listeners;

use Illuminate\Support\Facades\DB;
use Quansitech\Cmf\Media\Events\MediaUsageAttached;
use Quansitech\Cmf\Media\Events\MediaUsageDetached;
use Quansitech\Cmf\Media\Models\Media;

/**
 * 维护 media.ref_count 冗余计数：
 * attach +1（软删记录恢复），detach -1；归零时若开启 auto_delete 则
 * 软删触发延迟清理，关闭则仅保留记录（可在后台手动删除）。
 */
class UpdateMediaRefCount
{
    public function handleAttached(MediaUsageAttached $event): void
    {
        $this->change($event->usage->media_id, 1);
    }

    public function handleDetached(MediaUsageDetached $event): void
    {
        $this->change($event->usage->media_id, -1);
    }

    protected function change(int $mediaId, int $delta): void
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        DB::transaction(function () use ($model, $mediaId, $delta): void {
            /** @var Media|null $media */
            $media = $model::withTrashed()->lockForUpdate()->find($mediaId);

            if (! $media instanceof Media) {
                return;
            }

            $media->ref_count = max(0, $media->ref_count + $delta);
            $media->save();

            if ($media->ref_count > 0 && $media->trashed()) {
                // 归零软删后又被引用：恢复（DeleteMediaJob 复查时会自然跳过）
                $media->restore();
            }

            if ($media->ref_count === 0 && ! $media->trashed() && config('cmf-media.auto_delete', true)) {
                // 归零软删，模型 deleted 钩子负责排期 DeleteMediaJob
                $media->delete();
            }
        });
    }
}
