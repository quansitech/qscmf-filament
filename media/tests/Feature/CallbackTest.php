<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;

beforeEach(function (): void {
    Storage::fake('cmf-media-tos');
});

it('creates a media record on callback with uploader', function (): void {
    $user = actingAsTestUser();

    $hash = str_repeat('a', 32);
    $path = Media::objectKey($hash, 'jpg');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ])->assertOk()
        ->assertJsonPath('media.hash', $hash)
        ->assertJsonPath('media.path', $path);

    $media = Media::where('hash', $hash)->sole();
    expect($media->uploader_id)->toBe($user->id)
        ->and($media->disk)->toBe('tos')
        ->and($media->size)->toBe(100);
});

it('rejects callback when the object does not exist', function (): void {
    actingAsTestUser();

    $hash = str_repeat('b', 32);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => Media::objectKey($hash, 'jpg'),
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('path');

    expect(Media::where('hash', $hash)->exists())->toBeFalse();
});

it('rejects callback when size mismatches the remote object', function (): void {
    actingAsTestUser();

    $hash = str_repeat('c', 32);
    $path = Media::objectKey($hash, 'jpg');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 999,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size');
});

it('rejects callback when etag mismatches the reported hash （防伪）', function (): void {
    actingAsTestUser();

    $hash = str_repeat('d', 32);
    $path = Media::objectKey($hash, 'jpg');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    // 云端对象的 ETag 是另一个文件的 MD5 → 冒领他人文件
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.str_repeat('9', 32).'"'])]);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('hash');
});

it('rejects callback when path does not follow the server key rule', function (): void {
    actingAsTestUser();

    $hash = str_repeat('e', 32);

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => 'hacked/'.$hash.'.jpg',
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('path');
});

it('deduplicates concurrent callbacks of the same hash', function (): void {
    actingAsTestUser();

    $hash = str_repeat('f', 32);
    $path = Media::objectKey($hash, 'jpg');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $payload = [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ];

    $first = $this->postJson(route('cmf-media.callback'), $payload)->assertOk();
    $second = $this->postJson(route('cmf-media.callback'), $payload)->assertOk();

    expect(Media::where('hash', $hash)->count())->toBe(1)
        ->and($second->json('media.id'))->toBe($first->json('media.id'));
});

it('restores a soft deleted record when the same hash is uploaded again', function (): void {
    \Illuminate\Support\Facades\Queue::fake();
    actingAsTestUser();

    $hash = str_repeat('0', 32);
    $path = Media::objectKey($hash, 'jpg');
    Storage::disk('cmf-media-tos')->put($path, str_repeat('x', 100));
    Http::fake(['*' => Http::response(null, 200, ['ETag' => '"'.$hash.'"'])]);

    $media = createMedia(['hash' => $hash, 'path' => $path, 'size' => 100]);
    $media->delete();

    $this->postJson(route('cmf-media.callback'), [
        'hash' => $hash,
        'path' => $path,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 100,
    ])->assertOk();

    expect(Media::where('hash', $hash)->count())->toBe(1)
        ->and($media->fresh()->trashed())->toBeFalse();
});
