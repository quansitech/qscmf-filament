<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Support\MediaUploader;
use Quansitech\Cmf\Media\Support\UploadRule;
use RuntimeException;

/**
 * 把公众号正文里的外链图片下载并落库到项目媒体库（cmf-media）。
 *
 * 安全：URL 校验统一交给 SafeUrlValidator（http/https、禁 userinfo、端口 80/443、
 * 白名单可选、仅公网 IP）；限制超时与单张体积。
 */
final class RemoteImageLocalizer
{
    public function __construct(private readonly SafeUrlValidator $urlValidator) {}

    public function localize(string $url): Media
    {
        $cfg = (array) config('cmf-wechat-article');

        $allowedHosts = array_values(array_filter((array) ($cfg['image_allowed_hosts'] ?? [])));

        $safe = $this->urlValidator->validate($url, $allowedHosts, '图片');
        $url = $safe['url'];

        $maxBytes = (int) ($cfg['max_image_bytes'] ?? 10 * 1024 * 1024);

        $tmp = tempnam(sys_get_temp_dir(), 'wx-img-');

        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        try {
            // sink 流式落盘：不把整个响应读进内存；超限用 filesize 事后拦截
            $response = Http::withOptions([
                'allow_redirects' => false,
                'verify' => (bool) ($cfg['verify_tls'] ?? true),
                // 用校验阶段解析出的 IP 发起连接（SNI/Host 仍是域名），消除 DNS rebinding 窗口
                'curl' => $this->pinResolveOptions(
                    $safe['host'],
                    $safe['port'] ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80),
                    $safe['ips'],
                ),
            ])
                ->withHeaders([
                    'User-Agent' => (string) ($cfg['user_agent'] ?? 'Mozilla/5.0'),
                    'Accept' => 'image/avif,image/webp,image/*,*/*;q=0.8',
                    'Referer' => (string) ($cfg['image_referer'] ?? 'https://mp.weixin.qq.com/'),
                ])
                ->connectTimeout((int) ($cfg['connect_timeout'] ?? 5))
                ->timeout((int) ($cfg['timeout'] ?? 15))
                ->sink($tmp)
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('图片下载失败：HTTP '.$response->status());
            }

            clearstatcache(true, $tmp);
            $size = (int) filesize($tmp);

            if ($size === 0) {
                throw new RuntimeException('图片内容为空');
            }

            if ($size > $maxBytes) {
                throw new RuntimeException('图片超过大小上限');
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';

            // SVG 可内嵌脚本，显式拒绝（即使正文会被 sandbox iframe 渲染）
            if (str_starts_with($mime, 'image/svg')) {
                throw new RuntimeException('不支持 SVG 图片');
            }

            if (! str_starts_with($mime, 'image/')) {
                throw new RuntimeException('下载内容不是有效图片');
            }

            $file = new UploadedFile($tmp, $this->guessName($url, $mime), $mime, null, true);

            /** @var UploadRule|null $rule */
            $rule = UploadRule::find((string) ($cfg['media_rule'] ?? 'editor'));

            return app(MediaUploader::class)->store($file, rule: $rule);
        } finally {
            @unlink($tmp);
        }
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

    private function guessName(string $url, string $mime): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename($path);

        if ($base !== '' && preg_match('/\.[A-Za-z0-9]{2,5}$/', $base) === 1) {
            return $base;
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            default => 'jpg',
        };

        return 'wechat-image.'.$ext;
    }
}
