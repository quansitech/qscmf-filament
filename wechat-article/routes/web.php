<?php

use Illuminate\Support\Facades\Route;
use Quansitech\Cmf\WechatArticle\Http\Controllers\WechatEmbedController;
use Quansitech\Cmf\WechatArticle\Http\Controllers\WechatRefetchController;

/*
|--------------------------------------------------------------------------
| 公众号文章嵌入路由（模块自带，无需宿主再注册）
|--------------------------------------------------------------------------
| - embed：由 RichEditor 自定义块生成的 iframe 加载；URL 带 url + t（HMAC 令牌）
|   + 可选 ref（承载该块的业务记录 id，用于登记图片媒体引用）。只读、无需登录。
| - refetch：块标题栏「重新抓取」按钮调用，强制重新抓取并覆盖快照。
|   需要 CSRF（POST）+ 后台登录（控制器内校验，避免 auth 中间件重定向到
|   route('login')——站点路由组没有该命名路由），并做限流防滥用。
| - editor.js：给 RichEditor 注入的增强脚本（块标题栏按钮）。
|
*/

Route::get('/wechat-article/embed', WechatEmbedController::class)
    ->middleware('throttle:60,1')
    ->name('site.wechat.embed');

Route::post('/wechat-article/refetch', WechatRefetchController::class)
    ->middleware(['web', 'throttle:10,1'])
    ->name('site.wechat.refetch');

Route::get('/wechat-article/editor.js', function () {
    $path = __DIR__.'/../resources/js/wechat-embed-refetch.js';

    abort_unless(is_file($path), 404);

    return response(file_get_contents($path), 200, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('site.wechat.editor-js');
