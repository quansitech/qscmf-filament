<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Quansitech\Cmf\Media\Http\Controllers\MediaUploadController;

Route::middleware(config('cmf-media.middleware', ['web', 'auth']))
    ->prefix(config('cmf-media.route_prefix', 'cmf-media'))
    ->name('cmf-media.')
    ->group(function (): void {
        Route::post('check', [MediaUploadController::class, 'check'])->name('check');
        Route::post('sign', [MediaUploadController::class, 'sign'])->name('sign');
        Route::post('upload', [MediaUploadController::class, 'upload'])->name('upload'); // local 驱动专用
        Route::post('callback', [MediaUploadController::class, 'callback'])->name('callback');
        Route::get('library', [MediaUploadController::class, 'library'])->name('library');
    });
