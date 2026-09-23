<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Script> */
class ScriptFactory extends Factory
{
    public function definition(): array
    {
        return ['content_item_id' => ContentItem::factory(), 'title' => fake()->sentence(4), 'body' => fake()->paragraphs(2, true)];
    }
}
