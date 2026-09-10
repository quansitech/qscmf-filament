<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Actions;

use Filament\Schemas\Components\Section;
use Override;
use Tapp\FilamentAuditing\Filament\Actions\RestoreAuditAction as BaseRestoreAuditAction;
use Tapp\FilamentAuditing\Filament\Infolists\Components\AuditValuesEntry;

/**
 * 覆盖 tapp/filament-auditing 回滚弹窗中硬编码的英文区块标题；
 * 用 AuditValuesEntry 渲染，字段名经语言包翻译为中文。
 */
class RestoreAuditAction extends BaseRestoreAuditAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->schema([
            Section::make(trans('filament-auditing::filament-auditing.restore.from'))
                ->schema([
                    AuditValuesEntry::make('new_values')
                        ->hiddenLabel(),
                ]),
            Section::make(trans('filament-auditing::filament-auditing.restore.to'))
                ->schema([
                    AuditValuesEntry::make('old_values')
                        ->hiddenLabel(),
                ]),
        ]);
    }
}
