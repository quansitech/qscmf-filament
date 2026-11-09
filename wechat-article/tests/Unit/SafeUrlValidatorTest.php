<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Tests\Unit;

use Quansitech\Cmf\WechatArticle\Support\SafeUrlValidator;
use Quansitech\Cmf\WechatArticle\Tests\TestCase;
use RuntimeException;

class SafeUrlValidatorTest extends TestCase
{
    private SafeUrlValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SafeUrlValidator;
    }

    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('链接仅支持 http/https');

        $this->validator->validate('ftp://mp.weixin.qq.com/s/a', []);
    }

    public function test_rejects_userinfo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不允许包含认证信息');

        $this->validator->validate('https://user:'.'pass'.'@mp.weixin.qq.com/s/a', []);
    }

    public function test_rejects_unexpected_port(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('端口不被允许');

        $this->validator->validate('https://mp.weixin.qq.com:8443/s/a', []);
    }

    public function test_rejects_host_outside_allowlist_without_dns(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('域名不在允许列表：evil.example.com');

        // 白名单判定先于 DNS 解析，因此该用例不依赖网络
        $this->validator->validate('https://evil.example.com/s/a', ['mp.weixin.qq.com']);
    }

    public function test_allows_public_ip_literal_with_empty_allowlist(): void
    {
        // 空白名单 = 不限域名；IP 字面量不触发 DNS 查询，用例可离线运行
        $result = $this->validator->validate('https://8.8.8.8/s/a', []);

        $this->assertSame('8.8.8.8', $result['host']);
        $this->assertSame(['8.8.8.8'], $result['ips']);
    }

    public function test_rejects_private_ip_literal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('非公网');

        $this->validator->validate('http://10.0.0.1/s/a', []);
    }

    public function test_label_prefixes_error_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('图片端口不被允许');

        $this->validator->validate('http://mp.weixin.qq.com:8080/s/a', [], '图片');
    }

    public function test_host_allowlist_supports_wildcard(): void
    {
        $this->assertTrue($this->validator->hostAllowed('a.b.example.com', ['*.example.com']));
        $this->assertFalse($this->validator->hostAllowed('example.com', ['*.example.com']));
        $this->assertFalse($this->validator->hostAllowed('badexample.com', ['*.example.com']));
        $this->assertTrue($this->validator->hostAllowed('mp.weixin.qq.com', ['mp.weixin.qq.com']));
    }
}
