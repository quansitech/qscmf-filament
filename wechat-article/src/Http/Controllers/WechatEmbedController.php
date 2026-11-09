<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Http\Controllers;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Quansitech\Cmf\WechatArticle\Models\WechatArticle;
use Quansitech\Cmf\WechatArticle\Support\WechatArticleStore;
use Quansitech\Cmf\WechatArticle\Support\WechatEmbedView;
use Throwable;

/**
 * 公众号文章嵌入内容：按签名 URL 返回库中已抓取的原文 HTML（供 iframe 渲染）。
 *
 * 正文在内容记录保存时已抓取落库（见 SavesWechatArticleEmbeds）；此处默认直接读库，
 * 仅在库中缺失时兜底抓取一次。若 URL 带 ref（承载该块的记录 id），则在该记录上
 * 登记图片媒体引用，避免孤儿清理。
 */
final class WechatEmbedController
{
    public function __invoke(Request $request)
    {
        $url = (string) $request->query('url', '');
        $token = (string) $request->query('t', '');
        $ref = (int) $request->query('ref', 0);

        // 校验与 url 绑定的令牌；失败返回纯文本（避免沙箱 iframe 里加载带脚本的错误页）
        if ($url === '' || ! hash_equals(WechatEmbedView::token($url), $token)) {
            return response('forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $html = '';
        $mediaIds = [];

        try {
            $article = WechatArticle::query()->where('url', $url)->first();

            if ($article === null || blank($article->content)) {
                // 兜底：库中尚无该文章（例如旧数据或直接访问），即时抓取并落库。
                // 该入口是公开的（iframe 无登录态），用限流防止被外部当免费抓取代理；
                // 限流的 429 抛出 HttpResponseException，必须在下面透传，不能被兜底 catch 吞掉。
                $this->ensureFetchRateLimited($request, $url);

                $article = app(WechatArticleStore::class)->fetch($url);
            }

            $html = (string) $article->content;
            $mediaIds = array_values(array_map('intval', (array) ($article->media_ids ?? [])));

            if ($ref > 0 && $mediaIds !== []) {
                $this->attachMediaUsage($ref, $url, $mediaIds);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            // 面向公众统一文案，异常细节（可能带出下游信息）只进日志
            $html = '<div style="padding:24px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#b91c1c;font-size:14px;">'
                .'公众号文章暂时无法加载，请稍后再试。</div>';
            $mediaIds = [];
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // 允许同源（含 http 本地媒体驱动）与 https 的图片/样式/字体，否则本地落地的
            // /cmf-media 图片会被 CSP 拦掉；脚本仍由 default-src 'none' 完全禁用。
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' https: http: data: blob:; style-src 'self' 'unsafe-inline' https: http: data:; font-src 'self' https: http: data:; media-src 'self' https: http: data:; frame-ancestors 'self';",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * 兜底抓取限流：同一 url 每分钟 2 次、同一 IP 每分钟 10 次，超出直接返回错误文案。
     */
    private function ensureFetchRateLimited(Request $request, string $url): void
    {
        $urlKey = 'wechat-embed-url:'.sha1($url);
        $ipKey = 'wechat-embed-ip:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($urlKey, 2) || RateLimiter::tooManyAttempts($ipKey, 10)) {
            abort(response(
                '<div style="padding:24px;font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#92400e;font-size:14px;">抓取过于频繁，请稍后再试。</div>',
                429,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ));
        }

        RateLimiter::hit($urlKey, 60);
        RateLimiter::hit($ipKey, 60);
    }

    /**
     * 在承载记录的富文本字段上登记图片引用（幂等，按 ref+url 短周期去重）。
     * 字段按 config('cmf-wechat-article.content_fields') 逐个判断块是否真的出现在该字段里。
     *
     * @param  list<int>  $mediaIds
     */
    private function attachMediaUsage(int $ref, string $url, array $mediaIds): void
    {
        /** @var class-string $modelClass */
        $modelClass = (string) config('cmf-wechat-article.content_model', '');

        if (! class_exists($modelClass)) {
            return;
        }

        $content = $modelClass::query()->find($ref);

        if ($content === null || ! method_exists($content, 'mediaUsages') || ! method_exists($content, 'syncMedia')) {
            return;
        }

        // 护栏放到所有校验与同步成功之后：避免无效 ref/字段误占 7 天去重窗口
        $guardKey = 'cmf-wechat-embed-ref:'.$ref.':'.sha1($url);

        if (! Cache::add($guardKey, 1, now()->addDays(7))) {
            return;
        }

        $fields = (array) config('cmf-wechat-article.content_fields', ['content']);

        foreach ($fields as $field) {
            $field = (string) $field;

            // 用 urls() 精确判定块是否出现在该字段（data-config 里 / 被转义成 \/，不能 str_contains）
            if ($field === '' || ! in_array($url, app(WechatArticleStore::class)->urls((string) $content->getAttribute($field)), true)) {
                continue;
            }

            /** @var list<int> $existing */
            $existing = $content->mediaUsages()
                ->where('field', $field)
                ->pluck('media_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $content->syncMedia(array_values(array_unique(array_merge($existing, $mediaIds))), $field);
        }
    }
}
