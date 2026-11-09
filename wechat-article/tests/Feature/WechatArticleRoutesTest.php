<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Tests\Feature;

use Quansitech\Cmf\WechatArticle\Models\WechatArticle;
use Quansitech\Cmf\WechatArticle\Support\WechatEmbedView;
use Quansitech\Cmf\WechatArticle\Tests\TestCase;

class WechatArticleRoutesTest extends TestCase
{
    private const URL = 'https://mp.weixin.qq.com/s/abc123';

    public function test_editor_script_is_served_with_block_enhancements(): void
    {
        $response = $this->get('/wechat-article/editor.js');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=UTF-8');
        $response->assertSee('wechat-article-saving-overlay', false);
        $response->assertSee('data-wechat-refetch', false);
    }

    public function test_embed_returns_stored_snapshot(): void
    {
        WechatArticle::query()->create([
            'url' => self::URL,
            'content' => '<section><p>正文内容</p></section>',
            'media_ids' => [],
            'fetched_at' => now(),
        ]);

        $response = $this->get('/wechat-article/embed?'.http_build_query([
            'url' => self::URL,
            't' => WechatEmbedView::token(self::URL),
        ]));

        $response->assertOk();
        $response->assertSee('<section><p>正文内容</p></section>', false);
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_embed_rejects_invalid_token_with_plain_text(): void
    {
        $response = $this->get('/wechat-article/embed?'.http_build_query([
            'url' => self::URL,
            't' => 'invalid-token',
        ]));

        $response->assertStatus(403);
        $this->assertSame('forbidden', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }
}
