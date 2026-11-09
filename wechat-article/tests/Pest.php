<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\WechatArticle\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\WechatArticle\Tests\TestCase;

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

    Auth::login($user);

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
    Gate::before(fn (): bool => true);
}
