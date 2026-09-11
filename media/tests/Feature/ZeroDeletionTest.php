<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Jobs\DeleteMediaJob;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Tests\Fixtures\Models\Post;

it('soft deletes media and dispatches the delayed job when ref_count reaches zero', function (): void {
    Queue::fake();
    Storage::fake('cmf-media-tos');

    $media = createMedia();
    Storage::disk('cmf-media-tos')->put($media->path, 'content');
    $post = Post::create(['title' => 'hello']);

    $post->attachMedia($media->id, 'cover');
    $post->detachMedia($media->id, 'cover');

    expect($media->fresh()->trashed())->toBeTrue()
        ->and($media->fresh()->ref_count)->toBe(0);

    Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job): bool => $job->mediaId === $media->id && $job->delay !== null);

    // 尚未执行 Job：对象仍在云端
    expect(Storage::disk('cmf-media-tos')->exists($media->path))->toBeTrue();
});

it('job deletes the cloud object and force deletes the record', function (): void {
    Queue::fake();
    Storage::fake('cmf-media-tos');

    $media = createMedia();
    Storage::disk('cmf-media-tos')->put($media->path, 'content');
    $post = Post::create(['title' => 'hello']);

    $post->attachMedia($media->id, 'cover');
    $post->detachMedia($media->id, 'cover');

    (new DeleteMediaJob($media->id))->handle();

    expect(Storage::disk('cmf-media-tos')->exists($media->path))->toBeFalse()
        ->and(Media::withTrashed()->find($media->id))->toBeNull();
});

it('job skips deletion when the media was re-referenced before execution （竞态防护）', function (): void {
    Queue::fake();
    Storage::fake('cmf-media-tos');

    $media = createMedia();
    Storage::disk('cmf-media-tos')->put($media->path, 'content');
    $post = Post::create(['title' => 'hello']);

    $post->attachMedia($media->id, 'cover');
    $post->detachMedia($media->id, 'cover');

    expect($media->fresh()->trashed())->toBeTrue();

    // 延迟窗口内重新被引用：恢复 + ref_count=1
    $post->attachMedia($media->id, 'cover');

    expect($media->fresh()->trashed())->toBeFalse()
        ->and($media->fresh()->ref_count)->toBe(1);

    // 此前派发的 Job 执行时复查，跳过删除
    (new DeleteMediaJob($media->id))->handle();

    expect(Storage::disk('cmf-media-tos')->exists($media->path))->toBeTrue()
        ->and(Media::withTrashed()->find($media->id))->not->toBeNull();
});

it('deleting a referenced media from the admin is blocked and job is not dispatched', function (): void {
    Queue::fake();

    $media = createMedia();
    $post = Post::create(['title' => 'hello']);
    $post->attachMedia($media->id, 'cover');

    Queue::assertNothingPushed();

    // 归零才会触发软删与派发；ref_count>0 时直接删除不派发清理任务
    $media->refresh()->delete();

    Queue::assertNothingPushed();
    expect($media->fresh()->trashed())->toBeTrue();
});

it('keeps the media when auto delete is disabled（开关关闭）', function (): void {
    Queue::fake();
    config()->set('cmf-media.auto_delete', false);

    $media = createMedia();
    $post = Post::create(['title' => 'hello']);

    $post->attachMedia($media->id, 'cover');
    $post->detachMedia($media->id, 'cover');

    // 归零但不软删、不派发清理任务
    expect($media->fresh()->trashed())->toBeFalse()
        ->and($media->fresh()->ref_count)->toBe(0);

    Queue::assertNotPushed(DeleteMediaJob::class);

    // 重新引用正常计数
    $post->attachMedia($media->id, 'cover');

    expect($media->fresh()->ref_count)->toBe(1);
});

it('admin manual delete still schedules cloud cleanup when auto delete is disabled', function (): void {
    Queue::fake();
    config()->set('cmf-media.auto_delete', false);

    $media = createMedia();

    // 后台手动删除（ref_count=0）是显式操作，仍应排期清理云端对象
    $media->delete();

    expect($media->fresh()->trashed())->toBeTrue();
    Queue::assertPushed(DeleteMediaJob::class, fn (DeleteMediaJob $job): bool => $job->mediaId === $media->id);
});
