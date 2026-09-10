<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Filament\Resources\Audits;

use Filament\Actions\ViewAction;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Override;
use Quansitech\Cmf\Auditing\Actions\RestoreAuditAction;
use Quansitech\Cmf\Auditing\Filament\Resources\Audits\Pages\ListAudits;
use Quansitech\Cmf\Auditing\Filament\Resources\Audits\Pages\ViewAudit;
use Quansitech\Cmf\Auditing\Filament\Resources\Audits\Schemas\AuditInfolist;
use Tapp\FilamentAuditing\Filament\Resources\Audits\AuditResource as BaseAuditResource;
use UnitEnum;

/**
 * 继承 tapp/filament-auditing 的 AuditResource，中文化标签，
 * 并翻译 event 列值与审计对象类名。
 */
class AuditResource extends BaseAuditResource
{
    protected static ?string $modelLabel = '审计日志';

    protected static ?string $pluralModelLabel = '审计日志';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-shield::filament-shield.nav.group');
    }

    #[Override]
    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        foreach ($table->getColumns() as $column) {
            if (! $column instanceof TextColumn) {
                continue;
            }

            if ($column->getName() === 'event') {
                $column->formatStateUsing(function (string $state): string {
                    $key = "filament-auditing::filament-auditing.event.{$state}";

                    return Lang::has($key) ? trans($key) : $state;
                });
            }

            if ($column->getName() === 'auditable_type') {
                $column->formatStateUsing(function (string $state): string {
                    $key = 'filament-auditing::filament-auditing.model.'.Str::afterLast($state, '\\');

                    return Lang::has($key) ? trans($key) : Str::afterLast($state, '\\');
                });
            }
        }

        return $table->recordActions([
            ViewAction::make(),
            RestoreAuditAction::make('restore'),
        ]);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return AuditInfolist::configure($schema);
    }

    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAudits::route('/'),
            'view' => ViewAudit::route('/{record}'),
        ];
    }
}
