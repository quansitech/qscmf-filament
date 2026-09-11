<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Contracts\ObjectInspector;
use Throwable;

/**
 * 默认对象检查器：exists/size 走 Storage（Flysystem），
 * ETag 通过签名 HEAD 请求读取响应头（三家云对单 PUT 对象的 ETag 即内容 MD5）。
 */
class StorageObjectInspector implements ObjectInspector
{
    public function inspect(string $disk, string $path): ?array
    {
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        return [
            'size' => $storage->size($path),
            'etag' => $this->fetchEtag($disk, $path),
        ];
    }

    protected function fetchEtag(string $disk, string $path): ?string
    {
        if (! config('cmf-media.verify_etag', true)) {
            return null;
        }

        try {
            // disk 名形如 cmf-media-tos，还原驱动名取签名器
            $driver = (string) preg_replace('/^cmf-media-/', '', $disk);
            $url = MediaManager::signer($driver)->signUrl('HEAD', $path, MediaManager::diskConfig($driver));

            $response = Http::timeout(10)->head($url);

            if (! $response->successful()) {
                return null;
            }

            $etag = $response->header('ETag');

            return is_string($etag) ? trim($etag, '"') : null;
        } catch (Throwable) {
            // ETag 取不到不阻断建档（降级为仅 size 校验）
            return null;
        }
    }
}
