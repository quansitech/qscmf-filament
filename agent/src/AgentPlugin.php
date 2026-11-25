<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent;

use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Packstub\Agents\Mcp\AgentTool;
use Packstub\FilamentAgents\AgentsPlugin;
use Quansitech\Cmf\Agent\Support\DiscoveredAgentTools;
use Throwable;

/**
 * 把 Packstub 的后台助手挂到 CMF 面板。
 *
 * 模块通过 Cmf::registerPlugin() 登记，CmfPanelProvider 构建面板时自动挂载；
 * 宿主不需要修改 AdminPanelProvider。
 */
final class AgentPlugin implements Plugin
{
    public const ID = 'cmf-agent';

    public const MANAGE_TOKENS = 'Manage:AgentToken';

    public static function make(): static
    {
        return app(self::class);
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        if (! self::supportsPanel($panel) || $panel->hasPlugin('packstub-agents')) {
            return;
        }

        $panel->plugin(AgentsPlugin::make()
            ->name(config('cmf-agent.name'))
            ->agent(Assistant::class)
            ->tools(self::toolClasses())
            ->chat(driver: config('cmf-agent.driver'))
            ->authorizeUsing(static function (string $ability): bool {
                $actor = Filament::auth()->user();

                return $actor instanceof Authenticatable && Gate::forUser($actor)->allows($ability);
            })
            ->agentAccess((bool) config('cmf-agent.agent_access', true), ability: self::MANAGE_TOKENS));
    }

    public function boot(Panel $panel): void {}

    /**
     * 其它 Composer 包通过 extra.cmf-agent.tools 声明的工具 + 配置 tools。
     *
     * 自动发现里无效或损坏的声明只记录日志并跳过，避免影响后台面板启动；
     * 宿主显式配置的错误仍然抛出异常，便于开发期发现。
     *
     * @return list<class-string<AgentTool>>
     */
    public static function toolClasses(): array
    {
        /** @var list<class-string<AgentTool>> $discovered */
        $discovered = [];

        foreach (DiscoveredAgentTools::discover() as $tool) {
            if (! self::isAgentTool($tool)) {
                Log::warning('已跳过无效的 CMF 后台助手工具声明。', [
                    'tool' => $tool,
                    'source' => is_string($tool) ? DiscoveredAgentTools::origin($tool) : null,
                ]);

                continue;
            }

            $discovered[] = $tool;
        }

        /** @var list<class-string<AgentTool>> $configured */
        $configured = [];

        foreach ((array) config('cmf-agent.tools', []) as $tool) {
            if (! self::isAgentTool($tool)) {
                throw new InvalidArgumentException('CMF 后台助手工具必须是 Packstub AgentTool 的子类：'.(is_string($tool) ? $tool : get_debug_type($tool)));
            }

            $configured[] = $tool;
        }

        return self::rejectDuplicateToolNames(array_values(array_unique([
            ...$discovered,
            ...$configured,
        ])));
    }

    /**
     * 按 MCP 工具名去重，保留首个有效声明（自动发现 > 宿主配置）。
     *
     * @param  list<class-string<AgentTool>>  $candidates
     * @return list<class-string<AgentTool>>
     */
    private static function rejectDuplicateToolNames(array $candidates): array
    {
        $tools = [];
        $names = [];

        foreach ($candidates as $tool) {
            try {
                $name = app($tool)->name();
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('已跳过无法实例化的 CMF 后台助手工具。', [
                    'tool' => $tool,
                    'source' => DiscoveredAgentTools::origin($tool) ?? 'discovery',
                ]);

                continue;
            }

            if (isset($names[$name])) {
                Log::warning('CMF 后台助手工具名重复，已保留首次声明。', [
                    'tool' => $tool,
                    'duplicate_of' => $names[$name],
                    'name' => $name,
                    'source' => DiscoveredAgentTools::origin($tool) ?? 'config',
                ]);

                continue;
            }

            $names[$name] = $tool;
            $tools[] = $tool;
        }

        /** @var list<class-string<AgentTool>> */
        return $tools;
    }

    private static function isAgentTool(mixed $tool): bool
    {
        if (! is_string($tool) || $tool === '') {
            return false;
        }

        try {
            return class_exists($tool) && is_a($tool, AgentTool::class, true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function supportsPanel(Panel $panel): bool
    {
        return $panel->getId() === config('cmf-agent.panel') && ! $panel->hasTenancy();
    }
}
