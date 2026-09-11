<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cmf_media', function (Blueprint $table): void {
            $table->id();
            $table->string('disk', 20)->comment('云存储驱动：tos / oss / cos');
            $table->string('path', 512)->comment('对象 key：{hash前2位}/{hash}.{ext}');
            $table->char('hash', 32)->unique()->comment('文件内容 MD5，去重键');
            $table->string('original_name')->comment('原始文件名');
            $table->string('mime', 100);
            $table->string('ext', 20);
            $table->unsignedBigInteger('size')->comment('字节');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('uploader_id')->nullable()->comment('上传人');
            $table->unsignedBigInteger('ref_count')->default(0)->comment('引用计数（冗余自 cmf_media_usages）');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('cmf_media_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('cmf_media')->cascadeOnDelete();
            $table->string('usable_type');
            $table->unsignedBigInteger('usable_id');
            $table->string('field', 100)->comment('引用字段名');
            $table->timestamp('created_at')->nullable();

            $table->unique(['media_id', 'usable_type', 'usable_id', 'field'], 'cmf_media_usages_unique');
            $table->index(['usable_type', 'usable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cmf_media_usages');
        Schema::dropIfExists('cmf_media');
    }
};
