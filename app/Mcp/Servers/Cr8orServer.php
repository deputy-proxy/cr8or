<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\EnterpriseContextResource;
use App\Mcp\Resources\KnowledgeContextResource;
use App\Mcp\Resources\StrategyContextResource;
use App\Mcp\Resources\WorkContextResource;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\GetAgentDescriptorTool;
use App\Mcp\Tools\GetApprovalRequestTool;
use App\Mcp\Tools\GetAudienceTool;
use App\Mcp\Tools\GetCampaignTool;
use App\Mcp\Tools\GetCapabilityTool;
use App\Mcp\Tools\GetChannelTool;
use App\Mcp\Tools\GetContentItemTool;
use App\Mcp\Tools\GetContentSeriesTool;
use App\Mcp\Tools\GetEnterpriseTool;
use App\Mcp\Tools\GetExecutionTool;
use App\Mcp\Tools\GetExpertDescriptorTool;
use App\Mcp\Tools\GetObjectiveTool;
use App\Mcp\Tools\GetStrategyTool;
use App\Mcp\Tools\GetWorkItemTool;
use App\Mcp\Tools\ListAgentDescriptorTool;
use App\Mcp\Tools\ListApprovalRequestTool;
use App\Mcp\Tools\ListAudienceTool;
use App\Mcp\Tools\ListCampaignTool;
use App\Mcp\Tools\ListCapabilitiesTool;
use App\Mcp\Tools\ListChannelTool;
use App\Mcp\Tools\ListContentItemTool;
use App\Mcp\Tools\ListContentSeriesTool;
use App\Mcp\Tools\ListEnterpriseTool;
use App\Mcp\Tools\ListExecutionTool;
use App\Mcp\Tools\ListExpertDescriptorTool;
use App\Mcp\Tools\ListObjectiveTool;
use App\Mcp\Tools\ListStrategyTool;
use App\Mcp\Tools\ListWorkItemTool;
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
        ListCapabilitiesTool::class,
        GetCapabilityTool::class,
        ListEnterpriseTool::class,
        GetEnterpriseTool::class,
        ListObjectiveTool::class,
        GetObjectiveTool::class,
        ListStrategyTool::class,
        GetStrategyTool::class,
        ListWorkItemTool::class,
        GetWorkItemTool::class,
        ListAgentDescriptorTool::class,
        GetAgentDescriptorTool::class,
        ListExpertDescriptorTool::class,
        GetExpertDescriptorTool::class,
        ListCampaignTool::class,
        GetCampaignTool::class,
        ListContentSeriesTool::class,
        GetContentSeriesTool::class,
        ListContentItemTool::class,
        GetContentItemTool::class,
        ListAudienceTool::class,
        GetAudienceTool::class,
        ListChannelTool::class,
        GetChannelTool::class,
        ListExecutionTool::class,
        GetExecutionTool::class,
        ListApprovalRequestTool::class,
        GetApprovalRequestTool::class,
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