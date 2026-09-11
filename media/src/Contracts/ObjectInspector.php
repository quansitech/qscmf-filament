<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Contracts;

/**
 * 云端对象检查器：callback 建档前校验对象真实存在、size 一致、
 * ETag 与客户端上报 hash 一致（防伪）。
 */
interface ObjectInspector
{
    /**
     * @return array{size: int, etag: string|null}|null 对象不存在返回 null
     */
    public function inspect(string $disk, string $path): ?array;
}
