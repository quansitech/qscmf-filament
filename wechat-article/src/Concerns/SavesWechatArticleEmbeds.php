<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Concerns;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\WechatArticle\Models\WechatArticle;
use Quansitech\Cmf\WechatArticle\Support\WechatArticleStore;
use Throwable;

/**
 * 让「正文里含公众号嵌入块」的模型在保存时即抓取文章并落库。
 *
 * 用法：在模型里 `use SavesWechatArticleEmbeds;`（模型需使用 cmf-media 的 HasMedia
 * 才能登记图片引用；没有 HasMedia 时仍会抓取落库，只是不登记图片引用）。
 *
 * 抓取字段默认取 config('cmf-wechat-article.content_fields')，默认 ['content']。
 */
trait SavesWechatArticleEmbeds
{
    public static function bootSavesWechatArticleEmbeds(): void
    {
        static::saving(function (Model $model): void {
            $store = app(WechatArticleStore::class);

            foreach (static::wechatArticleEmbedFields() as $field) {
                if (! $model->isDirty($field)) {
                    continue;
                }

                try {
                    // sync 单块失败不中断其余块，返回失败链接列表；
                    // 前台渲染时 embed 控制器仍会按需兜底抓取（带限流）。
                    $failed = $store->sync((string) $model->getAttribute($field));

                    if ($failed !== []) {
                        static::notifyWechatArticlesFailed('部分公众号文章抓取失败：'.implode('、', $failed));
                    }
                } catch (Throwable $e) {
                    report($e);

                    static::notifyWechatArticlesFailed($e->getMessage());
                }
            }
        });

        static::saved(function (Model $model): void {
            if (! method_exists($model, 'syncMedia')) {
                return;
            }

            $store = app(WechatArticleStore::class);

            $fetchedUrls = [];

            foreach (static::wechatArticleEmbedFields() as $field) {
                if (! $model->wasRecentlyCreated && ! $model->wasChanged($field)) {
                    continue;
                }

                $html = (string) $model->getAttribute($field);

                $ids = array_merge(
                    static::wechatArticleImageIdsIn($html),
                    $store->mediaIdsFor($html),
                );

                $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

                $model->syncMedia($ids, $field);

                $fetchedUrls = array_merge($fetchedUrls, $store->urls($html));
            }

            static::notifyWechatArticlesFetched(array_values(array_unique($fetchedUrls)));
        });
    }

    /**
     * 需要处理公众号嵌入块的字段。
     *
     * @return list<string>
     */
    protected static function wechatArticleEmbedFields(): array
    {
        $fields = array_values(array_filter((array) config('cmf-wechat-article.content_fields', ['content'])));

        return $fields === [] ? ['content'] : $fields;
    }

    /**
     * 正文里已有的 `img[data-id]` 图片 id（保留编辑器里其它图片的媒体引用）。
     *
     * @return list<int>
     */
    protected static function wechatArticleImageIdsIn(mixed $html): array
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, 'data-id')) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__wm">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $ids = [];

        foreach ((new DOMXPath($doc))->query('//img[@data-id]') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $id = (int) $node->getAttribute('data-id');

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * 保存时抓取失败给后台操作者一个提示（仅在 HTTP 请求里发送，避免队列/命令环境报错）。
     */
    protected static function notifyWechatArticlesFailed(string $message): void
    {
        if ($message === '' || app()->runningInConsole() || ! class_exists(Notification::class)) {
            return;
        }

        if (! request()->hasSession() || filament()->getCurrentPanel() === null) {
            return;
        }

        Notification::make()
            ->title('公众号文章抓取失败')
            ->body($message.' 本次保存已继续；前台首次访问该文章时会自动重试。')
            ->warning()
            ->send();
    }

    /**
     * 保存后给后台操作者一个抓取结果提示（仅在 HTTP 请求里发送，避免队列/命令环境报错）。
     *
     * @param  list<string>  $urls
     */
    protected static function notifyWechatArticlesFetched(array $urls): void
    {
        if ($urls === [] || app()->runningInConsole() || ! class_exists(Notification::class)) {
            return;
        }

        // 仅在有会话的后台面板请求里提示；API/队列等无会话环境直接跳过
        if (! request()->hasSession() || filament()->getCurrentPanel() === null) {
            return;
        }

        $images = (int) WechatArticle::query()->whereIn('url', $urls)->get()
            ->sum(fn ($article): int => count((array) $article->media_ids));

        Notification::make()
            ->title('已抓取公众号文章')
            ->body('共 '.count($urls).' 篇，图片 '.$images.' 张，已保存为原文快照。')
            ->success()
            ->send();
    }
}
