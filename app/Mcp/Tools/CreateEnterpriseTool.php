<?php

namespace App\Mcp\Tools;

use App\Models\Organization;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-enterprise')]
#[Description('Create an enterprise under an organization where the authenticated actor has enterprise creation authority.')]
class CreateEnterpriseTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'organization_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255)->required(),
            'slug' => $schema->string()->min(1)->max(255),
            'status' => $schema->string()->min(1)->max(100),
        ];
    }

    public function handle(Request $request, DomainResourceService $domain): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'enterprise.create', function () use ($request, $domain) {
            $validated = $request->validate([
                'organization_id' => ['required', 'integer', 'min:1', 'exists:organizations,id'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'slug' => ['nullable', 'string', 'min:1', 'max:255'],
                'status' => ['nullable', 'string', 'min:1', 'max:100'],
            ]);

            $actor = $request->user();

            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $organization = Organization::query()->findOrFail((int) $validated['organization_id']);
            $enterprise = $domain->createEnterprise($actor, $organization, $validated);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $enterprise->id,
                    'organization_id' => $enterprise->organization_id,
                    'name' => $enterprise->name,
                    'slug' => $enterprise->slug,
                    'status' => $enterprise->status,
                    'created_at' => $enterprise->created_at,
                    'updated_at' => $enterprise->updated_at,
                ],
            ]);
        });
    }
}
