<?php

declare(strict_types=1);

use Quansitech\Cmf\Media\Models\Media;

it('check returns existing media for a known hash （秒传）', function (): void {
    actingAsTestUser();
    $media = createMedia();

    $this->postJson(route('cmf-media.check'), [
        'hash' => $media->hash,
        'size' => 1024,
        'mime' => 'image/jpeg',
    ])->assertOk()
        ->assertJsonPath('media.id', $media->id)
        ->assertJsonPath('media.hash', $media->hash);
});

it('check restores soft deleted media on hit', function (): void {
    \Illuminate\Support\Facades\Queue::fake();
    actingAsTestUser();
    $media = createMedia();
    $media->delete();

    $this->postJson(route('cmf-media.check'), [
        'hash' => $media->hash,
        'size' => 1024,
        'mime' => 'image/jpeg',
    ])->assertOk();

    expect($media->fresh()->trashed())->toBeFalse();
});

it('check returns 404 for unknown hash', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.check'), [
        'hash' => str_repeat('b', 32),
        'size' => 1024,
        'mime' => 'image/jpeg',
    ])->assertNotFound();
});

it('sign returns upload credential with server generated key', function (): void {
    actingAsTestUser();

    $hash = str_repeat('c', 32);

    $response = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
    ])->assertOk();

    expect($response->json('method'))->toBe('PUT')
        ->and($response->json('upload_url'))->toBeString()
        ->and($response->json('path'))->toBe('cc/'.$hash.'.jpg')
        ->and($response->json('disk'))->toBe('tos');
});

it('sign rejects oversize files with 422', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('d', 32),
        'name' => 'big.jpg',
        'mime' => 'image/jpeg',
        'size' => (int) config('cmf-media.max_size') + 1,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size');
});

it('sign rejects disallowed mime with 422', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('e', 32),
        'name' => 'evil.exe',
        'mime' => 'application/x-msdownload',
        'size' => 100,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('mime');
});

it('sign supports wildcard mime whitelist', function (): void {
    actingAsTestUser();

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('f', 32),
        'name' => 'clip.mp4',
        'mime' => 'video/mp4',
        'size' => 100,
    ])->assertOk();
});

it('denies upload endpoints without permission', function (): void {
    $user = actingAsTestUser(grantAll: false);

    $this->postJson(route('cmf-media.sign'), [
        'hash' => str_repeat('1', 32),
        'name' => 'a.jpg',
        'mime' => 'image/jpeg',
        'size' => 10,
    ])->assertForbidden();

    $this->postJson(route('cmf-media.check'), [
        'hash' => str_repeat('1', 32),
        'size' => 10,
        'mime' => 'image/jpeg',
    ])->assertForbidden();
});
