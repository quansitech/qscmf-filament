<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Quansitech\Cmf\WechatArticle\Models\WechatArticle;
use Throwable;

/**
 * 公众号文章抓取结果存取。
 *
 * 保存内容记录时，把正文里出现的公众号嵌入块（自定义块）逐个抓取并写入
 * wechat_articles 表，做到「提交即抓」；前台渲染时直接读库，不再实时抓取。
 */
final class WechatArticleStore
{
    public function __construct(private readonly WechatSnapshotter $snapshotter) {}

    /**
     * 抓取并保存正文里所有公众号嵌入块的原文；已存在的文章默认跳过。
     *
     * 单个块抓取失败不中断其余块（公开访问 embed 时有兜底补抓），返回失败的链接列表。
     *
     * @return list<string>
     */
    public function sync(?string $html, bool $force = false): array
    {
        $failed = [];

        foreach ($this->urls($html) as $url) {
            try {
                $this->fetch($url, $force);
            } catch (Throwable $e) {
                report($e);
                $failed[] = $url;
            }
        }

        return $failed;
    }

    /**
     * 从富文本 HTML 中提取公众号嵌入块里的文章链接。
     *
     * @return list<string>
     */
    public function urls(?string $html): array
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, 'data-type="customBlock"')) {
            return [];
        }

        $xpath = $this->xpath($html);
        $urls = [];

        foreach ($xpath->query('//div[@data-type="customBlock"]') ?: [] as $node) {
            if (! $node instanceof DOMElement || $node->getAttribute('data-id') !== 'wechat-embed') {
                continue;
            }

            $config = json_decode((string) $node->getAttribute('data-config'), true);
            $url = is_array($config) ? trim((string) ($config['url'] ?? '')) : '';

            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $urls[$url] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * 抓取单篇文章并落库（已存在且 content 非空则直接复用）。
     */
    public function fetch(string $url, bool $force = false): WechatArticle
    {
        $article = WechatArticle::query()->firstOrNew(['url' => $url]);

        if (! $force && filled($article->content)) {
            return $article;
        }

        $payload = $this->snapshotter->snapshotWithMedia($url);

        $article->fill([
            'content' => (string) ($payload['html'] ?? ''),
            'media_ids' => array_values(array_map('intval', (array) ($payload['mediaIds'] ?? []))),
            'fetched_at' => now(),
        ]);

        try {
            $article->save();
        } catch (QueryException $e) {
            // 并发首次抓取同一 url 时会撞唯一索引：返回另一请求已写入的记录即可
            if ($this->isDuplicateKey($e)) {
                $existing = WechatArticle::query()->where('url', $url)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }

        // 重新抓取会更换正文图片：把引用该文章的记录上的媒体引用同步到新图片，
        // 避免新图片因 ref_count=0 在 24h 后被孤儿清理删掉。
        $this->resyncRecordUsages($url, (array) $article->media_ids);

        $this->forgetVersionCache($url);

        return $article;
    }

    /**
     * 块预览版本号（fetched_at 时间戳），带短缓存避免编辑器多块渲染时重复查库。
     */
    public function versionFor(?string $url): ?int
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $key = $this->versionCacheKey($url);

        // TTL 复用 cache_ttl 配置（此前无消费点）
        $fetchedAt = Cache::remember($key, now()->addMinutes(max(1, (int) config('cmf-wechat-article.cache_ttl', 30))), function () use ($url): ?string {
            $value = WechatArticle::query()->where('url', $url)->value('fetched_at');

            return $value === null ? null : (string) $value;
        });

        if ($fetchedAt === null || $fetchedAt === '') {
            return null;
        }

        return (int) strtotime($fetchedAt);
    }

    private function forgetVersionCache(string $url): void
    {
        Cache::forget($this->versionCacheKey($url));
    }

    private function versionCacheKey(string $url): string
    {
        return 'wechat-article-fetched-at:'.sha1($url);
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        $code = (string) ($e->errorInfo[1] ?? '');

        // MySQL 1062 / PostgreSQL 23505
        return in_array($code, ['1062', '23505'], true);
    }

    /**
     * 把引用该文章 url 的业务记录上的 content 字段媒体引用刷新为该文章当前的图片。
     *
     * @param  array<int|string, mixed>  $mediaIds
     */
    private function resyncRecordUsages(string $url, array $mediaIds): void
    {
        $modelClass = (string) config('cmf-wechat-article.content_model', '');

        if ($modelClass === '' || ! class_exists($modelClass)) {
            return;
        }

        if (! method_exists($modelClass, 'query')) {
            return;
        }

        $fields = (array) config('cmf-wechat-article.content_fields', ['content']);
        $incoming = array_map('intval', $mediaIds);

        // LIKE 转义：url 里的 %/_ 及字面反斜杠都要处理；且 data-config 可能是
        // json_encode 转义过的形态（/ 写作 \/），两种形态都要匹配
        $likeEscape = static fn (string $value): string => str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
        $plainPattern = '%'.$likeEscape($url).'%';
        $escapedPattern = '%'.$likeEscape(str_replace('/', '\\/', $url)).'%';

        foreach ($fields as $field) {
            $modelClass::query()
                ->where(function ($query) use ($field, $plainPattern, $escapedPattern): void {
                    $query->where($field, 'like', $plainPattern)
                        ->orWhere($field, 'like', $escapedPattern);
                })
                ->get()
                ->each(function ($record) use ($field, $url, $incoming): void {
                    $html = (string) $record->getAttribute($field);

                    // LIKE 只是粗筛（可能子串误匹配），用 urls() 精确确认该字段确实嵌了这篇
                    // 文章（data-config 里 json_encode 会把 / 转成 \/，不能直接 str_contains）
                    if (! in_array($url, $this->urls($html), true)) {
                        return;
                    }

                    if (! method_exists($record, 'syncMedia')) {
                        return;
                    }

                    // syncMedia 是差集覆盖语义：必须把「编辑器直插图片 img[data-id]」
                    // 也纳入集合，否则它们会被 detach，最终被孤儿清理删掉媒体文件。
                    $ids = array_merge(
                        $this->imageIdsIn($html),
                        $this->mediaIdsFor($html),
                        $incoming,
                    );
                    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

                    $record->syncMedia($ids, $field);
                });
        }
    }

    /**
     * 正文里编辑器直插图片（img[data-id]）的媒体 id。
     *
     * @return list<int>
     */
    public function imageIdsIn(?string $html): array
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, 'data-id')) {
            return [];
        }

        $ids = [];

        foreach (($this->xpath($html)->query('//img[@data-id]') ?: []) as $node) {
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
     * 正文里所有公众号文章落地到媒体库的图片 id。
     *
     * @return list<int>
     */
    public function mediaIdsFor(?string $html): array
    {
        $urls = $this->urls($html);

        if ($urls === []) {
            return [];
        }

        $ids = WechatArticle::query()
            ->whereIn('url', $urls)
            ->get()
            ->flatMap(fn (WechatArticle $article): array => array_map('intval', (array) ($article->media_ids ?? [])))
            ->all();

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        return $ids;
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__wu">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }
}
