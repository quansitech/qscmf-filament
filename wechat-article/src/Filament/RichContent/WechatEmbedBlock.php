<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Filament\RichContent;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Quansitech\Cmf\WechatArticle\Support\WechatArticleStore;
use Quansitech\Cmf\WechatArticle\Support\WechatEmbedView;

/**
 * RichEditor 自定义块：公众号文章（只读嵌入）。
 *
 * 编辑器工具栏「自定义块」→ 选「公众号文章」→ 粘贴链接 → 插入为原子块，
 * 块内直接预览原文（沙箱 iframe）；保存内容记录时抓取并存库，前台由
 * WechatEmbedView::render 展开为 iframe。
 *
 * 块标题栏的「重新抓取」按钮由 WechatEditorPlugin 注入的 JS 渲染（不在此弹窗内）。
 */
final class WechatEmbedBlock extends RichContentCustomBlock
{
    public const ID = 'wechat-embed';

    public static function getId(): string
    {
        return self::ID;
    }

    public static function getLabel(): string
    {
        return '公众号文章';
    }

    public static function getIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedGlobeAlt;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('嵌入公众号文章')
            ->modalDescription('粘贴微信公众号文章链接，插入后为不可编辑的原文嵌入块，前台按原文展示。')
            ->modalSubmitActionLabel(fn (array $arguments): string => ($arguments['mode'] ?? 'insert') === 'edit' ? '保存' : '插入文章')
            ->schema([
                TextInput::make('url')
                    ->label('文章链接')
                    ->placeholder('https://mp.weixin.qq.com/s/……')
                    ->required()
                    ->url()
                    ->startsWith('https://mp.weixin.qq.com')
                    ->helperText('目前支持微信公众号文章（mp.weixin.qq.com）。插入后可在块的标题栏点「重新抓取」更新内容。'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        return '公众号文章';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): ?string
    {
        $url = (string) ($config['url'] ?? '');

        // 用库中抓取时间做版本号：重新抓取后保存块，预览 iframe 会带新的 v 参数重新加载。
        // 版本查询走 WechatArticleStore 的短缓存，避免编辑器内多块渲染时重复查库（N+1）。
        $version = app(WechatArticleStore::class)->versionFor($url);

        return WechatEmbedView::iframeHtml($url, null, $version);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return self::toPreviewHtml($config);
    }
}
