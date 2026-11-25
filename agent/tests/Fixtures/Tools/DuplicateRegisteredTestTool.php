<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;

#[IsReadOnly]
final class DuplicateRegisteredTestTool extends AgentTool
{
    /** 与 RegisteredTestTool 同名，用于验证工具名冲突时保留首次声明。 */
    protected string $name = 'registered_test_tool';

    protected string $description = '测试用同名工具。';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): array
    {
        return ['ok' => false];
    }
}
