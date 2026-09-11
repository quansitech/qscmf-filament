<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * 媒体引用关联：一条记录代表"某模型的某字段引用了某媒体"。
 *
 * @property int $id
 * @property int $media_id
 * @property string $usable_type
 * @property int $usable_id
 * @property string $field
 * @property Carbon|null $created_at
 */
class MediaUsage extends Model
{
    protected $table = 'cmf_media_usages';

    /** @var list<string> */
    protected $fillable = ['media_id', 'usable_type', 'usable_id', 'field'];

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        return $this->belongsTo($model, 'media_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function usable(): MorphTo
    {
        return $this->morphTo();
    }
}
