<?php

declare(strict_types=1);

use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapterContract;
use Quansitech\Cmf\Media\Flysystem\OssFilesystemAdapter;

/**
 * 回归测试：自定义 disk 必须能生成 URL。
 *
 * 历史 bug：Storage::extend 用通用 FilesystemAdapter 包 League 适配器，
 * 而 Laravel 13 的 url()/temporaryUrl() 只认适配器上的 getUrl()/getTemporaryUrl()
 * 方法（或 Ftp/Sftp/Local 类型），导致 Media::url() 恒为 null、后台缩略图空白。
 * 另有顺序 bug：先走 temporaryUrl 时 thumbUrl 拼接的图片处理参数会破坏 SigV4 签名。
 */

/**
 * 最小 League 适配器桩（不触网）。
 */
function leagueAdapterStub(): FlysystemAdapterContract
{
    return new class implements FlysystemAdapterContract
    {
        public function fileExists(string $path): bool
        {
            return true;
        }

        public function directoryExists(string $path): bool
        {
            return true;
        }

        public function write(string $path, string $contents, Config $config): void {}

        public function writeStream(string $path, $contents, Config $config): void {}

        public function read(string $path): string
        {
            return '';
        }

        public function readStream(string $path)
        {
            return fopen('php://temp', 'rb');
        }

        public function delete(string $path): void {}

        public function deleteDirectory(string $path): void {}

        public function createDirectory(string $path, Config $config): void {}

        public function setVisibility(string $path, string $visibility): void {}

        public function visibility(string $path): FileAttributes
        {
            return new FileAttributes($path);
        }

        public function mimeType(string $path): FileAttributes
        {
            return new FileAttributes($path);
        }

        public function lastModified(string $path): FileAttributes
        {
            return new FileAttributes($path);
        }

        public function fileSize(string $path): FileAttributes
        {
            return new FileAttributes($path);
        }

        public function listContents(string $path, bool $deep): iterable
        {
            return [];
        }

        public function move(string $source, string $destination, Config $config): void {}

        public function copy(string $source, string $destination, Config $config): void {}
    };
}

/**
 * 注册一个同时支持公开 url() 与 temporaryUrl() 的测试 disk，
 * 两者返回值可区分，用于锁定 Media::url() 的优先顺序。
 */
function registerUrlTestDisk(string $driver, bool $urlWithQuery = false): void
{
    $stub = leagueAdapterStub();

    Storage::extend($driver, function () use ($stub, $urlWithQuery) {
        return new class(new Filesystem($stub), $stub, [], $urlWithQuery) extends FilesystemAdapter
        {
            public function __construct(
                Filesystem $driver,
                FlysystemAdapterContract $adapter,
                array $config,
                protected bool $withQuery,
            ) {
                parent::__construct($driver, $adapter, $config);
            }

            public function url($path): string
            {
                return 'https://cdn.example.com/'.$this->prefixer->prefixPath($path)
                    .($this->withQuery ? '?existing=1' : '');
            }

            public function temporaryUrl($path, $expiration, array $options = []): string
            {
                return 'https://signed.example.com/'.$this->prefixer->prefixPath($path);
            }
        };
    });

    config()->set("filesystems.disks.{$driver}", ['driver' => $driver]);
}

it('resolves the tos disk via Laravel S3 wrapper supporting url and temporaryUrl', function (): void {
    $disk = Storage::disk('cmf-media-tos');

    // 必须是 Laravel 的 S3 专用包装类（通用 FilesystemAdapter 无法生成 URL）
    expect($disk)->toBeInstanceOf(LaravelS3Adapter::class);

    // url() 与 temporaryUrl() 均为本地计算（字符串拼接 / 预签名），不触网
    expect($disk->url('ab/abc.jpg'))
        ->toBe('https://test-tos-bucket.tos-s3-cn-beijing.volces.com/ab/abc.jpg');
    expect($disk->temporaryUrl('ab/abc.jpg', now()->addMinute()))
        ->toContain('X-Amz-Signature=');
});

it('oss filesystem adapter generates public url from bucket and endpoint', function (): void {
    $stub = leagueAdapterStub();

    $disk = new OssFilesystemAdapter(new Filesystem($stub), $stub, [
        'bucket' => 'test-oss-bucket',
        'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
    ]);

    // xxtime 适配器没有 Laravel 识别的 getUrl()，由 OssFilesystemAdapter 补齐
    expect($disk)->toBeInstanceOf(FilesystemAdapter::class)
        ->and($disk->url('ab/abc.jpg'))
        ->toBe('https://test-oss-bucket.oss-cn-hangzhou.aliyuncs.com/ab/abc.jpg');
});

it('media url prefers public url so thumb processing params stay valid', function (): void {
    registerUrlTestDisk('cmf-media-urltest');

    $media = createMedia(['disk' => 'urltest']);

    // 必须是公开 URL 而非临时签名 URL：
    // thumbUrl 会在其后拼接图片处理参数，预签名 URL 追加参数会破坏 SigV4 签名
    expect($media->url())->toBe('https://cdn.example.com/'.$media->path);
});

it('thumb url appends processing suffix with correct separator', function (): void {
    registerUrlTestDisk('cmf-media-urltest');
    config()->set('cmf-media.disks.urltest.thumb_suffix', '?x-test=resize,w_200');

    $media = createMedia(['disk' => 'urltest']);

    expect($media->thumbUrl())
        ->toBe('https://cdn.example.com/'.$media->path.'?x-test=resize,w_200');
});

it('thumb url uses & separator when the url already has a query string', function (): void {
    registerUrlTestDisk('cmf-media-urltestq', urlWithQuery: true);
    config()->set('cmf-media.disks.urltestq.thumb_suffix', '?x-test=resize,w_200');

    $media = createMedia(['disk' => 'urltestq']);

    expect($media->thumbUrl())
        ->toBe('https://cdn.example.com/'.$media->path.'?existing=1&x-test=resize,w_200');
});

it('thumb url returns null for non-image media', function (): void {
    registerUrlTestDisk('cmf-media-urltest');

    $media = createMedia([
        'disk' => 'urltest',
        'mime' => 'application/pdf',
        'ext' => 'pdf',
    ]);

    expect($media->thumbUrl())->toBeNull();
});

it('media url returns null when the disk cannot be resolved', function (): void {
    $media = createMedia(['disk' => 'ghost']);

    // adapter 未装 / disk 未注册时展示场景不 500
    expect($media->url())->toBeNull()
        ->and($media->thumbUrl())->toBeNull();
});
