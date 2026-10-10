<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Issue;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Issue> */
class IssueFactory extends Factory
{
    public function definition(): array
    {
        $repository = 'example/repository';
        $number = fake()->unique()->numberBetween(1, 10000);

        return [
            'enterprise_id' => Enterprise::factory(),
            'repository' => $repository,
            'external_id' => (string) fake()->unique()->numberBetween(1000000, 999999999),
            'number' => $number,
            'title' => fake()->sentence(),
            'state' => fake()->randomElement(['open', 'closed']),
            'labels' => [],
            'author_login' => fake()->userName(),
            'github_created_at' => now()->subDays(4),
            'github_updated_at' => now()->subDay(),
            'github_closed_at' => null,
            'url' => 'https://github.com/'.$repository.'/issues/'.$number,
            'last_synced_at' => now(),
        ];
    }
}
