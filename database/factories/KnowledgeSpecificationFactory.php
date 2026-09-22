<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSpecification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeSpecification> */
class KnowledgeSpecificationFactory extends Factory
{
    protected $model = KnowledgeSpecification::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'knowledge_item_id' => KnowledgeItem::factory(), 'name' => fake()->sentence(4), 'version' => '1.0', 'content' => fake()->paragraphs(2, true)];
    }
}