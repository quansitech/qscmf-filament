<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\URL;
use Quansitech\Cmf\WechatArticle\Filament\RichContent\WechatEmbedBlock;

/**
 * 把富文本里的“公众号文章”自定义块（<div data-type="customBlock" data-id="wechat-embed">）
 * 渲染为沙箱 iframe；块内容按需由 /wechat-article/embed 路由抓取并返回。
 *
 * $ref 为承载该块的业务记录 id（前台渲染时传入），用于把抓取落地到媒体库的
 * 图片在对应记录上建立引用，避免被媒体库孤儿清理。
 */
final class WechatEmbedView
{
    public static function render(?string $html, ?int $ref = null, bool $absolute = false): string
    {
        $html = (string) $html;

        if ($html === '' || ! str_contains($html, 'data-type="customBlock"')) {
            return $html;
        }

        return self::expand($html, $ref, $absolute);
    }

    /**
     * 生成与文章 url 绑定的校验令牌（不受 host/协议影响）。
     */
    public static function token(string $url): string
    {
        return hash_hmac('sha256', $url, (string) config('app.key'));
    }

    public static function iframeHtml(string $url, ?int $ref = null, ?int $version = null, bool $absolute = false): string
    {
        $url = trim($url);

        if ($url === '') {
            return '<div style="padding:12px 16px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px;">公众号文章：未配置链接</div>';
        }

        // 用「内容摘要 + 应用密钥」做签名，而非 URL signedRoute：签名只与文章 url 绑定，
        // 不受 iframe 生成/访问时的 host、协议、参数顺序影响，避免签名不一致导致 403。
        $params = ['url' => $url, 't' => self::token($url)];

        if ($ref !== null && $ref > 0) {
            $params['ref'] = $ref;
        }

        if ($version !== null && $version > 0) {
            $params['v'] = $version;
        }

        // $absolute=true 供 API/外部消费方使用（相对 URL 在外部上下文无法解析）
        $src = URL::route('site.wechat.embed', $params, $absolute);

        // onload 只保证 DOM 就绪，正文里的图片还在陆续加载，高度会持续增长；
        // sandbox 含 allow-same-origin，可在父页轮询 contentDocument 的高度兜住多图文章
        // （onload 立即算一次，再每 500ms 复算，共 20 次 ≈ 10s，足够大多数文章）。
        $onload = "var self=this;var n=0;var f=function(){try{self.style.height=(self.contentWindow.document.documentElement.scrollHeight+16)+'px';}catch(e){}};f();var t=setInterval(function(){f();if(++n>=20){clearInterval(t);}},500);";

        return '<iframe src="'.e($src).'" sandbox="allow-same-origin" referrerpolicy="no-referrer" '
            .'style="width:100%;border:0;display:block;min-height:420px;background:#fff;" '
            .'onload="'.e($onload).'"></iframe>';
    }

    private static function expand(string $html, ?int $ref, bool $absolute): string
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__wv">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//div[@id="__wv"]')->item(0);

        if (! $root instanceof DOMElement) {
            return $html;
        }

        foreach (iterator_to_array($xpath->query('.//div[@data-type="customBlock"]', $root)) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->getAttribute('data-id') !== WechatEmbedBlock::ID) {
                continue;
            }

            $config = json_decode($node->getAttribute('data-config'), true);
            $config = is_array($config) ? $config : [];

            $iframe = self::iframeNode($doc, (string) ($config['url'] ?? ''), $ref, null, $absolute);

            if ($iframe !== null && $node->parentNode !== null) {
                $node->parentNode->replaceChild($iframe, $node);
            }
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function iframeNode(DOMDocument $doc, string $url, ?int $ref, ?int $version = null, bool $absolute = false): ?DOMNode
    {
        $previous = libxml_use_internal_errors(true);
        $tmp = new DOMDocument;
        $tmp->loadHTML('<?xml encoding="utf-8"?><div>'.self::iframeHtml($url, $ref, $version, $absolute).'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $el = $tmp->getElementsByTagName('iframe')->item(0) ?? $tmp->getElementsByTagName('div')->item(0);

        return $el !== null ? $doc->importNode($el, true) : null;
    }
}
