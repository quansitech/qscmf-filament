<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Quansitech\Cmf\Media\Models\Media;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * 真实存储冒烟：put → exists → get → delete 全流程。
 * 云驱动需配置环境变量凭证（TOS_* / OSS_* / COS_*），local 无需凭证，CI 可跳过。
 */
#[AsCommand(name: 'cmf-media:smoke', description: '媒体存储冒烟测试（云驱动需真实 bucket 凭证）')]
class SmokeTestCommand extends Command
{
    protected $signature = 'cmf-media:smoke
        {--disk= : 驱动（tos / oss / cos / local），默认取 cmf-media.default}';

    public function handle(): int
    {
        $driver = (string) ($this->option('disk') ?: config('cmf-media.default', 'tos'));

        if (! in_array($driver, ['tos', 'oss', 'cos', 'local'], true)) {
            $this->components->error("不支持的驱动：{$driver}（可选 tos / oss / cos / local）");

            return self::FAILURE;
        }

        if ($driver !== 'local' && ! config("cmf-media.disks.{$driver}.bucket")) {
            $this->components->error("驱动 {$driver} 未配置 bucket（请检查 cmf-media.disks.{$driver} / 环境变量）");

            return self::FAILURE;
        }

        $diskName = Media::diskName($driver);
        $path = 'cmf-media-smoke/'.Str::random(16).'.txt';
        $content = 'cmf-media smoke test '.now()->toIso8601String();

        try {
            $disk = Storage::disk($diskName);

            $this->components->task("[{$diskName}] put {$path}", fn (): bool => $disk->put($path, $content));
            $this->components->task("[{$diskName}] exists", function () use ($disk, $path): bool {
                return $disk->exists($path);
            });
            $this->components->task("[{$diskName}] get 内容一致", function () use ($disk, $path, $content): bool {
                return $disk->get($path) === $content;
            });
            $this->components->task("[{$diskName}] delete", function () use ($disk, $path): bool {
                $disk->delete($path);

                return ! $disk->exists($path);
            });
        } catch (Throwable $e) {
            $this->components->error("冒烟失败：{$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("驱动 {$driver} 冒烟通过（put → exists → get → delete）。");

        return self::SUCCESS;
    }
}
