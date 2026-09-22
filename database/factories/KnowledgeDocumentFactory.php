<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeDocument> */
class KnowledgeDocumentFactory extends Factory
{
    protected $model = KnowledgeDocument::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'knowledge_source_id' => KnowledgeSource::factory(), 'title' => fake()->sentence(5), 'identifier' => fake()->optional()->uuid(), 'status' => 'active', 'content' => fake()->optional()->paragraphs(2, true), 'metadata' => null];
    }
}