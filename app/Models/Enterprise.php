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
}
