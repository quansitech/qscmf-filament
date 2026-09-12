<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Concerns;

use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Quansitech\Cmf\Media\RichContent\MediaFileAttachmentProvider;

/**
 * RichEditor 富文本附件接管一行接入（引用计数闭环）：
 *
 * ```php
 * class Post extends Model implements HasRichContent
 * {
 *     use HasMedia;
 *     use HasMediaRichContent;
 *
 *     protected array $mediaRichContentAttributes = ['content']; // 可多个字段
 * }
 * ```
 *
 * 接入后 RichEditor 字段的上传自动走媒体库（内容哈希去重），保存时按内容
 * 中的 media id 集合同步 usages 引用（引用计数），移除引用归零走既有
 * 软删 + 延迟 Job 清理链路；记录物理删除时自动清理富文本字段的引用
 * （软删保留，恢复后引用仍在）。
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasMediaRichContent
{
    use InteractsWithRichContent;

    protected static function bootHasMediaRichContent(): void
    {
        // 记录物理删除时清理富文本字段的媒体引用；软删保留（恢复后引用仍在）
        static::deleted(function (Model $model): void {
            /** @var static $model */
            if (in_array(SoftDeletes::class, class_uses_recursive($model), true) && ! $model->isForceDeleting()) {
                return;
            }

            foreach ($model->mediaRichContentFields() as $field) {
                /** @var list<int> $mediaIds */
                $mediaIds = $model->mediaUsages()
                    ->where('field', $field)
                    ->pluck('media_id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($mediaIds as $mediaId) {
                    $model->detachMedia($mediaId, $field);
                }
            }
        });
    }

    protected function setUpRichContent(): void
    {
        if (! $this instanceof HasRichContent) {
            throw new LogicException(
                static::class.' 使用 HasMediaRichContent 需同时 implements '
                .HasRichContent::class.'（Filament RichEditor 据此识别富文本属性）'
            );
        }

        if (! method_exists($this, 'syncMedia')) {
            throw new LogicException(
                static::class.' 使用 HasMediaRichContent 需同时 use HasMedia（引用计数依赖 syncMedia）'
            );
        }

        foreach ($this->mediaRichContentFields() as $name) {
            $this->registerRichContent($name)
                ->fileAttachmentProvider(app(MediaFileAttachmentProvider::class));
        }
    }

    /**
     * 挂接媒体库的 RichEditor 字段名列表。
     *
     * @return list<string>
     */
    protected function mediaRichContentFields(): array
    {
        return property_exists($this, 'mediaRichContentAttributes') && is_array($this->mediaRichContentAttributes)
            ? array_values($this->mediaRichContentAttributes)
            : ['content'];
    }
}
