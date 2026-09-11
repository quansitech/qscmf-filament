<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Jobs\DeleteMediaJob;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Tests\Fixtures\Models\Post;

/**
 * 孤儿清理命令：上传超过宽限期（默认 24h）仍零引用的记录软删并
 * 排期清理云端对象；宽限期内的零引用记录与有引用记录不受影响。
 */
it('soft deletes orphan media older than the grace period and schedules cleanup', function (): void {
    Queue::fake();
    Storage::fake('cmf-media-tos');

    // created_at 不在 fillable 中，创建后单独回写
    $orphan = createMedia();
    $orphan->created_at = now()->subHours(25);
    $orphan->save();
    Storage::disk('cmf-media-tos')->put($orphan->path, 'content');

    $this->artisan('cmf-media:prune-orphans')->assertSuccessful();

    expect($orphan->fresh()->trashed())->toBeTrue();
    Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job): bool => $job->mediaId === $orphan->id);
});

it('keeps zero-ref media within the grace period（长表单填写中）', function (): void {
    Queue::fake();

    $recent = createMedia();
    $recent->created_at = now()->subHours(2);
    $recent->save();

    $this->artisan('cmf-media:prune-orphans')->assertSuccessful();

    expect($recent->fresh()->trashed())->toBeFalse();
    Queue::assertNotPushed(DeleteMediaJob::class);
});

it('keeps referenced media regardless of age', function (): void {
    Queue::fake();

    $referenced = createMedia();
    $referenced->created_at = now()->subDays(7);
    $referenced->save();
    $post = Post::create(['title' => 'x']);
    $post->attachMedia($referenced->id, 'cover');

    $this->artisan('cmf-media:prune-orphans')->assertSuccessful();

    expect($referenced->fresh()->trashed())->toBeFalse();
    Queue::assertNotPushed(DeleteMediaJob::class);
});

it('respects the configured grace hours', function (): void {
    Queue::fake();
    config()->set('cmf-media.orphan_cleanup_after_hours', 72);

    $twoDaysOld = createMedia();
    $twoDaysOld->created_at = now()->subHours(50);
    $twoDaysOld->save();

    $this->artisan('cmf-media:prune-orphans')->assertSuccessful();

    expect($twoDaysOld->fresh()->trashed())->toBeFalse();
});

it('registers a daily schedule gated by the auto delete switch（运行期判断）', function (): void {
    /** @var list<\Illuminate\Console\Scheduling\Event> $events */
    $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();

    $event = collect($events)->first(
        fn (\Illuminate\Console\Scheduling\Event $e): bool => str_contains($e->command ?? '', 'cmf-media:prune-orphans'),
    );

    expect($event)->not->toBeNull();

    // when 闭包运行期读取配置：开关关闭时跳过该次调度
    config()->set('cmf-media.auto_delete', false);
    expect($event->filtersPass($this->app))->toBeFalse();

    config()->set('cmf-media.auto_delete', true);
    expect($event->filtersPass($this->app))->toBeTrue();
});

it('keeps the orphan file available for attach within the grace period（attach 正常计数）', function (): void {
    Queue::fake();

    $media = createMedia();
    $media->created_at = now()->subHours(10);
    $media->save();
    $post = Post::create(['title' => 'x']);
    $post->attachMedia($media->id, 'cover');

    expect($media->fresh()->ref_count)->toBe(1)
        ->and($media->fresh()->trashed())->toBeFalse();
});
