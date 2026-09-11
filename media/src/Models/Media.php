<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Jobs\DeleteMediaJob;
use Throwable;

/**
 * 媒体资源：内容 MD5 去重，对象 key 即 hash 路径；引用计数归零后
 * 软删并延迟派发 DeleteMediaJob 清理云端对象。
 *
 * @property int $id
 * @property string $disk 存储驱动：tos / oss / cos / local
 * @property string $path 对象 key：{hash前2位}/{hash}.{ext}
 * @property string $hash 文件内容 MD5
 * @property string $original_name
 * @property string $mime
 * @property string $ext
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $uploader_id
 * @property int $ref_count
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cmf_media';

    /** @var list<string> */
    protected $fillable = ['disk', 'path', 'hash', 'original_name', 'mime', 'ext', 'size', 'width', 'height', 'uploader_id', 'ref_count'];

    protected static function booted(): void
    {
        // 软删（引用归零 / 后台删除）即排期延迟清理；Job 执行前会复查引用计数
        static::deleted(function (Media $media): void {
            if ($media->isForceDeleting() || $media->ref_count > 0) {
                return;
            }

            $media->scheduleDeletion();
        });
    }

    /**
     * 延迟派发云端对象删除任务（延迟时长由 config 控制）。
     */
    public function scheduleDeletion(): void
    {
        DeleteMediaJob::dispatch($this->id)
            ->delay(now()->addMinutes((int) config('cmf-media.delete_delay_minutes', 60)));
    }

    /**
     * 对象存储 disk 名称（cmf-media-{driver}）。
     */
    public static function diskName(?string $driver = null): string
    {
        return 'cmf-media-'.($driver ?: (string) config('cmf-media.default', 'tos'));
    }

    /**
     * 对象 key：{hash前2位}/{hash}.{ext}。
     */
    public static function objectKey(string $hash, string $ext): string
    {
        return substr($hash, 0, 2).'/'.$hash.($ext === '' ? '' : '.'.$ext);
    }

    /**
     * @return HasMany<MediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class, 'media_id');
    }

    /**
     * 上传人（宿主用户模型，可为空）。
     *
     * @return BelongsTo<Model, $this>
     */
    public function uploader(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model') ?: \Illuminate\Foundation\Auth\User::class;

        return $this->belongsTo($model, 'uploader_id');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * 访问 URL：优先公开 URL，回退临时签名 URL。
     *
     * 注意顺序不能反过来：缩略图依赖在 URL 后拼接云厂商图片处理参数
     * （x-tos-process / x-oss-process / imageMogr2），而 SigV4 预签名 URL
     * 追加任何查询参数都会破坏签名导致 403。媒体文件按公开可读设计。
     */
    public function url(int $ttl = 600): ?string
    {
        try {
            $disk = Storage::disk(static::diskName($this->disk));
        } catch (Throwable) {
            // 对应驱动的 adapter 未安装时，列表页等展示场景不因此 500
            return null;
        }

        try {
            return $disk->url($this->path);
        } catch (Throwable) {
            try {
                return $disk->temporaryUrl($this->path, now()->addSeconds($ttl));
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * 缩略图 URL：直传绕开服务器无法本地生成缩略图，
     * 使用云厂商图片处理参数（OSS/COS/TOS 均支持 URL 参数缩放）。
     */
    public function thumbUrl(): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        $url = $this->url();

        if (! is_string($url)) {
            return null;
        }

        /** @var string $suffix */
        $suffix = config("cmf-media.disks.{$this->disk}.thumb_suffix", '');

        if ($suffix === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&'.ltrim($suffix, '?&') : $suffix);
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'ref_count' => 'integer',
        ];
    }
}
