<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Quansitech\Cmf\Area\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Area\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Unit');

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
 * 只迁移模块的建表/结构迁移（跳过 4 万行的全量数据 seed，加速测试）。
 */
function migrateAreaSchema(): void
{
    foreach (glob(__DIR__.'/../database/migrations/2026_09_1*_*.php') ?: [] as $file) {
        if (str_contains(basename($file), '_seed_')) {
            continue;
        }
        Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => $file, '--realpath' => true]);
    }
}

/**
 * 快速写入地区行。
 */
function createArea(array $attributes): Quansitech\Cmf\Area\Models\Area
{
    return Quansitech\Cmf\Area\Models\Area::query()->create([
        'pid' => 0,
        'deep' => 0,
        'name' => '测试',
        'pinyin_prefix' => 'c',
        'pinyin' => 'ce shi',
        'ext_id' => $attributes['id'] * 1000000,
        'ext_name' => '测试地区',
        'status' => 1,
        ...$attributes,
    ]);
}

/**
 * 生成迷你 csv fixture（v2 语义用例用），返回文件路径。
 * 行格式：[id, pid, deep, name, ext_name]（pinyin/ext_id 自动填充）。
 *
 * @param  list<array{int, int, int, string, string}>  $rows
 */
function writeAreaCsvFixture(string $fileName, array $rows): string
{
    $path = sys_get_temp_dir().'/'.$fileName;
    $fh = fopen($path, 'w');
    fputcsv($fh, ['id', 'pid', 'deep', 'name', 'pinyin_prefix', 'pinyin', 'ext_id', 'ext_name']);
    foreach ($rows as [$id, $pid, $deep, $name, $extName]) {
        fputcsv($fh, [$id, $pid, $deep, $name, 'c', 'ce shi', $id * 1000000, $extName]);
    }
    fclose($fh);

    return $path;
}
