<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wechat_articles', function (Blueprint $table): void {
            $table->id();

            // 公众号文章链接（唯一，作为抓取结果的天然主键）
            $table->string('url', 768)->unique();

            // 抓取并清洗后的正文 HTML 快照
            $table->longText('content')->nullable();

            // 正文里落地到媒体库的图片 id 列表
            $table->json('media_ids')->nullable();

            // 最近一次抓取时间
            $table->timestamp('fetched_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_articles');
    }
};
