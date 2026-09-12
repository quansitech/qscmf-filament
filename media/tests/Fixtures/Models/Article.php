<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Media\Tests\Fixtures\Models;

use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Quansitech\Cmf\Media\Concerns\HasMedia;
use Quansitech\Cmf\Media\Concerns\HasMediaRichContent;

/**
 * 测试业务模型：挂载 HasMediaRichContent 验证 RichEditor 附件接管。
 */
class Article extends Model implements HasRichContent
{
    use HasMedia;
    use HasMediaRichContent;
    use SoftDeletes;

    protected $table = 'articles';

    /** @var list<string> */
    protected $fillable = ['title', 'content'];

    /** @var list<string> */
    protected array $mediaRichContentAttributes = ['content'];
}
