<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class ListProducts implements Operation
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{items: list<array{id: int, enterprise_id: int, name: string, slug: string, status: string, product_type: string, is_commercially_available: bool}>, pagination: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function execute(User $actor, array $input): array
    {
        $enterprise = Enterprise::query()->findOrFail((int) $input['enterprise_id']);
        Gate::forUser($actor)->authorize('view', $enterprise);

        $perPage = (int) ($input['per_page'] ?? 20);
        $page = (int) ($input['page'] ?? 1);

        $products = $enterprise->products()
            ->when(isset($input['status']), fn (Builder $query) => $query->where('status', $input['status']))
            ->when(isset($input['search']), function (Builder $query) use ($input): void {
                $search = (string) $input['search'];
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate($perPage, [
                'id', 'enterprise_id', 'name', 'slug', 'status', 'product_type', 'is_commercially_available',
            ], 'page', $page);

        return [
            'items' => array_values(array_map(static fn (Product $product): array => [
                'id' => (int) $product->getKey(),
                'enterprise_id' => (int) $product->enterprise_id,
                'name' => $product->name,
                'slug' => $product->slug,
                'status' => $product->status,
                'product_type' => $product->product_type,
                'is_commercially_available' => (bool) $product->is_commercially_available,
            ], $products->items())),
            'pagination' => [
                'page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ],
        ];
    }
}
