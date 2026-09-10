<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Users\Filament\Resources\Users;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;
use Quansitech\Cmf\Users\Filament\Resources\Users\Pages\CreateUser;
use Quansitech\Cmf\Users\Filament\Resources\Users\Pages\EditUser;
use Quansitech\Cmf\Users\Filament\Resources\Users\Pages\ListUsers;
use Quansitech\Cmf\Users\Filament\Resources\Users\Pages\ViewUser;
use Quansitech\Cmf\Users\Models\User;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;
use UnitEnum;

/**
 * 用户管理 Resource。
 *
 * 深度定制：php artisan cmf:extend users 生成宿主继承类后，
 * 按需覆写 getFormFields() / getTableColumns() 等方法即可，
 * 未覆写的部分仍随模块包升级。
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = '用户';

    protected static ?string $pluralModelLabel = '用户';

    /**
     * 模型走 config('cmf-users.model')，宿主替换模型后 Resource 自动指向新模型。
     */
    #[Override]
    public static function getModel(): string
    {
        return config('cmf-users.model') ?? parent::getModel();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('filament-shield::filament-shield.nav.group');
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema(static::getFormFields())
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<Component>
     */
    protected static function getFormFields(): array
    {
        return [
            TextInput::make('name')
                ->label('姓名')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('邮箱')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('password')
                ->label('密码')
                ->password()
                ->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->minLength(8)
                ->maxLength(255),
            Select::make('roles')
                ->label('角色')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                // pivot sync 不触发模型事件，改用 auditSync 让角色变更进审计
                ->saveRelationshipsUsing(function (Select $component): void {
                    $record = $component->getRecord();

                    if (! $record instanceof User) {
                        return;
                    }

                    $record->auditSync('roles', $component->getState() ?? []);
                }),
        ];
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components(static::getInfolistEntries());
    }

    /**
     * @return list<Component>
     */
    protected static function getInfolistEntries(): array
    {
        return [
            TextEntry::make('name')
                ->label('姓名'),
            TextEntry::make('email')
                ->label('邮箱'),
            TextEntry::make('roles.name')
                ->label('角色')
                ->badge(),
            TextEntry::make('created_at')
                ->label('创建时间')
                ->dateTime(),
        ];
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns(static::getTableColumns())
            ->filters([
                //
            ])
            ->recordActions(static::getTableRecordActions())
            ->toolbarActions(static::getTableToolbarActions());
    }

    /**
     * @return list<Column>
     */
    protected static function getTableColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label('姓名')
                ->weight(FontWeight::Medium)
                ->searchable(),
            TextColumn::make('email')
                ->label('邮箱')
                ->searchable(),
            TextColumn::make('roles.name')
                ->label('角色')
                ->badge()
                ->color('primary'),
            TextColumn::make('created_at')
                ->label('创建时间')
                ->dateTime()
                ->sortable(),
        ];
    }

    /**
     * @return list<Action>
     */
    protected static function getTableRecordActions(): array
    {
        return [
            ViewAction::make(),
            EditAction::make(),
        ];
    }

    /**
     * @return list<Action|BulkActionGroup>
     */
    protected static function getTableToolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                DeleteBulkAction::make(),
            ]),
        ];
    }

    #[Override]
    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
