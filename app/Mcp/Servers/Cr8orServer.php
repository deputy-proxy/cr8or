<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\EnterpriseContextResource;
use App\Mcp\Resources\KnowledgeContextResource;
use App\Mcp\Resources\StrategyContextResource;
use App\Mcp\Resources\WorkContextResource;
use App\Mcp\Tools\AnalyzeBusinessContextTool;
use App\Mcp\Tools\ArchiveAudienceTool;
use App\Mcp\Tools\ArchiveChannelTool;
use App\Mcp\Tools\ArchiveMarketingStrategyTool;
use App\Mcp\Tools\ConnectSocialAccountTool;
use App\Mcp\Tools\CreateAudienceTool;
use App\Mcp\Tools\CreateCampaignTool;
use App\Mcp\Tools\CreateChannelTool;
use App\Mcp\Tools\CreateContentItemTool;
use App\Mcp\Tools\CreateContentSeriesTool;
use App\Mcp\Tools\CreateEnterpriseTool;
use App\Mcp\Tools\CreateObjectiveTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateStrategyTool;
use App\Mcp\Tools\CreateWorkItemTool;
use App\Mcp\Tools\DelegateAgentTool;
use App\Mcp\Tools\DisconnectSocialAccountTool;
use App\Mcp\Tools\GenerateFinancialReportTool;
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
use App\Mcp\Tools\GetGoalTool;
use App\Mcp\Tools\GetInitiativeTool;
use App\Mcp\Tools\GetKpiTool;
use App\Mcp\Tools\GetMarketingStrategyTool;
use App\Mcp\Tools\GetObjectiveTool;
use App\Mcp\Tools\GetPlanTool;
use App\Mcp\Tools\GetProjectTool;
use App\Mcp\Tools\GetSocialAccountTool;
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
use App\Mcp\Tools\ListGoalTool;
use App\Mcp\Tools\ListInitiativeTool;
use App\Mcp\Tools\ListKpiTool;
use App\Mcp\Tools\ListMarketingStrategyTool;
use App\Mcp\Tools\ListObjectiveTool;
use App\Mcp\Tools\ListPlanTool;
use App\Mcp\Tools\ListProjectTool;
use App\Mcp\Tools\ListSocialAccountTool;
use App\Mcp\Tools\ListStrategyTool;
use App\Mcp\Tools\ListWorkItemTool;
use App\Mcp\Tools\MarkContentPublicationReadyTool;
use App\Mcp\Tools\PlanMarketingTool;
use App\Mcp\Tools\PublishContentTool;
use App\Mcp\Tools\RequestApprovalTool;
use App\Mcp\Tools\SubmitContentForReviewTool;
use App\Mcp\Tools\TransitionCampaignTool;
use App\Mcp\Tools\TransitionContentSeriesTool;
use App\Mcp\Tools\UpdateAudienceTool;
use App\Mcp\Tools\UpdateCampaignTool;
use App\Mcp\Tools\UpdateChannelTool;
use App\Mcp\Tools\UpdateContentItemTool;
use App\Mcp\Tools\UpdateContentSeriesTool;
use App\Mcp\Tools\UpdateObjectiveTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateSocialAccountTool;
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
        ListMarketingStrategyTool::class,
        ListGoalTool::class,
        GetGoalTool::class,
        ListKpiTool::class,
        GetKpiTool::class,
        ListPlanTool::class,
        GetPlanTool::class,
        ListInitiativeTool::class,
        GetInitiativeTool::class,
        GetMarketingStrategyTool::class,
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
        ListSocialAccountTool::class,
        GetSocialAccountTool::class,
        ListProjectTool::class,
        GetProjectTool::class,
        ListWorkItemTool::class,
        GetWorkItemTool::class,
        ListAgentDescriptorTool::class,
        GetAgentDescriptorTool::class,
        ListExpertDescriptorTool::class,
        GetExpertDescriptorTool::class,
        ListExecutionTool::class,
        GetExecutionTool::class,
        ListApprovalRequestTool::class,
        GetApprovalRequestTool::class,

        CreateObjectiveTool::class,
        UpdateObjectiveTool::class,
        CreateStrategyTool::class,
        UpdateStrategyTool::class,
        CreateCampaignTool::class,
        UpdateCampaignTool::class,
        TransitionCampaignTool::class,
        CreateContentSeriesTool::class,
        CreateEnterpriseTool::class,
        UpdateContentSeriesTool::class,
        TransitionContentSeriesTool::class,
        CreateAudienceTool::class,
        UpdateAudienceTool::class,
        ArchiveAudienceTool::class,
        CreateChannelTool::class,
        UpdateChannelTool::class,
        ArchiveChannelTool::class,
        ArchiveMarketingStrategyTool::class,
        ConnectSocialAccountTool::class,
        UpdateSocialAccountTool::class,
        DisconnectSocialAccountTool::class,
        CreateProjectTool::class,
        UpdateProjectTool::class,

        CreateWorkItemTool::class,
        UpdateWorkItemTool::class,
        CreateContentItemTool::class,
        UpdateContentItemTool::class,
        SubmitContentForReviewTool::class,
        MarkContentPublicationReadyTool::class,
        PublishContentTool::class,
        RequestApprovalTool::class,
        DelegateAgentTool::class,
        AnalyzeBusinessContextTool::class,
        PlanMarketingTool::class,
        GenerateFinancialReportTool::class,
    ];

    protected array $resources = [
        EnterpriseContextResource::class,
        StrategyContextResource::class,
        KnowledgeContextResource::class,
        WorkContextResource::class,
    ];

    protected array $prompts = [];
}
