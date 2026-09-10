@php
    use Filament\Support\Facades\FilamentView;
    use Filament\View\PanelsRenderHook;
@endphp

<div class="fi-simple-page">
    {{ FilamentView::renderHook(PanelsRenderHook::SIMPLE_PAGE_START, scopes: $this->getRenderHookScopes()) }}

    <div class="fi-simple-page-content">
        {{-- 头部：用户图标 + 标题（替代默认 logo 头部，由布局里的品牌区承担 logo 展示） --}}
        <header class="fi-simple-header">
            <div class="cmf-auth-heading">
                <svg class="cmf-auth-heading-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                </svg>
                <h1 class="fi-simple-header-heading">{{ $this->getHeading() }}</h1>
            </div>

            @if (filled($subheading = $this->getSubheading()))
                <p class="fi-simple-header-subheading">{{ $subheading }}</p>
            @endif
        </header>

        {{ $this->content }}
    </div>

    <x-filament-actions::modals />

    {{ FilamentView::renderHook(PanelsRenderHook::SIMPLE_PAGE_END, scopes: $this->getRenderHookScopes()) }}
</div>
