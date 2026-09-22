<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeVersion> */
class KnowledgeVersionFactory extends Factory
{
    protected $model = KnowledgeVersion::class;

    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'knowledge_item_id' => KnowledgeItem::factory(),
            'version' => null,
            'content' => fake()->paragraphs(2, true),
            'context_snapshot' => null,
            'recorded_at' => now(),
        ];
    }
}
