@php
    /** 品牌名可通过 config/cmf-core.php 的 brand.name（或环境变量 CMF_BRAND_NAME）修改 */
    $cmfBrandName = config('cmf-core.brand.name', config('app.name'));
@endphp

<x-filament-panels::layout.base :livewire="$livewire ?? null">
    <div class="cmf-auth">
        {{-- 左侧品牌展示区（移动端自动隐藏，改由卡片上方的品牌条展示） --}}
        <aside class="cmf-auth-brand">
            <div class="cmf-auth-brand-inner">
                <p class="cmf-auth-brand-name">{{ $cmfBrandName }}</p>

                <p class="cmf-auth-slogan">高效 · 便捷 · 安全</p>
                <p class="cmf-auth-desc">为您的业务提供一体化的管理解决方案</p>
            </div>
        </aside>

        {{-- 右侧登录表单 --}}
        <div class="cmf-auth-main">
            <div class="cmf-auth-mobile-brand">{{ $cmfBrandName }}</div>

            <main class="cmf-auth-card">
                {{ $slot }}
            </main>
        </div>
    </div>

    <style>
        .cmf-auth {
            display: flex;
            min-height: 100dvh;
            background-color: #f4f8fc;
        }

        html.dark .cmf-auth {
            background-color: #070d17;
        }

        /* ---- 品牌区 ---- */

        .cmf-auth-brand {
            position: relative;
            display: none;
            flex: 1 1 0%;
            overflow: hidden;
            padding: 3rem;
            color: #fff;
            background: linear-gradient(170deg, #61caed 0%, #37a5d7 55%, #3f9dd0 100%);
        }

        @media (min-width: 1024px) {
            .cmf-auth-brand {
                display: block;
            }
        }

        /* 装饰光斑 */
        .cmf-auth-brand::before,
        .cmf-auth-brand::after {
            position: absolute;
            border-radius: 9999px;
            background: rgba(255, 255, 255, 0.09);
            content: '';
        }

        .cmf-auth-brand::before {
            top: -10rem;
            inset-inline-end: -8rem;
            width: 30rem;
            height: 30rem;
        }

        .cmf-auth-brand::after {
            bottom: -14rem;
            inset-inline-start: -10rem;
            width: 36rem;
            height: 36rem;
            background: rgba(255, 255, 255, 0.06);
        }

        .cmf-auth-brand-inner {
            position: relative;
            z-index: 1;
            max-width: 26rem;
            padding-top: 22vh;
        }

        .cmf-auth-brand-name {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            color: #fff;
        }

        .cmf-auth-slogan {
            margin-top: 1.5rem;
            font-size: 1.9rem;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: 0.04em;
        }

        .cmf-auth-desc {
            margin-top: 0.75rem;
            font-size: 0.95rem;
            line-height: 1.7;
            color: rgba(255, 255, 255, 0.9);
        }

        /* ---- 表单区 ---- */

        .cmf-auth-main {
            display: flex;
            flex: 1 1 0%;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1rem;
        }

        .cmf-auth-mobile-brand {
            margin-bottom: 1.75rem;
            font-size: 1.25rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            color: #1f2937;
        }

        html.dark .cmf-auth-mobile-brand {
            color: #f9fafb;
        }

        @media (min-width: 1024px) {
            .cmf-auth-mobile-brand {
                display: none;
            }
        }

        .cmf-auth-card {
            width: 100%;
            max-width: 28rem;
            padding: 2.75rem;
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 15px 50px rgba(30, 80, 120, 0.1);
        }

        html.dark .cmf-auth-card {
            background: #111827;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.45);
        }

        /* 卡片头部：图标 + 标题横向居中 */
        .cmf-auth-card .fi-simple-header {
            margin-bottom: 1.75rem;
        }

        .cmf-auth-heading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
        }

        .cmf-auth-heading-icon {
            width: 1.9rem;
            height: 1.9rem;
            color: #29abe2;
        }

        .cmf-auth-heading .fi-simple-header-heading {
            margin: 0;
        }
    </style>
</x-filament-panels::layout.base>
