<?php

namespace App\Models;

use Database\Factories\EnterpriseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** @property array<array-key, mixed>|null $connections */
#[Fillable(['organization_id', 'enterprise_group_id', 'enterprise_category_id', 'name', 'slug', 'status', 'connections', 'github_repository', 'github_repository_url', 'github_issues_sync_status', 'github_issues_synced_at', 'github_issues_sync_error', 'website_domain'])]
class Enterprise extends Model
{
    public const CONNECTION_TYPES = ['depends_on', 'supports', 'integrates_with', 'related_to', 'competes_with', 'owns'];

    protected function casts(): array
    {
        return ['connections' => 'array', 'github_issues_synced_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $enterprise): void {
            foreach (['enterprise_group_id', 'enterprise_category_id'] as $field) {
                $id = $enterprise->getAttribute($field);
                if ($id === null) {
                    continue;
                }
                $record = $field === 'enterprise_group_id'
                    ? EnterpriseGroup::query()->find((int) $id)
                    : EnterpriseCategory::query()->find((int) $id);
                if ($record === null || (int) $record->organization_id !== (int) $enterprise->organization_id) {
                    throw new LogicException('Enterprise group and category must belong to the same organization.');
                }
            }

            $connections = $enterprise->getAttribute('connections') ?? [];
            if (! is_array($connections) || ! array_is_list($connections)) {
                throw new LogicException('Enterprise connections must be a list.');
            }
            foreach ($connections as $connection) {
                if (! is_array($connection)) {
                    throw new LogicException('Enterprise connection entries must be objects.');
                }
                $targetId = $connection['target_enterprise_id'] ?? null;
                $type = $connection['type'] ?? null;
                $direction = $connection['direction'] ?? null;
                $description = $connection['description'] ?? null;
                if (! is_numeric($targetId) || (int) $targetId <= 0 || (int) $targetId === (int) $enterprise->getKey()
                    || ! is_string($type) || ! in_array($type, self::CONNECTION_TYPES, true)
                    || ! is_string($direction) || ! in_array($direction, ['incoming', 'outgoing', 'bidirectional'], true)
                    || ($description !== null && (! is_string($description) || mb_strlen($description) > 500))
                    || array_diff(array_keys($connection), ['target_enterprise_id', 'type', 'direction', 'description']) !== []) {
                    throw new LogicException('Enterprise connection entry has an invalid shape or unsupported value.');
                }
                $target = self::query()->find((int) $targetId);
                if ($target === null || (int) $target->organization_id !== (int) $enterprise->organization_id) {
                    throw new LogicException('Enterprise connections must target an Enterprise in the same organization.');
                }
            }

            $repository = $enterprise->getAttribute('github_repository');
            $repositoryUrl = $enterprise->getAttribute('github_repository_url');
            if (($repository === null) !== ($repositoryUrl === null)) {
                throw new LogicException('GitHub repository identity and URL must be configured together.');
            }
            if ($repository !== null) {
                $parsedRepositoryUrl = parse_url((string) $repositoryUrl);
                if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) $repository) !== 1
                    || ! is_array($parsedRepositoryUrl)
                    || ($parsedRepositoryUrl['scheme'] ?? null) !== 'https'
                    || ($parsedRepositoryUrl['host'] ?? null) !== 'github.com'
                    || array_intersect(['port', 'user', 'pass', 'query', 'fragment'], array_keys($parsedRepositoryUrl)) !== []
                    || rtrim((string) ($parsedRepositoryUrl['path'] ?? ''), '/') !== '/'.$repository
                ) {
                    throw new LogicException('GitHub repository URL must match the canonical owner/repo identity.');
                }
            }

            $domain = $enterprise->getAttribute('website_domain');
            if ($domain !== null && (strtolower((string) $domain) !== (string) $domain || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', (string) $domain) !== 1)) {
                throw new LogicException('Website domain must be a lowercase hostname without a scheme or path.');
            }
        });
    }

    /** @return BelongsTo<EnterpriseGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EnterpriseGroup::class, 'enterprise_group_id');
    }

    /** @return BelongsTo<EnterpriseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EnterpriseCategory::class, 'enterprise_category_id');
    }

    /** @return HasMany<IntegrationConnection, $this> */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class);
    }

    /** @return HasMany<Issue, $this> */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /** @use HasFactory<EnterpriseFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasOne<EnterpriseContext, $this> */
    public function context(): HasOne
    {
        return $this->hasOne(EnterpriseContext::class);
    }

    /** @return HasMany<MarketingStrategy, $this> */
    public function marketingStrategies(): HasMany
    {
        return $this->hasMany(MarketingStrategy::class);
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<ContentItem, $this> */
    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    /** @return HasMany<Channel, $this> */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /** @return HasMany<Audience, $this> */
    public function audiences(): HasMany
    {
        return $this->hasMany(Audience::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Customer, $this> */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** @return HasMany<Partner, $this> */
    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** @return HasMany<Kpi, $this> */
    public function kpis(): HasMany
    {
        return $this->hasMany(Kpi::class);
    }

    /** @return HasMany<EnterpriseDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(EnterpriseDecision::class);
    }

    /** @return HasMany<AgentAssignment, $this> */
    public function agentAssignments(): HasMany
    {
        return $this->hasMany(AgentAssignment::class);
    }

    /** @return HasMany<AgentEpisodicMemory, $this> */
    public function agentEpisodicMemories(): HasMany
    {
        return $this->hasMany(AgentEpisodicMemory::class);
    }

    /** @return HasMany<AgentSemanticMemory, $this> */
    public function agentSemanticMemories(): HasMany
    {
        return $this->hasMany(AgentSemanticMemory::class);
    }

    /** @return HasMany<KnowledgeSource, $this> */
    public function knowledgeSources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    /** @return HasMany<KnowledgeDocument, $this> */
    public function knowledgeDocuments(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    /** @return HasMany<KnowledgeItem, $this> */
    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    /** @return HasMany<KnowledgeContext, $this> */
    public function knowledgeContexts(): HasMany
    {
        return $this->hasMany(KnowledgeContext::class);
    }

    /** @return HasMany<KnowledgeVersion, $this> */
    public function knowledgeVersions(): HasMany
    {
        return $this->hasMany(KnowledgeVersion::class);
    }

    /** @return HasMany<KnowledgeReference, $this> */
    public function knowledgeReferences(): HasMany
    {
        return $this->hasMany(KnowledgeReference::class);
    }

    /** @return HasMany<KnowledgeSpecification, $this> */
    public function knowledgeSpecifications(): HasMany
    {
        return $this->hasMany(KnowledgeSpecification::class);
    }

    /** @return HasMany<Vision, $this> */
    public function visions(): HasMany
    {
        return $this->hasMany(Vision::class);
    }

    /** @return HasMany<Mission, $this> */
    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    /** @return HasMany<Competitor, $this> */
    public function competitors(): HasMany
    {
        return $this->hasMany(Competitor::class);
    }

    /** @return HasMany<Objective, $this> */
    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<WorkItem, $this> */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    /** @return HasMany<Dependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(Dependency::class);
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /** @return HasMany<GenerationRequest, $this> */
    public function generationRequests(): HasMany
    {
        return $this->hasMany(GenerationRequest::class);
    }

    /** @return HasMany<RenderRequest, $this> */
    public function renderRequests(): HasMany
    {
        return $this->hasMany(RenderRequest::class);
    }

    /** @return HasMany<Assignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** @return HasMany<FinancialAccount, $this> */
    public function financialAccounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class);
    }

    /** @return HasMany<FinancialReport, $this> */
    public function financialReports(): HasMany
    {
        return $this->hasMany(FinancialReport::class);
    }

    /** @return HasMany<Report, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /** @return HasMany<MetricDefinition, $this> */
    public function metricDefinitions(): HasMany
    {
        return $this->hasMany(MetricDefinition::class);
    }

    /** @return HasMany<BusinessHealthResult, $this> */
    public function businessHealthResults(): HasMany
    {
        return $this->hasMany(BusinessHealthResult::class);
    }

    /** @return HasMany<FinancialPeriod, $this> */
    public function financialPeriods(): HasMany
    {
        return $this->hasMany(FinancialPeriod::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<Revenue, $this> */
    public function revenues(): HasMany
    {
        return $this->hasMany(Revenue::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<TransactionCategory, $this> */
    public function transactionCategories(): HasMany
    {
        return $this->hasMany(TransactionCategory::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Statement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(Statement::class);
    }

    /** @return HasMany<StatementEntry, $this> */
    public function statementEntries(): HasMany
    {
        return $this->hasMany(StatementEntry::class);
    }
}