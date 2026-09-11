<?php

declare(strict_types=1);

use Quansitech\Cmf\Media\Filament\Resources\Media\MediaResource;
use Quansitech\Cmf\Media\Models\Media;
use Quansitech\Cmf\Media\Policies\MediaPolicy;

return [

    /*
    |--------------------------------------------------------------------------
    | 默认云存储驱动
    |--------------------------------------------------------------------------
    |
    | 浏览器直传与媒体读写使用的存储：tos（火山引擎）/ oss（阿里云）/
    | cos（腾讯云）/ local（服务器本地磁盘，文件流量经过应用服务器）。
    |
    */

    'default' => env('CMF_MEDIA_DRIVER', 'tos'),

    /*
    |--------------------------------------------------------------------------
    | 云存储凭证
    |--------------------------------------------------------------------------
    |
    | 各驱动凭证留空时对应 disk 不注册。所需 Flysystem adapter 通过 composer
    | 按需安装（见 composer.json suggest），未安装时解析 disk 会抛出明确异常。
    |
    | thumb_suffix 用于后台列表缩略图：追加在云厂商图片处理 URL 之后。
    |
    */

    'disks' => [
        'tos' => [
            'key' => env('TOS_ACCESS_KEY'),
            'secret' => env('TOS_SECRET_KEY'),
            'region' => env('TOS_REGION', 'cn-beijing'),
            'bucket' => env('TOS_BUCKET'),
            'endpoint' => env('TOS_ENDPOINT'), // 如 tos-s3-cn-beijing.volces.com（必须用 S3 兼容域名，勿用原生域名）
            'thumb_suffix' => env('TOS_THUMB_SUFFIX', '?x-tos-process=image/resize,w_200'),
        ],
        'oss' => [
            'key' => env('OSS_ACCESS_KEY_ID'),
            'secret' => env('OSS_ACCESS_KEY_SECRET'),
            'bucket' => env('OSS_BUCKET'),
            'endpoint' => env('OSS_ENDPOINT'), // 如 oss-cn-hangzhou.aliyuncs.com
            'thumb_suffix' => env('OSS_THUMB_SUFFIX', '?x-oss-process=image/resize,w_200'),
        ],
        'cos' => [
            'secret_id' => env('COS_SECRET_ID'),
            'secret_key' => env('COS_SECRET_KEY'),
            'region' => env('COS_REGION', 'ap-guangzhou'),
            'bucket' => env('COS_BUCKET'), // 含 appid，如 example-1250000000
            'thumb_suffix' => env('COS_THUMB_SUFFIX', '?imageMogr2/thumbnail/200x200'),
        ],
        // 本地磁盘：文件落在应用服务器（默认 public/cmf-media，直接可访问），
        // 上传走 POST /{route_prefix}/upload（服务器接收并计算 hash 落盘），
        // 秒传去重与引用计数逻辑与云驱动一致。适合单机部署 / 内网场景。
        'local' => [
            'root' => env('CMF_MEDIA_LOCAL_ROOT'),   // 默认 public_path('cmf-media')
            'url' => env('CMF_MEDIA_LOCAL_URL'),     // 默认 /cmf-media
            'thumb_suffix' => '', // 无云图片处理，缩略图直接用原图
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 上传策略
    |--------------------------------------------------------------------------
    |
    | max_size：单文件上限（字节），默认 500MB；直传为单 PUT/POST，超大文件
    | 分片上传列为后续迭代。
    | allowed_mimes：MIME 白名单，支持 "image/*" 通配。
    | sign_expires：直传凭证有效期（秒），限定单 key 短时效。
    | verify_etag：callback 建档时比对对象 ETag 与客户端上报的内容 MD5，
    | 防止恶意用户用他人 hash 冒领文件归属（分片上传 ETag 算法不同，届时跳过）。
    |
    */

    'max_size' => env('CMF_MEDIA_MAX_SIZE', 500 * 1024 * 1024),

    'allowed_mimes' => [
        'image/*',
        'video/*',
        'audio/*',
        'application/pdf',
        'application/zip',
        'application/x-zip-compressed',
        'text/plain',
    ],

    'sign_expires' => 600,

    'verify_etag' => env('CMF_MEDIA_VERIFY_ETAG', true),

    /*
    |--------------------------------------------------------------------------
    | 归零自动删除开关
    |--------------------------------------------------------------------------
    |
    | 开启：ref_count 归零即软删并延迟派发 DeleteMediaJob 清理云端对象；
    | 关闭：归零仅保留记录（后续被重新引用时正常计数），孤儿文件可在后台
    | 手动删除（手动删除仍会排期清理云端对象）。适合有合规留存要求的场景。
    |
    */

    'auto_delete' => env('CMF_MEDIA_AUTO_DELETE', true),

    /*
    |--------------------------------------------------------------------------
    | 归零删除缓冲（分钟）
    |--------------------------------------------------------------------------
    |
    | ref_count 归零后软删并延迟派发 DeleteMediaJob，执行前复查引用计数，
    | 防止"删的同时又被引用"的竞态误删。需运行队列 worker（见 README「归零删除队列」）。
    |
    */

    'delete_delay_minutes' => env('CMF_MEDIA_DELETE_DELAY', 60),

    /*
    |--------------------------------------------------------------------------
    | 孤儿清理宽限（小时）
    |--------------------------------------------------------------------------
    |
    | 「上传后未保存表单」的孤儿文件由每日调度的 cmf-media:prune-orphans 兜底：
    | 上传超过该小时数仍零引用的记录软删并排期清理云端对象。宽限需覆盖
    | 用户填写长表单的耗时。仅 auto_delete 开启时注册调度；需宿主 crontab
    | 配置 schedule:run。
    |
    */

    'orphan_cleanup_after_hours' => env('CMF_MEDIA_ORPHAN_CLEANUP_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | 上传端点路由
    |--------------------------------------------------------------------------
    */

    'route_prefix' => env('CMF_MEDIA_ROUTE_PREFIX', 'cmf-media'),

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | 模型 / Resource / Policy
    |--------------------------------------------------------------------------
    |
    | 深度定制时在 app/ 下继承对应类并替换这里的配置。
    |
    */

    'model' => Media::class,

    'resource' => MediaResource::class,

    'policy' => MediaPolicy::class,

    /*
    |--------------------------------------------------------------------------
    | Shield 权限点（写入 filament-shield.resources.manage）
    |--------------------------------------------------------------------------
    */

    'permissions' => ['viewAny', 'view', 'create', 'delete'],

    /*
    |--------------------------------------------------------------------------
    | 审计集成
    |--------------------------------------------------------------------------
    |
    | 开启后 Media 模型切换为 AuditableMedia（挂 owen-it/laravel-auditing），
    | 需宿主已安装 quansitech/cmf-module-auditing（或 owen-it/laravel-auditing）。
    |
    */

    'audit' => env('CMF_MEDIA_AUDIT', false),

];
