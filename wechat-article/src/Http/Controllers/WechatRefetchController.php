<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Http\Controllers;

use Filament\FilamentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Quansitech\Cmf\WechatArticle\Support\WechatArticleStore;
use Quansitech\Cmf\WechatArticle\Support\WechatEmbedView;
use Throwable;

/**
 * 强制重新抓取某篇公众号文章（覆盖 wechat_articles 中的快照与图片）。
 *
 * 由块标题栏的「重新抓取」按钮调用；URL 带 url + t（与 embed 相同的 HMAC 令牌）。
 */
final class WechatRefetchController
{
    public function __invoke(Request $request): JsonResponse
    {
        // 仅后台登录用户可用（编辑器按钮）；CSRF 由 web 中间件组校验
        $authenticated = auth()->check()
            || (class_exists(FilamentManager::class)
                && filament()->getCurrentPanel() !== null
                && filament()->auth()->check());

        if (! $authenticated) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        $url = (string) $request->input('url', '');
        $token = (string) $request->input('t', '');

        if ($url === '' || ! hash_equals(WechatEmbedView::token($url), $token)) {
            return response()->json(['ok' => false, 'message' => 'forbidden'], 403);
        }

        try {
            $article = app(WechatArticleStore::class)->fetch($url, force: true);

            $mediaIds = (array) $article->media_ids;

            return response()->json([
                'ok' => true,
                'length' => mb_strlen(trim(strip_tags((string) $article->content))),
                'images' => count($mediaIds),
                'fetched_at' => $article->fetched_at?->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => '重新抓取失败：'.$e->getMessage(),
            ], 422);
        }
    }
}
