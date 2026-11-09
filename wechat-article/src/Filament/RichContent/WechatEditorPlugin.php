<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Filament\RichContent;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Illuminate\Support\Facades\URL;
use Tiptap\Core\Extension;

/**
 * 给 RichEditor 注入「公众号文章」块的前端增强脚本。
 *
 * 只负责 JS：在公众号块的标题栏渲染一个「重新抓取」按钮（见
 * resources/js/wechat-embed-refetch.js），不提供任何额外的 PHP 节点或工具栏按钮。
 */
final class WechatEditorPlugin implements RichContentPlugin
{
    public static function make(): static
    {
        return app(self::class);
    }

    /**
     * @return array<Extension>
     */
    public function getTipTapPhpExtensions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    public function getTipTapJsExtensions(): array
    {
        // 相对地址：由浏览器按 document.baseURI 解析，避免绑定 APP_URL 的绝对域名。
        // 附带文件 mtime 作为版本号，脚本更新后浏览器会重新拉取（避免长缓存）。
        $path = __DIR__.'/../../../resources/js/wechat-embed-refetch.js';
        $version = is_file($path) ? (string) filemtime($path) : '1';

        return [URL::route('site.wechat.editor-js', ['v' => $version], false)];
    }

    /**
     * @return array<RichEditorTool>
     */
    public function getEditorTools(): array
    {
        return [];
    }

    /**
     * @return array<Action>
     */
    public function getEditorActions(): array
    {
        return [];
    }
}
