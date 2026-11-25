<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent;

use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Packstub\Agents\Http\Middleware\AuthenticateAgent;
use Quansitech\Cmf\Agent\Http\AuthorizeMcpAccess;
use Quansitech\Cmf\Core\Cmf;

final class CmfAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cmf-agent.php', 'cmf-agent');

        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');

        // 包内 JSON 翻译在 Filament/Packstub 之后加载，清空已解析缓存以确保覆盖 vendor 英文。
        $this->callAfterResolving('translator', static function ($translator): void {
            $translator->setLoaded([]);
        });

        Cmf::registerPlugin(AgentPlugin::class);

        $permissions = config('filament-shield.custom_permissions', []);
        $permissions[AgentPlugin::MANAGE_TOKENS] ??= '管理 Agent 授权令牌';
        config(['filament-shield.custom_permissions' => $permissions]);

        // Packstub 自带 /mcp 端点；仅在宿主显式关闭时才禁用。
        $this->app->booting(function (): void {
            if (! (bool) config('cmf-agent.enable_packstub_mcp', true)) {
                config(['packstub-agents.mcp.enabled' => false]);

                return;
            }

            $middleware = config('packstub-agents.mcp.middleware', []);
            config(['packstub-agents.mcp.middleware' => array_values(array_unique([...$middleware, AuthorizeMcpAccess::class]))]);
        });
    }

    public function boot(): void
    {
        // Cached routes can predate the authorization middleware or the current MCP settings.
        $this->app['events']->listen(RouteMatched::class, static function (RouteMatched $event): void {
            if (! $event->request->isMethod('POST')) {
                return;
            }

            $path = trim((string) config('packstub-agents.mcp.path', 'mcp'), '/');
            $middleware = $event->route->middleware();

            if ($event->route->uri() !== $path && ! in_array(AuthenticateAgent::class, $middleware, true)) {
                return;
            }

            abort_unless(config('cmf-agent.enable_packstub_mcp', true) && config('packstub-agents.mcp.enabled', true), 404);
            abort_unless(in_array(AuthorizeMcpAccess::class, $middleware, true), 503);
        });

        $this->publishes([
            __DIR__.'/../config/cmf-agent.php' => config_path('cmf-agent.php'),
        ], 'cmf-agent-config');
    }
}
