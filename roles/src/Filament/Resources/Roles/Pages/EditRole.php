<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Roles\Filament\Resources\Roles\Pages;

use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Override;
use Quansitech\Cmf\Auditing\Support\AuditLogger;
use Quansitech\Cmf\Roles\Filament\Resources\Roles\RoleResource;

class EditRole extends EditRecord
{
    public Collection $permissions;

    /**
     * 保存前的权限名快照，用于 afterSave 中对比补记审计。
     *
     * @var list<string>
     */
    protected array $oldPermissionNames = [];

    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    #[Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->oldPermissionNames = $this->record->permissions()->pluck('name')->sort()->values()->all();

        $this->permissions = collect($data)
            ->filter(fn (mixed $permission, string $key): bool => ! in_array($key, ['name', 'guard_name', 'select_all', Utils::getTenantModelForeignKey()], true))
            ->values()
            ->flatten()
            ->unique();

        if (Utils::isTenancyEnabled() && Arr::has($data, Utils::getTenantModelForeignKey()) && filled($data[Utils::getTenantModelForeignKey()])) {
            return Arr::only($data, ['name', 'guard_name', Utils::getTenantModelForeignKey()]);
        }

        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterSave(): void
    {
        $permissionModels = collect();
        $this->permissions->each(function (string $permission) use ($permissionModels): void {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->data['guard_name'],
            ]));
        });

        // @phpstan-ignore-next-line
        $this->record->syncPermissions($permissionModels);

        // syncPermissions 走 pivot 不触发模型事件，对比快照手动补记审计
        $newPermissionNames = $this->record->permissions()->pluck('name')->sort()->values()->all();

        AuditLogger::custom(
            $this->record,
            'sync',
            ['permissions' => $this->oldPermissionNames],
            ['permissions' => $newPermissionNames],
        );
    }
}
