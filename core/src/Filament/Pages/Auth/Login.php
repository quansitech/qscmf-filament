<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Core\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

/**
 * 品牌化登录页：左侧品牌展示区（全思科技 logo + 品牌蓝渐变），右侧登录表单。
 * 仅替换页面布局，表单、多因子认证、限流等逻辑沿用 Filament 原生实现。
 */
class Login extends BaseLogin
{
    protected static string $layout = 'cmf-core::filament.layouts.auth';

    protected string $view = 'cmf-core::filament.pages.auth.login';

    /**
     * 卡片头部不显示 logo：品牌展示由布局左侧品牌区承担，
     * 卡片头部改为「用户图标 + 标题」（见自定义视图）。
     */
    public function hasLogo(): bool
    {
        return false;
    }
}
