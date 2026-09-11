<?php

declare(strict_types=1);

use Quansitech\Cmf\Media\Signers\CosSigner;
use Quansitech\Cmf\Media\Signers\OssSigner;
use Quansitech\Cmf\Media\Signers\TosSigner;

$tosConfig = [
    'key' => 'AKIDEXAMPLE',
    'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
    'region' => 'cn-beijing',
    'bucket' => 'examplebucket',
    'endpoint' => 'tos-s3-cn-beijing.volces.com',
];

$ossConfig = [
    'key' => 'test-oss-key',
    'secret' => 'test-oss-secret',
    'bucket' => 'examplebucket',
    'endpoint' => 'oss-cn-hangzhou.aliyuncs.com',
];

$cosConfig = [
    'secret_id' => 'test-cos-secret-id',
    'secret_key' => 'test-cos-secret-key',
    'region' => 'ap-guangzhou',
    'bucket' => 'examplebucket-1250000000',
];

it('tos signer issues a SigV4 presigned PUT restricted to the key', function () use ($tosConfig): void {
    $key = 'ab/'.str_repeat('a', 32).'.jpg';

    $result = (new TosSigner)->signUpload($key, 'image/jpeg', 123, $tosConfig);

    expect($result['method'])->toBe('PUT')
        ->and($result['headers'])->toBe(['Content-Type' => 'image/jpeg'])
        ->and($result['expires'])->toBe(600);

    $parts = parse_url($result['upload_url']);
    expect($parts['host'])->toBe('examplebucket.tos-s3-cn-beijing.volces.com')
        ->and($parts['path'])->toBe('/'.$key);

    parse_str($parts['query'], $query);
    expect($query['X-Amz-Algorithm'])->toBe('AWS4-HMAC-SHA256')
        ->and($query['X-Amz-Expires'])->toBe('600')
        ->and($query['X-Amz-SignedHeaders'])->toBe('host')
        ->and($query['X-Amz-Credential'])->toStartWith('AKIDEXAMPLE/')
        ->and($query['X-Amz-Credential'])->toEndWith('/cn-beijing/s3/aws4_request')
        ->and($query['X-Amz-Signature'])->toMatch('/^[0-9a-f]{64}$/');

    // 独立复算官方 SigV4 算法，校验签名正确
    $dateStamp = substr($query['X-Amz-Date'], 0, 8);
    $canonicalQuery = collect($query)
        ->except('X-Amz-Signature')
        ->sortKeys()
        ->map(fn (string $v, string $k): string => rawurlencode($k).'='.rawurlencode($v))
        ->implode('&');

    $canonicalRequest = "PUT\n/{$key}\n{$canonicalQuery}\nhost:examplebucket.tos-s3-cn-beijing.volces.com\n\nhost\nUNSIGNED-PAYLOAD";
    $stringToSign = "AWS4-HMAC-SHA256\n{$query['X-Amz-Date']}\n{$dateStamp}/cn-beijing/s3/aws4_request\n".hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$tosConfig['secret'], true);
    $kRegion = hash_hmac('sha256', 'cn-beijing', $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

    expect(hash_hmac('sha256', $stringToSign, $kSigning))->toBe($query['X-Amz-Signature']);
});

it('tos signer signs HEAD urls for server side verification', function () use ($tosConfig): void {
    $url = (new TosSigner)->signUrl('HEAD', 'ab/'.str_repeat('b', 32).'.png', $tosConfig, 600);

    expect($url)->toContain('X-Amz-Signature=')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/ab/'.str_repeat('b', 32).'.png');
});

it('oss signer issues a PostObject policy signature', function () use ($ossConfig): void {
    $key = 'cd/'.str_repeat('c', 32).'.png';

    $result = (new OssSigner)->signUpload($key, 'image/png', 456, $ossConfig);

    expect($result['method'])->toBe('POST')
        ->and($result['upload_url'])->toBe('https://examplebucket.oss-cn-hangzhou.aliyuncs.com')
        ->and($result['fields']['key'])->toBe($key)
        ->and($result['fields']['OSSAccessKeyId'])->toBe('test-oss-key')
        ->and($result['fields']['Content-Type'])->toBe('image/png')
        ->and($result['fields']['success_action_status'])->toBe('200');

    // 按阿里云官方算法复算：Signature = base64(HMAC-SHA1(secret, base64(policy)))
    $policy = $result['fields']['policy'];
    $expected = base64_encode(hash_hmac('sha1', $policy, $ossConfig['secret'], true));
    expect($result['fields']['Signature'])->toBe($expected);

    $decoded = json_decode(base64_decode($policy, true), true);
    expect($decoded['expiration'])->toBeString()
        ->and($decoded['conditions'])->toContain(['eq', '$key', $key])
        ->and($decoded['conditions'])->toContain(['eq', '$Content-Type', 'image/png'])
        ->and($decoded['conditions'])->toContain(['content-length-range', 456, 456]);
});

it('oss signer signs HEAD urls with query string signature', function () use ($ossConfig): void {
    $key = 'ab/'.str_repeat('d', 32).'.jpg';
    $url = (new OssSigner)->signUrl('HEAD', $key, $ossConfig, 600);

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(parse_url($url, PHP_URL_HOST))->toBe('examplebucket.oss-cn-hangzhou.aliyuncs.com')
        ->and($query['OSSAccessKeyId'])->toBe('test-oss-key')
        ->and((int) $query['Expires'])->toBeGreaterThan(time());

    // 复算 OSS 查询串签名：base64(HMAC-SHA1(secret, "HEAD\n\n\n{expires}\n/{bucket}/{key}"))
    $stringToSign = "HEAD\n\n\n{$query['Expires']}\n/examplebucket/{$key}";
    $expected = base64_encode(hash_hmac('sha1', $stringToSign, $ossConfig['secret'], true));
    expect($query['Signature'])->toBe($expected);
});

it('cos signer issues a presigned PUT with q-sign-algorithm', function () use ($cosConfig): void {
    $key = 'ef/'.str_repeat('e', 32).'.webp';

    $result = (new CosSigner)->signUpload($key, 'image/webp', 789, $cosConfig);

    expect($result['method'])->toBe('PUT')
        ->and($result['headers'])->toBe(['Content-Type' => 'image/webp']);

    $parts = parse_url($result['upload_url']);
    expect($parts['host'])->toBe('examplebucket-1250000000.cos.ap-guangzhou.myqcloud.com')
        ->and($parts['path'])->toBe('/'.$key);

    parse_str($parts['query'], $query);
    expect($query['q-sign-algorithm'])->toBe('sha1')
        ->and($query['q-ak'])->toBe($cosConfig['secret_id'])
        ->and($query['q-sign-time'])->toBe($query['q-key-time'])
        ->and($query['q-signature'])->toMatch('/^[0-9a-f]{40}$/');

    // 按腾讯云官方算法复算
    $keyTime = $query['q-sign-time'];
    $signKey = hash_hmac('sha1', $keyTime, $cosConfig['secret_key']);
    $httpString = "put\n/{$key}\n\n\n";
    $stringToSign = "sha1\n{$keyTime}\n".sha1($httpString)."\n";
    expect(hash_hmac('sha1', $stringToSign, $signKey))->toBe($query['q-signature']);
});

it('cos signer signs HEAD urls for server side verification', function () use ($cosConfig): void {
    $url = (new CosSigner)->signUrl('HEAD', 'ab/'.str_repeat('f', 32).'.png', $cosConfig, 600);

    expect($url)->toContain('q-signature=');
});
