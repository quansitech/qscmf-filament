<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Tests\Unit;

use Quansitech\Cmf\WechatArticle\Support\WechatArticleStore;
use Quansitech\Cmf\WechatArticle\Tests\TestCase;

class WechatArticleStoreTest extends TestCase
{
    private WechatArticleStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->app->make(WechatArticleStore::class);
    }

    private function blockHtml(string $url, string $id = 'wechat-embed'): string
    {
        $config = json_encode(['url' => $url], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<p>前言</p><div data-type="customBlock" data-config="'.htmlspecialchars((string) $config, ENT_QUOTES).'" data-id="'.$id.'"></div>';
    }

    public function test_extracts_url_from_embed_block(): void
    {
        $html = $this->blockHtml('https://mp.weixin.qq.com/s/abc123');

        $this->assertSame(['https://mp.weixin.qq.com/s/abc123'], $this->store->urls($html));
    }

    public function test_ignores_other_custom_blocks_and_empty_html(): void
    {
        $this->assertSame([], $this->store->urls(null));
        $this->assertSame([], $this->store->urls(''));
        $this->assertSame([], $this->store->urls('<p>纯文本</p>'));

        $html = $this->blockHtml('https://mp.weixin.qq.com/s/abc123', 'other-block');

        $this->assertSame([], $this->store->urls($html));
    }

    public function test_dedupes_same_url_in_multiple_blocks(): void
    {
        $html = $this->blockHtml('https://mp.weixin.qq.com/s/abc123')
            .$this->blockHtml('https://mp.weixin.qq.com/s/abc123');

        $this->assertSame(['https://mp.weixin.qq.com/s/abc123'], $this->store->urls($html));
    }

    public function test_skips_invalid_url(): void
    {
        $html = $this->blockHtml('javascript:alert(1)');

        $this->assertSame([], $this->store->urls($html));
    }
}
