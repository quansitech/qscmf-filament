<?php

declare(strict_types=1);

use Quansitech\Cmf\Media\Models\MediaUsage;
use Quansitech\Cmf\Media\Tests\Fixtures\Models\Post;

it('attach creates a usage row and bumps ref_count', function (): void {
    $media = createMedia();
    $post = Post::create(['title' => 'hello']);

    $post->attachMedia($media->id, 'cover');

    expect(MediaUsage::count())->toBe(1)
        ->and($media->fresh()->ref_count)->toBe(1);

    $usage = MediaUsage::sole();
    expect($usage->usable_type)->toBe(Post::class)
        ->and($usage->usable_id)->toBe($post->id)
        ->and($usage->field)->toBe('cover');
});

it('attaching the same media on the same field twice does not duplicate', function (): void {
    $media = createMedia();
    $post = Post::create(['title' => 'hello']);

    $post->attachMedia($media->id, 'cover');
    $post->attachMedia($media->id, 'cover');

    expect(MediaUsage::count())->toBe(1)
        ->and($media->fresh()->ref_count)->toBe(1);
});

it('the same field can reference the same media from different models', function (): void {
    $media = createMedia();
    $postA = Post::create(['title' => 'a']);
    $postB = Post::create(['title' => 'b']);

    $postA->attachMedia($media->id, 'cover');
    $postB->attachMedia($media->id, 'cover');

    expect(MediaUsage::count())->toBe(2)
        ->and($media->fresh()->ref_count)->toBe(2);

    // 解除一个引用后 ref_count=1，不触发软删
    $postA->detachMedia($media->id, 'cover');

    expect($media->fresh()->ref_count)->toBe(1)
        ->and($media->fresh()->trashed())->toBeFalse();
});

it('sync adds the diff and removes the rest', function (): void {
    $mediaA = createMedia();
    $mediaB = createMedia();
    $mediaC = createMedia();
    $post = Post::create(['title' => 'hello']);

    $post->syncMedia([$mediaA->id, $mediaB->id], 'gallery');
    expect(MediaUsage::where('field', 'gallery')->count())->toBe(2)
        ->and($mediaA->fresh()->ref_count)->toBe(1)
        ->and($mediaB->fresh()->ref_count)->toBe(1);

    $post->syncMedia([$mediaB->id, $mediaC->id], 'gallery');
    expect(MediaUsage::where('field', 'gallery')->pluck('media_id')->all())->toBe([$mediaB->id, $mediaC->id])
        ->and($mediaB->fresh()->ref_count)->toBe(1)
        ->and($mediaC->fresh()->ref_count)->toBe(1);

    $post->syncMedia([], 'gallery');
    expect(MediaUsage::where('field', 'gallery')->count())->toBe(0);
});

it('detach of a missing usage is a no-op', function (): void {
    $post = Post::create(['title' => 'hello']);

    expect($post->detachMedia(999, 'cover'))->toBeFalse();
});
