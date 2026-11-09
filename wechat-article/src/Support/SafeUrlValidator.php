<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Support;

use RuntimeException;

/**
 * 抓取目标 URL 的统一安全校验（供正文抓取与图片下载共用）。
 *
 * 规则：
 * - 仅允许 http/https；
 * - 禁止 userinfo（user:pass@）；
 * - 端口仅 80/443；
 * - host 必须命中白名单（精确匹配或 *.example.com 通配）；白名单为空表示不限域名；
 * - 域名解析出的所有 IP 必须为公网地址（防 SSRF）。
 */
final class SafeUrlValidator
{
    /**
     * 校验并解析 URL。
     *
     * @param  array<int, string>  $allowedHosts
     * @param  string  $label  报错文案里的对象名（如「链接」「图片」）
     * @return array{url: string, host: string, port: int|null, ips: array<int, string>}
     *
     * @throws RuntimeException
     */
    public function validate(string $url, array $allowedHosts, string $label = '链接'): array
    {
        $url = trim($url);

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException($label.'格式不正确');
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException($label.'仅支持 http/https');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException($label.'不允许包含认证信息');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($port !== null && ! in_array($port, [80, 443], true)) {
            throw new RuntimeException($label.'端口不被允许');
        }

        $host = strtolower((string) $parts['host']);

        // IP 字面量：私网/保留地址直接拒绝（消息与解析失败保持一致）
        if (filter_var($host, FILTER_VALIDATE_IP) && ! $this->isPublicIp($host)) {
            throw new RuntimeException($label.'域名解析失败或非公网：'.$host);
        }

        if ($allowedHosts !== [] && ! $this->hostAllowed($host, $allowedHosts)) {
            throw new RuntimeException($label.'域名不在允许列表：'.$host);
        }

        $ips = $this->resolvePublicIps($host);

        if ($ips === []) {
            throw new RuntimeException($label.'域名解析失败或非公网：'.$host);
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ips' => $ips];
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    public function hostAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed));

            if ($allowed === '') {
                continue;
            }

            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".example.com"

                if (str_ends_with($host, $suffix)) {
                    return true;
                }

                continue;
            }

            if ($host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析域名，返回全部公网 IP；若存在私网/保留地址则拒绝（返回空数组）。
     *
     * @return array<int, string>
     */
    public function resolvePublicIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? [$host] : [];
        }

        $ips = [];

        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            if (! empty($record['ip'])) {
                $ips[] = $record['ip'];
            }

            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if ($ips === []) {
            foreach ((array) @gethostbynamel($host) as $ip) {
                if (is_string($ip) && $ip !== '') {
                    $ips[] = $ip;
                }
            }
        }

        $ips = array_values(array_unique($ips));

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                return [];
            }
        }

        return $ips;
    }

    public function isPublicIp(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
    }
}
