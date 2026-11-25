<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Feature;

use Filament\Facades\Filament;
use InvalidArgumentException;
use Quansitech\Cmf\Agent\AgentPlugin;
use Quansitech\Cmf\Agent\Support\DiscoveredAgentTools;
use Quansitech\Cmf\Agent\Tests\Fixtures\Tools\DuplicateRegisteredTestTool;
use Quansitech\Cmf\Agent\Tests\Fixtures\Tools\RegisteredTestTool;
use Quansitech\Cmf\Agent\Tests\TestCase;

final class AgentToolTest extends TestCase
{
    public function test_tools_are_discovered_from_composer_package_metadata(): void
    {
        $installedJson = tempnam(sys_get_temp_dir(), 'composer-installed-');

        try {
            file_put_contents($installedJson, json_encode([
                'packages' => [
                    [
                        'name' => 'vendor/irrelevant-package',
                        'extra' => [
                            'cmf-agent' => [
                                'tools' => [RegisteredTestTool::class, ''],
                            ],
                        ],
                    ],
                    [
                        'name' => 'vendor/module-without-agent-tools',
                        'extra' => [],
                    ],
                ],
            ]));

            $discovered = DiscoveredAgentTools::fromInstalledJson($installedJson);
        } finally {
            unlink($installedJson);
        }

        $this->assertSame([RegisteredTestTool::class], $discovered);
    }

    public function test_invalid_discovered_tools_do_not_break_panel_tool_classes(): void
    {
        $installedJson = tempnam(sys_get_temp_dir(), 'composer-installed-');

        try {
            file_put_contents($installedJson, json_encode([
                'packages' => [
                    [
                        'name' => 'vendor/module-with-agent-tools',
                        'extra' => [
                            'cmf-agent' => [
                                'tools' => ['  '.RegisteredTestTool::class.'  ', DuplicateRegisteredTestTool::class, \stdClass::class, 'App\\MissingAgentTool'],
                            ],
                        ],
                    ],
                ],
            ]));

            DiscoveredAgentTools::flush();
            DiscoveredAgentTools::discover($installedJson);

            $tools = AgentPlugin::toolClasses();
        } finally {
            unlink($installedJson);
        }

        $this->assertContains(RegisteredTestTool::class, $tools);
        $this->assertNotContains(DuplicateRegisteredTestTool::class, $tools);
        $this->assertNotContains(\stdClass::class, $tools);
        $this->assertNotContains('App\\MissingAgentTool', $tools);
    }

    public function test_missing_installed_json_discovery_is_empty(): void
    {
        $this->assertSame([], DiscoveredAgentTools::fromInstalledJson('/missing/vendor/composer/installed.json'));
    }

    public function test_configured_tools_are_included_in_tool_classes(): void
    {
        config(['cmf-agent.tools' => [RegisteredTestTool::class]]);

        $this->assertContains(RegisteredTestTool::class, AgentPlugin::toolClasses());
    }

    public function test_non_agent_tool_in_config_is_rejected_when_building_tool_classes(): void
    {
        config(['cmf-agent.tools' => [\stdClass::class]]);

        $this->expectException(InvalidArgumentException::class);

        AgentPlugin::toolClasses();
    }

    public function test_agent_plugin_registers_packstub_agents_on_admin_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasPlugin('cmf-agent'));
        $this->assertTrue($panel->hasPlugin('packstub-agents'));
    }

    public function test_chinese_translations_are_loaded_from_the_package(): void
    {
        $this->assertSame('新对话', __('New chat'));
        $this->assertSame('Agent 访问', __('Agent access'));
    }
}
