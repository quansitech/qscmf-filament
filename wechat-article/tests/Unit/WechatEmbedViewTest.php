<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Tests\Unit;

use Quansitech\Cmf\WechatArticle\Support\WechatEmbedView;
use Quansitech\Cmf\WechatArticle\Tests\TestCase;

class WechatEmbedViewTest extends TestCase
{
    private const URL = 'https://mp.weixin.qq.com/s/abc123';

    private function blockHtml(string $url, string $id = 'wechat-embed'): string
    {
        $config = json_encode(['url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<p>前言</p><div data-type="customBlock" data-config="'.htmlspecialchars((string) $config, ENT_QUOTES).'" data-id="'.$id.'"></div>';
    }

    public function test_plain_html_passes_through_untouched(): void
    {
        $this->assertSame('<p>hi</p>', WechatEmbedView::render('<p>hi</p>'));
        $this->assertSame('', WechatEmbedView::render(null));
    }

    public function test_embed_block_is_expanded_into_iframe(): void
    {
        $html = WechatEmbedView::render($this->blockHtml(self::URL), 42);

        $this->assertStringContainsString('<iframe', $html);
        $this->assertStringContainsString('/wechat-article/embed?url=', $html);
        $this->assertStringContainsString('ref=42', $html);
        $this->assertStringContainsString('sandbox="allow-same-origin"', $html);
        // 块本身不应残留
        $this->assertStringNotContainsString('data-type="customBlock"', $html);
    }

    public function test_token_is_bound_to_url_only(): void
    {
        $token = WechatEmbedView::token(self::URL);

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, WechatEmbedView::token(self::URL));
        $this->assertNotSame($token, WechatEmbedView::token(self::URL.'?x=1'));
    }

    public function test_iframe_html_without_url_renders_placeholder(): void
    {
        $html = WechatEmbedView::iframeHtml('  ');

        $this->assertStringContainsString('未配置链接', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function test_iframe_html_carries_ref_and_version(): void
    {
        $html = WechatEmbedView::iframeHtml(self::URL, 7, 12345);

        $this->assertStringContainsString('ref=7', $html);
        $this->assertStringContainsString('v=12345', $html);
        $this->assertStringContainsString('t='.WechatEmbedView::token(self::URL), $html);
    }
}
