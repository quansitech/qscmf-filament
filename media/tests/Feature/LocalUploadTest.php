<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Quansitech\Cmf\Media\Models\Media;

beforeEach(function (): void {
    config(['cmf-media.default' => 'local']);
    Storage::fake('cmf-media-local');

    // upload 为 multipart 表单 POST：要求校验失败返回 JSON 422 而非 302 重定向
    $this->withHeaders(['Accept' => 'application/json']);
});

it('sign returns same-origin upload endpoint for local driver', function (): void {
    actingAsTestUser();

    $hash = str_repeat('a', 32);

    $response = $this->postJson(route('cmf-media.sign'), [
        'hash' => $hash,
        'name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'size' => 2048,
    ])->assertOk();

    expect($response->json('method'))->toBe('POST')
        ->and($response->json('upload_url'))->toBe(route('cmf-media.upload'))
        ->and($response->json('fields.hash'))->toBe($hash)
        ->and($response->json('path'))->toBe('aa/'.$hash.'.jpg')
        ->and($response->json('disk'))->toBe('local')
        ->and($response->json('local'))->toBeTrue();
});

it('upload stores the file at the hash path and creates the media record', function (): void {
    $user = actingAsTestUser();

    $file = UploadedFile::fake()->image('photo.jpg', 120, 80);
    $hash = md5($file->get());

    $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => $hash,
        'name' => 'photo.jpg',
    ])->assertOk()
        ->assertJsonPath('media.hash', $hash)
        ->assertJsonPath('media.disk', 'local')
        ->assertJsonPath('media.original_name', 'photo.jpg')
        ->assertJsonPath('media.mime', 'image/jpeg')
        ->assertJsonPath('media.width', 120)
        ->assertJsonPath('media.height', 80);

    $path = substr($hash, 0, 2).'/'.$hash.'.jpg';
    Storage::disk('cmf-media-local')->assertExists($path);

    $media = Media::query()->sole();
    expect($media->path)->toBe($path)
        ->and($media->uploader_id)->toBe($user->id)
        ->and($media->size)->toBe(strlen($file->get()));
});

it('upload reuses the existing record for duplicate content （秒传兜底）', function (): void {
    actingAsTestUser();

    $upload = fn (): int => $this->post(route('cmf-media.upload'), [
        'file' => UploadedFile::fake()->image('photo.jpg'),
        'hash' => md5(UploadedFile::fake()->image('photo.jpg')->get()),
        'name' => 'photo.jpg',
    ])->assertOk()->json('media.id');

    // fake()->image 每次生成相同内容：两次上传 hash 相同
    $first = $upload();
    $second = $upload();

    expect($second)->toBe($first)
        ->and(Media::query()->count())->toBe(1);
});

it('upload rejects hash mismatch reported by the client', function (): void {
    actingAsTestUser();

    $this->post(route('cmf-media.upload'), [
        'file' => UploadedFile::fake()->image('photo.jpg'),
        'hash' => str_repeat('0', 32), // 与真实内容指纹不符
        'name' => 'photo.jpg',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('hash');
});

it('upload rejects oversize files with 422', function (): void {
    actingAsTestUser();

    $file = UploadedFile::fake()->create('big.zip', (int) config('cmf-media.max_size') / 1024 + 1, 'application/zip');

    $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => md5($file->get()),
        'name' => 'big.zip',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('size');
});

it('upload rejects disallowed mime detected on the server side', function (): void {
    actingAsTestUser();

    $file = UploadedFile::fake()->create('evil.exe', 10);

    $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => md5($file->get()),
        'name' => 'evil.exe',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('mime');
});

it('upload is denied without permission', function (): void {
    actingAsTestUser(grantAll: false);

    $file = UploadedFile::fake()->image('photo.jpg');

    $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => md5($file->get()),
        'name' => 'photo.jpg',
    ])->assertForbidden();
});

it('upload endpoint is not available for cloud drivers', function (): void {
    config(['cmf-media.default' => 'tos']);
    actingAsTestUser();

    $file = UploadedFile::fake()->image('photo.jpg');

    $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => md5($file->get()),
        'name' => 'photo.jpg',
    ])->assertNotFound();
});

it('smoke command passes for local driver', function (): void {
    $this->artisan('cmf-media:smoke', ['--disk' => 'local'])->assertSuccessful();
});

it('check then instant-hit works for local driver （秒传）', function (): void {
    actingAsTestUser();

    $file = UploadedFile::fake()->image('photo.jpg');
    $hash = md5($file->get());

    $mediaId = $this->post(route('cmf-media.upload'), [
        'file' => $file,
        'hash' => $hash,
        'name' => 'photo.jpg',
    ])->assertOk()->json('media.id');

    $this->postJson(route('cmf-media.check'), [
        'hash' => $hash,
        'size' => 1024,
        'mime' => 'image/jpeg',
    ])->assertOk()
        ->assertJsonPath('media.id', $mediaId);
});
