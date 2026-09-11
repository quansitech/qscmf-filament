<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Quansitech\Cmf\Media\Events\MediaUsageAttached;
use Quansitech\Cmf\Media\Events\MediaUsageDetached;
use Quansitech\Cmf\Media\Models\MediaUsage;

/**
 * 业务模型引用媒体的能力：attach / detach / sync，
 * usages 关联行 + 事件驱动 ref_count 增减。
 *
 * 用法：表单提交后 $model->syncMedia($mediaIds, '字段名')。
 */
trait HasMedia
{
    /**
     * @return MorphMany<MediaUsage, $this>
     */
    public function mediaUsages(): MorphMany
    {
        return $this->morphMany(MediaUsage::class, 'usable');
    }

    /**
     * 引用一个媒体（同字段重复引用不会产生重复行）。
     */
    public function attachMedia(int $mediaId, string $field): MediaUsage
    {
        /** @var MediaUsage $usage */
        $usage = $this->mediaUsages()->firstOrCreate([
            'media_id' => $mediaId,
            'field' => $field,
        ]);

        if ($usage->wasRecentlyCreated) {
            event(new MediaUsageAttached($usage));
        }

        return $usage;
    }

    /**
     * 解除某字段对一个媒体的引用。
     */
    public function detachMedia(int $mediaId, string $field): bool
    {
        /** @var MediaUsage|null $usage */
        $usage = $this->mediaUsages()
            ->where('media_id', $mediaId)
            ->where('field', $field)
            ->first();

        if (! $usage instanceof MediaUsage) {
            return false;
        }

        $usage->delete();

        event(new MediaUsageDetached($usage));

        return true;
    }

    /**
     * 以给定 id 列表覆盖某字段的引用集合（差集增删）。
     *
     * @param  list<int|string>  $mediaIds
     */
    public function syncMedia(array $mediaIds, string $field): void
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));

        /** @var list<int> $existing */
        $existing = $this->mediaUsages()
            ->where('field', $field)
            ->pluck('media_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach (array_diff($ids, $existing) as $id) {
            $this->attachMedia($id, $field);
        }

        foreach (array_diff($existing, $ids) as $id) {
            $this->detachMedia($id, $field);
        }
    }
}
