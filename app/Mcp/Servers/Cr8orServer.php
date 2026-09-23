<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\EnterpriseContextResource;
use App\Mcp\Resources\KnowledgeContextResource;
use App\Mcp\Resources\StrategyContextResource;
use App\Mcp\Resources\WorkContextResource;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\MarkContentPublicationReadyTool;
use App\Mcp\Tools\PublishContentTool;
use App\Mcp\Tools\RequestApprovalTool;
use App\Mcp\Tools\SubmitContentForReviewTool;
use App\Mcp\Tools\UpdateContentItemTool;
use App\Mcp\Tools\UpdateStrategyTool;
use App\Mcp\Tools\UpdateWorkItemTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CR8OR MCP Server')]
#[Version('0.1.0')]
#[Instructions('Provides controlled access to CR8OR capabilities through authorized enterprise resources.')]
class Cr8orServer extends Server
{
    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        CreateWorkItemTool::class,
        UpdateWorkItemTool::class,
        CreateStrategyTool::class,
        UpdateStrategyTool::class,
        CreateContentItemTool::class,
        UpdateContentItemTool::class,
        SubmitContentForReviewTool::class,
        MarkContentPublicationReadyTool::class,
        PublishContentTool::class,
        RequestApprovalTool::class,
    ];

    protected array $resources = [
        EnterpriseContextResource::class,
        StrategyContextResource::class,
        KnowledgeContextResource::class,
        WorkContextResource::class,
    ];

    protected array $prompts = [];
}