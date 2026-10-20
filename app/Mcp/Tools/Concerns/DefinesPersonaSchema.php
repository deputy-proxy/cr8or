<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Persona;
use Illuminate\Contracts\JsonSchema\JsonSchema;

trait DefinesPersonaSchema
{
    /** @return array<string, mixed> */
    protected static function personaSchemaFields(JsonSchema $schema): array
    {
        return [
            'persona_type' => $schema->string()->enum(['buyer', 'user', 'influencer', 'decision_maker', 'gatekeeper']),
            'age_range' => $schema->string()->max(100),
            'location' => $schema->string()->max(255),
            'job_title' => $schema->string()->max(255),
            'income_range' => $schema->string()->max(100),
            'education_level' => $schema->string()->max(100),
            'family_status' => $schema->string()->max(100),
            'research_confidence' => $schema->number()->min(0)->max(1),
            'last_reviewed_at' => $schema->string()->max(10),
            'psychographics' => $schema->object([
                'values' => $schema->array()->items($schema->string()->max(500)),
                'interests' => $schema->array()->items($schema->string()->max(500)),
                'lifestyle' => $schema->array()->items($schema->string()->max(500)),
                'attitudes' => $schema->array()->items($schema->string()->max(500)),
                'aspirations' => $schema->array()->items($schema->string()->max(500)),
            ])->withoutAdditionalProperties(),
            'goals_and_motivations' => $schema->object([
                'goals' => $schema->array()->items($schema->string()->max(500)),
                'purchase_motivations' => $schema->array()->items($schema->string()->max(500)),
                'success_criteria' => $schema->array()->items($schema->string()->max(500)),
                'practical_motivations' => $schema->array()->items($schema->string()->max(500)),
                'emotional_motivations' => $schema->array()->items($schema->string()->max(500)),
            ])->withoutAdditionalProperties(),
            'pain_points' => $schema->object([
                'problems' => $schema->array()->items($schema->string()->max(500)),
                'obstacles' => $schema->array()->items($schema->string()->max(500)),
                'current_alternatives' => $schema->array()->items($schema->string()->max(500)),
                'consequences_of_inaction' => $schema->array()->items($schema->string()->max(500)),
                'desired_improvements' => $schema->array()->items($schema->string()->max(500)),
            ])->withoutAdditionalProperties(),
            'buying_behavior' => $schema->object([
                'preferred_channels' => $schema->array()->items($schema->string()->max(100)),
                'research_habits' => $schema->array()->items($schema->string()->max(500)),
                'decision_criteria' => $schema->array()->items($schema->string()->max(500)),
                'purchase_triggers' => $schema->array()->items($schema->string()->max(500)),
                'objections' => $schema->array()->items($schema->string()->max(500)),
                'budget_sensitivity' => $schema->string()->max(500),
                'purchase_frequency' => $schema->string()->max(100),
                'preferred_content_formats' => $schema->array()->items($schema->string()->max(100)),
            ])->withoutAdditionalProperties(),
            'product_ids' => $schema->array()->items($schema->integer()->min(1)),
        ];
    }

    /** @return array<string, list<string>> */
    protected static function personaValidationRules(): array
    {
        $rules = [
            'persona_type' => ['sometimes', 'nullable', 'string', 'in:buyer,user,influencer,decision_maker,gatekeeper'],
            'age_range' => ['sometimes', 'nullable', 'string', 'max:100'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'income_range' => ['sometimes', 'nullable', 'string', 'max:100'],
            'education_level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'family_status' => ['sometimes', 'nullable', 'string', 'max:100'],
            'research_confidence' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'last_reviewed_at' => ['sometimes', 'nullable', 'date'],
            'psychographics' => ['sometimes', 'nullable', 'array:values,interests,lifestyle,attitudes,aspirations'],
            'psychographics.values' => ['sometimes', 'array', 'list'],
            'psychographics.values.*' => ['string', 'max:500'],
            'psychographics.interests' => ['sometimes', 'array', 'list'],
            'psychographics.interests.*' => ['string', 'max:500'],
            'psychographics.lifestyle' => ['sometimes', 'array', 'list'],
            'psychographics.lifestyle.*' => ['string', 'max:500'],
            'psychographics.attitudes' => ['sometimes', 'array', 'list'],
            'psychographics.attitudes.*' => ['string', 'max:500'],
            'psychographics.aspirations' => ['sometimes', 'array', 'list'],
            'psychographics.aspirations.*' => ['string', 'max:500'],
            'goals_and_motivations' => ['sometimes', 'nullable', 'array:goals,purchase_motivations,success_criteria,practical_motivations,emotional_motivations'],
            'goals_and_motivations.goals' => ['sometimes', 'array', 'list'],
            'goals_and_motivations.goals.*' => ['string', 'max:500'],
            'goals_and_motivations.purchase_motivations' => ['sometimes', 'array', 'list'],
            'goals_and_motivations.purchase_motivations.*' => ['string', 'max:500'],
            'goals_and_motivations.success_criteria' => ['sometimes', 'array', 'list'],
            'goals_and_motivations.success_criteria.*' => ['string', 'max:500'],
            'goals_and_motivations.practical_motivations' => ['sometimes', 'array', 'list'],
            'goals_and_motivations.practical_motivations.*' => ['string', 'max:500'],
            'goals_and_motivations.emotional_motivations' => ['sometimes', 'array', 'list'],
            'goals_and_motivations.emotional_motivations.*' => ['string', 'max:500'],
            'pain_points' => ['sometimes', 'nullable', 'array:problems,obstacles,current_alternatives,consequences_of_inaction,desired_improvements'],
            'pain_points.problems' => ['sometimes', 'array', 'list'],
            'pain_points.problems.*' => ['string', 'max:500'],
            'pain_points.obstacles' => ['sometimes', 'array', 'list'],
            'pain_points.obstacles.*' => ['string', 'max:500'],
            'pain_points.current_alternatives' => ['sometimes', 'array', 'list'],
            'pain_points.current_alternatives.*' => ['string', 'max:500'],
            'pain_points.consequences_of_inaction' => ['sometimes', 'array', 'list'],
            'pain_points.consequences_of_inaction.*' => ['string', 'max:500'],
            'pain_points.desired_improvements' => ['sometimes', 'array', 'list'],
            'pain_points.desired_improvements.*' => ['string', 'max:500'],
            'buying_behavior' => ['sometimes', 'nullable', 'array:preferred_channels,research_habits,decision_criteria,purchase_triggers,objections,budget_sensitivity,purchase_frequency,preferred_content_formats'],
            'buying_behavior.preferred_channels' => ['sometimes', 'array', 'list'],
            'buying_behavior.preferred_channels.*' => ['string', 'max:100'],
            'buying_behavior.research_habits' => ['sometimes', 'array', 'list'],
            'buying_behavior.research_habits.*' => ['string', 'max:500'],
            'buying_behavior.decision_criteria' => ['sometimes', 'array', 'list'],
            'buying_behavior.decision_criteria.*' => ['string', 'max:500'],
            'buying_behavior.purchase_triggers' => ['sometimes', 'array', 'list'],
            'buying_behavior.purchase_triggers.*' => ['string', 'max:500'],
            'buying_behavior.objections' => ['sometimes', 'array', 'list'],
            'buying_behavior.objections.*' => ['string', 'max:500'],
            'buying_behavior.budget_sensitivity' => ['sometimes', 'nullable', 'string', 'max:500'],
            'buying_behavior.purchase_frequency' => ['sometimes', 'nullable', 'string', 'max:100'],
            'buying_behavior.preferred_content_formats' => ['sometimes', 'array', 'list'],
            'buying_behavior.preferred_content_formats.*' => ['string', 'max:100'],
            'product_ids' => ['sometimes', 'array', 'list'],
            'product_ids.*' => ['integer', 'distinct', 'min:1'],
        ];

        return $rules;
    }

    /** @return array<string, mixed> */
    protected static function personaResult(Persona $record): array
    {
        $lastReviewedAt = $record->getRawOriginal('last_reviewed_at');

        return [
            'persona_type' => $record->persona_type,
            'age_range' => $record->age_range,
            'location' => $record->location,
            'job_title' => $record->job_title,
            'income_range' => $record->income_range,
            'education_level' => $record->education_level,
            'family_status' => $record->family_status,
            'research_confidence' => $record->research_confidence,
            'last_reviewed_at' => $lastReviewedAt === null ? null : substr((string) $lastReviewedAt, 0, 10),
            'psychographics' => $record->psychographics,
            'goals_and_motivations' => $record->goals_and_motivations,
            'pain_points' => $record->pain_points,
            'buying_behavior' => $record->buying_behavior,
            'product_ids' => $record->products->modelKeys(),
        ];
    }
}