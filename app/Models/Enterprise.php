<?php

namespace App\Models;

use Database\Factories\EnterpriseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'name', 'slug', 'status'])]
class Enterprise extends Model
{
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
