<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Contracts;

/**
 * 直传签名器：为浏览器直传生成限定单 key、短有效期的上传凭证，
 * 并为服务端 headObject 校验生成签名请求。
 */
interface DirectUploadSigner
{
    /**
     * 生成浏览器直传凭证。
     *
     * @param  array<string, mixed>  $config  cmf-media.disks.{driver} 配置
     * @return array{method: string, upload_url: string, fields: array<string, string>, headers: array<string, string>, expires: int}
     */
    public function signUpload(string $key, string $mime, int $size, array $config): array;

    /**
     * 生成指定 HTTP 方法的签名 URL（服务端 headObject 校验用）。
     *
     * @param  array<string, mixed>  $config
     */
    public function signUrl(string $method, string $key, array $config, ?int $expires = null): string;
}
