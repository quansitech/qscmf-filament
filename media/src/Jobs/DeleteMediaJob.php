<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;

/**
 * 延迟清理云端对象：执行前复查引用计数仍为 0 且仍处软删，
 * 防止"删的同时又被引用"的竞态误删。
 */
class DeleteMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public int $mediaId,
    ) {}

    public function handle(): void
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        /** @var Media|null $media */
        $media = $model::withTrashed()->find($this->mediaId);

        if (! $media instanceof Media) {
            return;
        }

        // 复查：归零前重新被引用（已 restore 或 ref_count>0）则跳过删除
        if ($media->ref_count > 0 || ! $media->trashed()) {
            return;
        }

        Storage::disk(Media::diskName($media->disk))->delete($media->path);

        $media->forceDelete();
    }
}
