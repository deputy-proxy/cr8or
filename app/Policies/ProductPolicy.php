<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $this->enterprisePolicy()->view($user, $product->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->enterprisePolicy()->update($user, $product->enterprise);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->enterprisePolicy()->delete($user, $product->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}