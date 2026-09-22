<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeItem> */
class KnowledgeItemFactory extends Factory
{
    protected $model = KnowledgeItem::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'knowledge_source_id' => KnowledgeSource::factory(), 'knowledge_document_id' => KnowledgeDocument::factory(), 'knowledge_context_id' => KnowledgeContext::factory(), 'title' => fake()->sentence(5), 'type' => 'fact', 'summary' => fake()->optional()->paragraph()];
    }
}
