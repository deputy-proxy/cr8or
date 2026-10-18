<?php

namespace App\Mcp\Tools;

use App\Models\Enterprise;
use App\Models\Product;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-product')]
#[Description('Create a product under an authorized enterprise. The slug is generated from the name when omitted; product names and slugs are scoped to the enterprise.')]
class CreateProductTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255)->required(),
            'slug' => $schema->string()->min(1)->max(255),
            'status' => $schema->string()->enum([Product::STATUS_ACTIVE, Product::STATUS_ARCHIVED]),
        ];
    }

    protected static function rules(): array
    {
        return [
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'min:1', 'max:255', 'alpha_dash'],
            'status' => ['sometimes', 'string', 'in:active,archived'],
        ];
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['enterprise_id' => (int) $validated['enterprise_id']];
    }

    /** @param array<string, mixed> $validated
     * @return array{0: string, 1: mixed}
     */
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['createForEnterprise', [Product::class, static::enterprise($validated)]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createProduct($actor, static::enterprise($validated), $validated);
    }

    protected static function result(Model $record): array
    {
        if (! $record instanceof Product) {
            throw new \LogicException('Unexpected product result.');
        }

        return [
            'id' => $record->id,
            'enterprise_id' => $record->enterprise_id,
            'name' => $record->name,
            'slug' => $record->slug,
            'status' => $record->status,
        ];
    }
}
