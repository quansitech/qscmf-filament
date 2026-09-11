<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Quansitech\Cmf\Media\Filament\Resources\Media\MediaResource;
use Quansitech\Cmf\Media\Models\Media;

it('registers shield permission points for the resource', function (): void {
    $manage = config('filament-shield.resources.manage');

    expect($manage)->toHaveKey(MediaResource::class)
        ->and($manage[MediaResource::class])->toBe(['viewAny', 'view', 'create', 'delete']);
});

it('denies resource access for users without permission (403)', function (): void {
    actingAsTestUser(grantAll: false);

    expect(Gate::forUser(auth()->user())->allows('viewAny', Media::class))->toBeFalse();

    $this->get(MediaResource::getUrl('index'))->assertForbidden();
});

it('allows resource access once the permission is granted', function (): void {
    actingAsTestUser(grantAll: false);

    Gate::define('ViewAny:Media', fn (): bool => true);

    $this->get(MediaResource::getUrl('index'))->assertOk();
});

it('policy maps abilities to shield permission names', function (): void {
    $user = actingAsTestUser(grantAll: false);

    foreach (['ViewAny:Media', 'View:Media', 'Create:Media', 'Delete:Media'] as $permission) {
        expect($user->can($permission))->toBeFalse();
    }

    Gate::define('Create:Media', fn (): bool => true);
    expect(Gate::forUser($user)->allows('create', Media::class))->toBeTrue();
});

it('fails loudly when audit is enabled without owen-it/laravel-auditing', function (): void {
    config()->set('cmf-media.audit', true);

    $provider = new \Quansitech\Cmf\Media\CmfMediaServiceProvider(app());
    $method = new ReflectionMethod($provider, 'configureAuditModel');
    $method->invoke($provider);
})->throws(RuntimeException::class, 'owen-it/laravel-auditing');
