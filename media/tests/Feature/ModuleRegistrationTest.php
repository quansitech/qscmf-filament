<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Quansitech\Cmf\Core\Cmf;
use Quansitech\Cmf\Media\MediaPlugin;
use Quansitech\Cmf\Media\Models\Media;

it('registers the media plugin into CMF', function (): void {
    expect(Cmf::pluginClasses())->toContain(MediaPlugin::class);
});

it('creates cmf_media and cmf_media_usages tables after migrate', function (): void {
    expect(Schema::hasTable('cmf_media'))->toBeTrue()
        ->and(Schema::hasTable('cmf_media_usages'))->toBeTrue();
});

it('enforces unique hash index on cmf_media', function (): void {
    $attributes = [
        'disk' => 'tos',
        'path' => 'ab/'.str_repeat('a', 32).'.jpg',
        'hash' => str_repeat('a', 32),
        'original_name' => 'a.jpg',
        'mime' => 'image/jpeg',
        'ext' => 'jpg',
        'size' => 100,
    ];

    Media::create($attributes);

    Media::create([...$attributes, 'id' => null]);

    expect()->fail('重复 hash 未触发唯一索引');
})->throws(QueryException::class);

it('publishes config via cmf-config tag', function (): void {
    $target = config_path('cmf-media.php');

    if (file_exists($target)) {
        unlink($target);
    }

    $this->artisan('vendor:publish', ['--tag' => 'cmf-config', '--no-interaction' => true]);

    expect(file_exists($target))->toBeTrue();
});
