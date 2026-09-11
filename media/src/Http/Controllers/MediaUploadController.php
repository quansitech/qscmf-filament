<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Quansitech\Cmf\Media\Contracts\ObjectInspector;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaManager;

/**
 * 浏览器直传三端点：check（查重秒传）/ sign（签发凭证）/ callback（建档），
 * 以及 MediaPicker「从媒体库选择」的 library 列表。
 */
class MediaUploadController extends Controller
{
    use AuthorizesRequests;

    /**
     * 查重：命中（含软删记录）直接返回已有 media（秒传），未命中 404。
     */
    public function check(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('viewAny', $model);

        /** @var array{hash: string, size: int, mime: string} $data */
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:32'],
            'size' => ['required', 'integer', 'min:0'],
            'mime' => ['required', 'string', 'max:100'],
        ]);

        /** @var Media|null $media */
        $media = $model::withTrashed()->where('hash', $data['hash'])->first();

        if (! $media instanceof Media) {
            return response()->json(['message' => '未命中'], 404);
        }

        if ($media->trashed()) {
            // 命中软删记录：恢复，DeleteMediaJob 复查时会自然跳过
            $media->restore();
        }

        return response()->json(['media' => $this->mediaJson($media)]);
    }

    /**
     * 签发直传凭证：校验 mime/大小白名单，对象 key 固定为 {hash前2位}/{hash}.{ext}。
     */
    public function sign(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('create', $model);

        /** @var array{hash: string, name: string, mime: string, size: int} $data */
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:32'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        if ($data['size'] > (int) config('cmf-media.max_size')) {
            throw ValidationException::withMessages([
                'size' => '文件超过大小上限 '.((int) config('cmf-media.max_size') / 1024 / 1024).'MB',
            ]);
        }

        if (! MediaManager::mimeAllowed($data['mime'])) {
            throw ValidationException::withMessages([
                'mime' => '不允许的文件类型：'.$data['mime'],
            ]);
        }

        $ext = MediaManager::safeExtension($data['name']);
        $key = Media::objectKey($data['hash'], $ext);
        $driver = MediaManager::driver();

        // local：无需云签名，直传应用服务器的 upload 端点（POST multipart）
        if (MediaManager::isLocal($driver)) {
            return response()->json([
                'method' => 'POST',
                'upload_url' => route('cmf-media.upload'),
                'fields' => ['hash' => $data['hash'], 'name' => $data['name']],
                'headers' => ['X-CSRF-TOKEN' => csrf_token(), 'Accept' => 'application/json'],
                'expires' => 0,
                'path' => $key,
                'disk' => $driver,
                'local' => true,
            ]);
        }

        $credential = MediaManager::signer($driver)
            ->signUpload($key, $data['mime'], (int) $data['size'], MediaManager::diskConfig($driver));

        return response()->json([...$credential, 'path' => $key, 'disk' => $driver]);
    }

    /**
     * local 驱动上传端点：服务器接收文件、计算真实内容 hash（防伪）、
     * 落盘到 hash 路径并建档。秒传去重由前置 check 承担，此处相同内容
     * 重复上传时复用已有记录（hash 唯一索引兜底）。
     */
    public function upload(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('create', $model);

        if (! MediaManager::isLocal()) {
            abort(404);
        }

        /** @var array{hash: string, name: string} $data */
        $data = $request->validate([
            'file' => ['required', 'file'],
            'hash' => ['required', 'string', 'size:32'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $size = (int) $file->getSize();

        if ($size < 1 || $size > (int) config('cmf-media.max_size')) {
            throw ValidationException::withMessages([
                'size' => '文件超过大小上限 '.((int) config('cmf-media.max_size') / 1024 / 1024).'MB',
            ]);
        }

        // MIME 以服务端探测为准，客户端上报不可信
        $mime = $file->getMimeType() ?: 'application/octet-stream';

        if (! MediaManager::mimeAllowed($mime)) {
            throw ValidationException::withMessages([
                'mime' => '不允许的文件类型：'.$mime,
            ]);
        }

        // 服务器亲自计算内容指纹：与客户端上报不符即拒绝
        $hash = md5_file($file->getRealPath());

        if (! is_string($hash) || ! hash_equals(strtolower($hash), strtolower($data['hash']))) {
            throw ValidationException::withMessages(['hash' => '文件指纹与上报哈希不符']);
        }

        $ext = MediaManager::safeExtension($data['name']);
        $key = Media::objectKey($hash, $ext);
        $diskName = Media::diskName('local');

        if (! Storage::disk($diskName)->exists($key)) {
            $stream = fopen($file->getRealPath(), 'r');
            Storage::disk($diskName)->put($key, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // 图片尺寸服务端直接读取（云直传靠客户端上报，local 自己算更可信）
        $width = $height = null;

        if (str_starts_with($mime, 'image/')) {
            $imageSize = @getimagesize($file->getRealPath());

            if (is_array($imageSize)) {
                [$width, $height] = [$imageSize[0], $imageSize[1]];
            }
        }

        $media = $this->firstOrCreate($model, 'local', $key, [
            'hash' => $hash,
            'name' => $data['name'],
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ], $ext, $request->user()?->getAuthIdentifier());

        return response()->json(['media' => $this->mediaJson($media)]);
    }

    /**
     * 直传完成回调建档：校验对象真实存在、size 一致、ETag 与上报 hash 一致（防伪），
     * hash 唯一索引兜底并发重复回调。
     */
    public function callback(Request $request, ObjectInspector $inspector): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('create', $model);

        /** @var array{hash: string, path: string, name: string, mime: string, size: int, width?: int|null, height?: int|null} $data */
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:32'],
            'path' => ['required', 'string', 'max:512'],
            'name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1'],
            'width' => ['nullable', 'integer', 'min:0'],
            'height' => ['nullable', 'integer', 'min:0'],
        ]);

        // 对象 key 由服务端规则生成，防止客户端伪造路径冒领他人文件
        $ext = MediaManager::safeExtension($data['name']);
        $expectedKey = Media::objectKey($data['hash'], $ext);

        if ($data['path'] !== $expectedKey) {
            throw ValidationException::withMessages(['path' => '对象路径与内容哈希不符']);
        }

        $driver = MediaManager::driver();
        $info = $inspector->inspect(Media::diskName($driver), $data['path']);

        if ($info === null) {
            throw ValidationException::withMessages(['path' => '云端对象不存在，建档失败']);
        }

        if ($info['size'] !== (int) $data['size']) {
            throw ValidationException::withMessages(['size' => '云端对象大小与上报不符']);
        }

        // 单 PUT 上传时 ETag 即内容 MD5（分片 ETag 含 "-"，跳过比对）
        $etag = $info['etag'];

        if (is_string($etag) && ! str_contains($etag, '-') && ! hash_equals(strtolower($etag), strtolower($data['hash']))) {
            throw ValidationException::withMessages(['hash' => '云端对象指纹与上报哈希不符']);
        }

        $media = $this->firstOrCreate($model, $driver, $expectedKey, $data, $ext, $request->user()?->getAuthIdentifier());

        return response()->json(['media' => $this->mediaJson($media)]);
    }

    /**
     * 媒体库列表（MediaPicker「从媒体库选择」）。
     */
    public function library(Request $request): JsonResponse
    {
        $model = $this->model();
        Gate::authorize('viewAny', $model);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
        $paginator = $model::query()
            ->latest()
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 24))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Media $media): array => $this->mediaJson($media))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * hash 唯一索引兜底并发重复回调：撞唯一约束时改查已有记录。
     *
     * @param  class-string<Media>  $model
     * @param  array<string, mixed>  $data
     */
    protected function firstOrCreate(string $model, string $driver, string $key, array $data, string $ext, int|string|null $uploaderId): Media
    {
        // 含软删记录：同 hash 重复上传直接复用并恢复
        /** @var Media|null $existing */
        $existing = $model::withTrashed()->where('hash', $data['hash'])->first();

        if ($existing instanceof Media) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        try {
            /** @var Media $media */
            $media = $model::create([
                'hash' => $data['hash'],
                'disk' => $driver,
                'path' => $key,
                'original_name' => $data['name'],
                'mime' => $data['mime'],
                'ext' => $ext,
                'size' => $data['size'],
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'uploader_id' => $uploaderId,
            ]);
        } catch (QueryException) {
            // 并发重复回调撞唯一索引：改查已有记录
            /** @var Media $media */
            $media = $model::withTrashed()->where('hash', $data['hash'])->firstOrFail();
        }

        if ($media->trashed()) {
            $media->restore();
        }

        return $media;
    }

    /**
     * @return class-string<Media>
     */
    protected function model(): string
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mediaJson(Media $media): array
    {
        return [
            'id' => $media->id,
            'disk' => $media->disk,
            'path' => $media->path,
            'hash' => $media->hash,
            'original_name' => $media->original_name,
            'mime' => $media->mime,
            'ext' => $media->ext,
            'size' => $media->size,
            'width' => $media->width,
            'height' => $media->height,
            'ref_count' => $media->ref_count,
            'url' => $media->url(),
            'thumb_url' => $media->thumbUrl(),
        ];
    }
}
