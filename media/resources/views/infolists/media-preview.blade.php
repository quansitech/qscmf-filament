@php
    /** @var \Quansitech\Cmf\Media\Models\Media $media */
    $media = $getRecord();
    $url = $media->url();
@endphp

<div>
    @if (! $url)
        <p class="text-sm text-gray-500 dark:text-gray-400">
            无法生成访问链接（对应驱动的 adapter 未安装或 disk 未配置）。
        </p>
    @elseif ($media->isImage())
        {{-- 点击在新窗口查看原图 --}}
        <a href="{{ $url }}" target="_blank" rel="noopener" title="点击查看原图" class="inline-block">
            <img
                src="{{ $media->thumbUrl() ?? $url }}"
                alt="{{ $media->original_name }}"
                class="max-h-80 rounded-lg border border-gray-200 dark:border-gray-700"
            />
        </a>
        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">点击图片在新窗口查看原图</p>
    @elseif (str_starts_with($media->mime, 'video/'))
        <video controls preload="metadata" class="max-h-80 w-full max-w-xl rounded-lg border border-gray-200 dark:border-gray-700">
            <source src="{{ $url }}" type="{{ $media->mime }}" />
            您的浏览器不支持视频播放。
        </video>
    @elseif (str_starts_with($media->mime, 'audio/'))
        <audio controls preload="metadata" class="w-full max-w-xl">
            <source src="{{ $url }}" type="{{ $media->mime }}" />
            您的浏览器不支持音频播放。
        </audio>
    @else
        <a
            href="{{ $url }}"
            target="_blank"
            rel="noopener"
            download="{{ $media->original_name }}"
            class="fi-btn fi-btn-color-gray fi-btn-size-sm inline-flex items-center gap-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm dark:border-gray-600"
        >
            下载文件（{{ $media->original_name }}）
        </a>
    @endif
</div>
