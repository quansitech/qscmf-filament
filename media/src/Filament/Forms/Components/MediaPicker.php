<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Forms\Components;

use Closure;
use Filament\Forms\Components\Field;
use Quansitech\Cmf\Media\Models\Media;

/**
 * 媒体选择/直传字段：浏览器直传云存储（hash → 查重 → 签名 → 直传 → 回调），
 * 支持单/多选、预览已有媒体、从媒体库选择。
 *
 * 字段 state 为媒体 id（单选）或 id 数组（多选）；业务模型保存后调用
 * HasMedia::syncMedia($ids, $field) 建立引用并驱动引用计数。
 */
class MediaPicker extends Field
{
    protected string $view = 'cmf-media::forms.components.media-picker';

    protected bool|Closure $multiple = false;

    protected function setUp(): void
    {
        parent::setUp();

        // 多选时 state 归一化为 int 数组；单选为 int|null
        $this->dehydrateStateUsing(function (mixed $state): mixed {
            if ($this->isMultiple()) {
                return collect(is_array($state) ? $state : [$state])
                    ->filter(fn (mixed $id): bool => filled($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->values()
                    ->all();
            }

            return filled($state) ? (int) $state : null;
        });
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->multiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->multiple);
    }

    /**
     * 当前 state 对应的媒体记录（视图预览用）。
     *
     * @return \Illuminate\Support\Collection<int, Media>
     */
    public function getSelectedMedia(): \Illuminate\Support\Collection
    {
        $state = $this->getState();
        $ids = is_array($state) ? $state : [$state];
        $ids = array_filter(array_map('intval', $ids));

        if ($ids === []) {
            return collect();
        }

        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        return $model::query()->whereIn('id', $ids)->get()->keyBy('id')
            ->pipe(fn (\Illuminate\Support\Collection $collection): \Illuminate\Support\Collection => collect($ids)
                ->map(fn (int $id): ?Media => $collection->get($id))
                ->filter()
                ->values());
    }
}
