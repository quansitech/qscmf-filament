<?php

declare(strict_types=1);

namespace Quansitech\Cmf\WechatArticle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 公众号文章抓取结果。
 *
 * 一篇文章（url 唯一）只保存一份正文快照；多个内容记录引用同一篇文章时复用。
 */
class WechatArticle extends Model
{
    protected $table = 'wechat_articles';

    protected $fillable = [
        'url',
        'content',
        'media_ids',
        'fetched_at',
    ];

    protected $casts = [
        'media_ids' => 'array',
        'fetched_at' => 'datetime',
    ];
}
