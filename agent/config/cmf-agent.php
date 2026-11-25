<?php

return [
    'panel' => 'admin',
    'name' => 'CMF 助手',
    'driver' => env('CMF_AGENT_DRIVER', 'sync'),

    // 使用 Packstub 自带的 /mcp 端点与 Agent access 令牌页。
    'enable_packstub_mcp' => true,
    'agent_access' => true,

    // 宿主定义的 Packstub AgentTool 子类。写工具在聊天中需要人工批准。
    'tools' => [],
];
