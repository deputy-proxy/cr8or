<?php

namespace App\Policies;

use App\Models\Enterprise;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->enterprisePolicy()->view($user, $invoice->enterprise);
    }

    public function create(User $user, Enterprise $enterprise): bool
    {
        return $this->enterprisePolicy()->create($user, $enterprise->organization);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->enterprisePolicy()->update($user, $invoice->enterprise);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->enterprisePolicy()->delete($user, $invoice->enterprise);
    }

    private function enterprisePolicy(): EnterprisePolicy
    {
        return new EnterprisePolicy;
    }
}