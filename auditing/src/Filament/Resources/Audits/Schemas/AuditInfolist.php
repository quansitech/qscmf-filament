<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Auditing\Filament\Resources\Audits\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tapp\FilamentAuditing\Filament\Infolists\Components\AuditValuesEntry;

/**
 * 基于 tapp/filament-auditing 的 AuditInfolist 调整：
 * event 值翻译为中文；变更前/后用 AuditValuesEntry（字段名经语言包翻译）。
 */
class AuditInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Tabs')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make(trans('filament-auditing::filament-auditing.infolist.tab.info'))
                            ->schema([
                                TextEntry::make('user.name')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.user')),
                                TextEntry::make('created_at')
                                    ->dateTime('Y-m-d H:i:s')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.created-at')),
                                TextEntry::make('auditable_type')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.audited'))
                                    ->formatStateUsing(function (string $state): string {
                                        $key = 'filament-auditing::filament-auditing.model.'.Str::afterLast($state, '\\');

                                        return Lang::has($key) ? trans($key) : Str::afterLast($state, '\\');
                                    }),
                                TextEntry::make('event')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.event'))
                                    ->formatStateUsing(function (string $state): string {
                                        $key = "filament-auditing::filament-auditing.event.{$state}";

                                        return Lang::has($key) ? trans($key) : $state;
                                    }),
                                TextEntry::make('url')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.url'))
                                    ->columnSpanFull(),
                                TextEntry::make('ip_address')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.ip-address')),
                                TextEntry::make('user_agent')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.user-agent'))
                                    ->columnSpanFull(),
                                TextEntry::make('tags')
                                    ->label(trans('filament-auditing::filament-auditing.infolist.tags'))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Tab::make(trans('filament-auditing::filament-auditing.infolist.tab.old-values'))
                            ->schema([
                                AuditValuesEntry::make('old_values')
                                    ->hiddenLabel(),
                            ]),
                        Tab::make(trans('filament-auditing::filament-auditing.infolist.tab.new-values'))
                            ->schema([
                                AuditValuesEntry::make('new_values')
                                    ->hiddenLabel(),
                            ]),
                    ]),
            ]);
    }
}
