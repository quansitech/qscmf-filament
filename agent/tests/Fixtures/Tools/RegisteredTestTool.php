<?php

declare(strict_types=1);

namespace Quansitech\Cmf\Agent\Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Packstub\Agents\Mcp\AgentTool;

#[IsReadOnly]
final class RegisteredTestTool extends AgentTool
{
    protected string $name = 'registered_test_tool';

    protected string $description = '测试用注册工具。';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request): array
    {
        return ['ok' => true];
    }
}
