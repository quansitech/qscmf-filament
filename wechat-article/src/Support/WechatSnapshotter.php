<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 抓取公众号文章正文（返回只读 HTML 快照）。
 *
 * 安全要点（URL 校验统一交给 SafeUrlValidator）：
 * - host 白名单、仅公网 IP、禁重定向、限制超时与体积；
 * - 抓取结果清洗（去脚本/事件属性/javascript: 协议）。
 */
final class WechatSnapshotter
{
    public function __construct(
        private readonly RemoteImageLocalizer $imageLocalizer,
        private readonly SafeUrlValidator $urlValidator,
    ) {}

    public function snapshot(string $url): string
    {
        return $this->fetchCleanHtml($url);
    }

    /**
     * 抓取正文并把正文里的外链图片下载落地到媒体库。
     *
     * @return array{html: string, mediaIds: list<int>}
     */
    public function snapshotWithMedia(string $url): array
    {
        return $this->localizeImages($this->fetchCleanHtml($url));
    }

    private function fetchCleanHtml(string $url): string
    {
        $cfg = (array) config('cmf-wechat-article');
        $allowedHosts = (array) ($cfg['allowed_hosts'] ?? ['mp.weixin.qq.com', 'weixin.qq.com']);

        $safe = $this->urlValidator->validate($url, $allowedHosts, '链接');
        $url = $safe['url'];

        $response = Http::withOptions([
            'allow_redirects' => false,
            'verify' => (bool) ($cfg['verify_tls'] ?? true),
            // 用校验阶段解析出的 IP 发起连接（SNI/Host 仍是域名），消除
            // 「校验一次解析、Guzzle 再解析一次」之间的 DNS rebinding 窗口
            'curl' => $this->pinResolveOptions(
                $safe['host'],
                $safe['port'] ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80),
                $safe['ips'],
            ),
        ])
            ->withHeaders([
                'User-Agent' => (string) ($cfg['user_agent'] ?? 'Mozilla/5.0'),
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->connectTimeout((int) ($cfg['connect_timeout'] ?? 5))
            ->timeout((int) ($cfg['timeout'] ?? 15))
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('抓取失败：HTTP '.$response->status());
        }

        $body = (string) $response->body();

        if (strlen($body) > (int) ($cfg['max_bytes'] ?? 5 * 1024 * 1024)) {
            throw new RuntimeException('内容超过大小上限');
        }

        $html = $this->clean($this->extractContent($body));

        if (trim(strip_tags($html)) === '' && ! str_contains($html, '<img')) {
            throw new RuntimeException('未能解析到文章正文（可能已删除或受限）');
        }

        return $html;
    }

    /**
     * 生成 Guzzle 的 curl CURLOPT_RESOLVE 选项，把域名钉到校验阶段解析出的公网 IP。
     *
     * @param  array<int, string>  $ips
     * @return array<string, mixed>
     */
    private function pinResolveOptions(string $host, ?int $port, array $ips): array
    {
        if ($ips === [] || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        return [CURLOPT_RESOLVE => [$host.':'.$port.':'.$ips[0]]];
    }

    /**
     * 把正文中的外链图片下载落地到媒体库，改写为媒体 URL（并写 data-id）。
     *
     * @return array{html: string, mediaIds: list<int>}
     */
    private function localizeImages(string $html): array
    {
        $cfg = (array) config('cmf-wechat-article');
        $maxImages = max(0, (int) ($cfg['max_images'] ?? 80));

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__wi">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//div[@id="__wi"]')->item(0);

        if (! $root instanceof DOMElement) {
            return ['html' => $html, 'mediaIds' => []];
        }

        /** @var list<int> $ids */
        $ids = [];
        $count = 0;
        $budgetSeconds = max(0, (int) ($cfg['image_budget_seconds'] ?? 60));
        $startedAt = microtime(true);

        foreach (iterator_to_array($xpath->query('.//img', $root)) as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $src = trim($img->getAttribute('src'));

            if ($src === '') {
                $src = trim($img->getAttribute('data-src'));
            }

            if ($src === '' || preg_match('~^https?://~i', $src) !== 1) {
                $img->removeAttribute('data-src');

                continue;
            }

            if ($img->getAttribute('data-id') !== '') {
                continue;
            }

            if ($maxImages > 0 && $count >= $maxImages) {
                break;
            }

            // 时间预算：图片逐张同步下载，超预算后跳过剩余图片（保留外链），避免拖死保存请求
            if ($budgetSeconds > 0 && (microtime(true) - $startedAt) >= $budgetSeconds) {
                Log::warning('wechat-article: 图片落地超出时间预算，剩余图片保留外链');

                break;
            }

            try {
                $media = $this->imageLocalizer->localize($src);
                $mediaUrl = $media->url();

                if (is_string($mediaUrl) && $mediaUrl !== '') {
                    $img->setAttribute('src', $mediaUrl);
                }

                $img->setAttribute('data-id', (string) $media->getKey());
                $img->removeAttribute('data-src');

                $ids[] = (int) $media->getKey();
                $count++;
            } catch (\Throwable $e) {
                Log::warning('wechat-article: 图片落地失败，保留外链', [
                    'url' => $src,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return ['html' => $out, 'mediaIds' => array_values(array_unique($ids))];
    }

    /**
     * 按 selector 提取正文节点；selector 未命中时兜底取 body。
     * selector 支持 `#id`、`.class`、`tag` 三种形式（可配置 config('cmf-wechat-article.content_selector')）。
     */
    private function extractContent(string $body): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $selector = trim((string) config('cmf-wechat-article.content_selector', '#js_content'));

        $node = null;

        if ($selector !== '') {
            $expression = match (true) {
                $selector[0] === '#' => '//*[@id="'.substr($selector, 1).'"]',
                $selector[0] === '.' => '//*[contains(concat(" ", normalize-space(@class), " "), " '.substr($selector, 1).' ")]',
                default => '//'.$selector,
            };

            $node = $xpath->query($expression)->item(0);
        }

        // 兜底：selector 未命中（公众号改版/页面受限）时退回 body
        if (! $node instanceof DOMElement) {
            $node = $xpath->query('//body')->item(0);
        }

        if (! $node instanceof DOMElement) {
            throw new RuntimeException('未能解析到文章正文（可能已删除或受限）');
        }

        foreach (iterator_to_array($xpath->query('.//img', $node)) as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }

            $dataSrc = $img->getAttribute('data-src');

            if ($dataSrc !== '' && $img->getAttribute('src') === '') {
                $img->setAttribute('src', $dataSrc);
            }

            $img->removeAttribute('data-src');
        }

        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return $html;
    }

    private function clean(string $html): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__wxt">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//div[@id="__wxt"]')->item(0);

        if (! $root instanceof DOMElement) {
            return $html;
        }

        // 移除可执行 / 外链替换类标签
        foreach (['script', 'noscript', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form', 'input', 'button', 'textarea', 'select'] as $tag) {
            foreach (iterator_to_array($xpath->query('.//'.$tag, $root)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 去掉 on* 事件属性与 javascript: 协议
        foreach (iterator_to_array($xpath->query('.//*', $root)) as $el) {
            if (! $el instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($el->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                // 浏览器解析 URL 时会剥除属性值里的 TAB/LF/CR/NUL（jav\tascript: 仍按 javascript: 执行），
                // 检测前先做同样规范化
                $value = strtolower(preg_replace('/[\t\r\n\0]/', '', trim($attribute->nodeValue ?? '')));

                if (str_starts_with($name, 'on') || str_starts_with($value, 'javascript:')) {
                    $el->removeAttribute($attribute->nodeName);
                }
            }
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }
}
