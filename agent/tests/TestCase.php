<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\Facades\Filament;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Packstub\Agents\AgentsServiceProvider;
use Packstub\FilamentAgents\FilamentAgentsServiceProvider;
use Quansitech\Cmf\Agent\CmfAgentServiceProvider;
use Quansitech\Cmf\Agent\Support\DiscoveredAgentTools;
use Quansitech\Cmf\Agent\Tests\Fixtures\Models\TestUser;
use Quansitech\Cmf\Agent\Tests\Fixtures\Providers\AdminPanelProvider;
use Quansitech\Cmf\Core\CmfCoreServiceProvider;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            McpServiceProvider::class,
            AiServiceProvider::class,
            AgentsServiceProvider::class,
            FilamentAgentsServiceProvider::class,
            CmfCoreServiceProvider::class,
            AdminPanelProvider::class,
            CmfAgentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.locale', 'zh_CN');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', TestUser::class);

        $app['config']->set('cmf-agent.panel', 'admin');
        $app['config']->set('cmf-agent.name', 'CMF 助手');
        $app['config']->set('cmf-agent.driver', 'sync');
        $app['config']->set('cmf-agent.tools', []);

        $app['config']->set('packstub-agents.mcp.enabled', false);
        $app['config']->set('packstub-agents.tool_log.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function setUp(): void
    {
        // 必须在 parent::setUp() 启动面板之前清空，避免上个用例的自动发现缓存影响面板构建。
        DiscoveredAgentTools::flush();

        parent::setUp();
        config(['cmf-agent.tools' => []]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
