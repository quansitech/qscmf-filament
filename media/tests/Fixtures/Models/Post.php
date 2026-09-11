<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Quansitech\Cmf\Media\Concerns\HasMedia;

/**
 * 测试业务模型：挂载 HasMedia 验证引用计数。
 */
class Post extends Model
{
    use HasMedia;

    protected $table = 'posts';

    /** @var list<string> */
    protected $fillable = ['title'];
}
