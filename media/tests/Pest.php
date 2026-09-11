<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Quansitech\Cmf\Media\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Media\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * 创建测试用户并登录（默认授予全部 Gate 权限）。
 */
function actingAsTestUser(bool $grantAll = true): TestUser
{
    $user = TestUser::query()->create([
        'name' => '测试用户',
        'email' => 'tester@example.com',
        'password' => 'password',
    ]);

    Illuminate\Support\Facades\Auth::login($user);

    if ($grantAll) {
        grantAllPermissions();
    }

    return $user;
}

/**
 * 放行全部权限（等价宿主的 super_admin Gate::before）。
 */
function grantAllPermissions(): void
{
    Illuminate\Support\Facades\Gate::before(fn (): bool => true);
}

/**
 * 快速创建媒体记录。
 */
function createMedia(array $overrides = []): Quansitech\Cmf\Media\Models\Media
{
    $hash = $overrides['hash'] ?? bin2hex(random_bytes(16));

    return Quansitech\Cmf\Media\Models\Media::create([
        'disk' => 'tos',
        'path' => Quansitech\Cmf\Media\Models\Media::objectKey($hash, 'jpg'),
        'hash' => $hash,
        'original_name' => 'photo.jpg',
        'mime' => 'image/jpeg',
        'ext' => 'jpg',
        'size' => 1024,
        ...$overrides,
    ]);
}
