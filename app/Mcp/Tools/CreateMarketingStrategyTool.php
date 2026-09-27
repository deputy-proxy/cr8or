<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\Enterprise;
use App\Models\User;
use App\Operations\CreateMarketingStrategy;
use App\Mcp\Concerns\ExecutesCapabilities;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Request;
use Laravel\Mcp\Server\Response;
use Illuminate\Support\Facades\Validator;

final class CreateMarketingStrategyTool extends Tool
{
    use ExecutesCapabilities;

    protected string $name = 'create-marketing-strategy';

    protected string $description = 'Create a marketing strategy under an authorized enterprise through the governed CR8OR marketing strategy capability.';

    public function handle(Request $request): Response
    {
        return $this->executeWithErrors($request, 'mcp.marketing.strategy.create', function (Request $request) {
            $validated = Validator::make($request->all(), [
                'enterprise_id' => ['required', 'integer', 'exists:enterprises,id'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'description' => ['nullable', 'string', 'max:10000'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ])->validate();

            $actor = $this->authenticatedUser($request);
            $enterprise = Enterprise::query()->findOrFail((int) $validated['enterprise_id']);

            $strategy = $this->executeCapability(
                app(CapabilityRegistry::class),
                $actor,
                ['enterprise' => $enterprise, ...$validated],
                'marketing.strategy.create'
            );

            return $this->successResponse([
                'id' => $strategy->id,
                'enterprise_id' => $strategy->enterprise_id,
                'name' => $strategy->name,
                'description' => $strategy->description,
                'status' => $strategy->status,
            ]);
        });
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401, 'Authentication required.');
        }

        return $user;
    }
}
