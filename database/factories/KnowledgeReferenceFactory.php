<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeReference> */
class KnowledgeReferenceFactory extends Factory
{
    protected $model = KnowledgeReference::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'knowledge_item_id' => KnowledgeItem::factory(), 'knowledge_document_id' => KnowledgeDocument::factory(), 'type' => 'source', 'label' => fake()->optional()->sentence(3), 'locator' => fake()->optional()->bothify('section-##'), 'metadata' => null];
    }
}