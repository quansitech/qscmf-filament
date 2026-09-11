<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Console;

use Illuminate\Console\Command;
use Quansitech\Cmf\Media\Models\Media;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * 孤儿媒体兜底清理：上传超过 cmf-media.orphan_cleanup_after_hours 小时
 * （默认 24h）仍零引用的记录软删（deleted 钩子自动排期 DeleteMediaJob
 * 清理云端对象），解决"上传后未保存表单"的孤儿文件问题。
 *
 * 由调度器周期触发（见 CmfMediaServiceProvider），仅在 auto_delete
 * 开启时注册；手动执行不受开关限制。
 */
#[AsCommand(name: 'cmf-media:prune-orphans', description: '清理超时零引用的孤儿媒体（软删并排期清理云端对象）')]
class PruneOrphanMediaCommand extends Command
{
    protected $signature = 'cmf-media:prune-orphans';

    public function handle(): int
    {
        /** @var class-string<Media> $model */
        $model = config('cmf-media.model', Media::class);

        $hours = (int) config('cmf-media.orphan_cleanup_after_hours', 24);
        $cutoff = now()->subHours($hours);

        $count = 0;

        // 软删触发 deleted 钩子 → 排期延迟 DeleteMediaJob（执行前复查引用计数，竞态安全）
        $model::query()
            ->where('ref_count', 0)
            ->where('created_at', '<=', $cutoff)
            ->chunkById(200, function (\Illuminate\Database\Eloquent\Collection $medias) use (&$count): void {
                foreach ($medias as $media) {
                    $media->delete();
                    $count++;
                }
            });

        $this->components->info("已清理 {$count} 条孤儿媒体（软删并排期删除云端对象）。");

        return self::SUCCESS;
    }
}
