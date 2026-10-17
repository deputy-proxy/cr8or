<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\StrategicContextService;
use Illuminate\Validation\ValidationException;

final class CreateCompetitor implements Operation
{
    public function __construct(private readonly StrategicContextService $strategicContext) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, array $input): Competitor
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) ($input['enterprise_id'] ?? 0));

        if (! isset($input['name']) || ! is_string($input['name'])) {
            throw ValidationException::withMessages(['name' => 'A competitor name is required.']);
        }

        foreach (['website', 'positioning'] as $field) {
            if (isset($input[$field]) && ! is_string($input[$field])) {
                throw ValidationException::withMessages([$field => 'This field must be a string.']);
            }
        }

        foreach (array_merge(['strengths', 'weaknesses'], Competitor::PROFILE_FIELDS) as $field) {
            if (isset($input[$field]) && ! is_array($input[$field])) {
                throw ValidationException::withMessages([$field => 'This field must be a structured list.']);
            }
        }

        $profile = array_intersect_key($input, array_flip(Competitor::PROFILE_FIELDS));

        return $this->strategicContext->createCompetitor(
            $actor,
            $enterprise,
            $input['name'],
            $input['website'] ?? null,
            $input['positioning'] ?? null,
            $input['strengths'] ?? [],
            $input['weaknesses'] ?? [],
            $profile,
        );
    }
}