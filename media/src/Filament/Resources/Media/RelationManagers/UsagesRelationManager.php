<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Filament\Resources\Media\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;

/**
 * 媒体引用明细（详情页）：哪些模型的哪个字段在引用该媒体。
 */
class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    protected static ?string $title = '引用明细';

    #[Override]
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('usable_type')->label('引用模型'),
                TextColumn::make('usable_id')->label('模型 ID'),
                TextColumn::make('field')->label('字段')->badge(),
                TextColumn::make('created_at')->label('引用时间')->dateTime(),
            ])
            ->paginated(false);
    }
}
