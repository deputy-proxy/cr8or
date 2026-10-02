<?php

namespace Database\Seeders;

use App\Enums\MembershipRole;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\CanonicalWorkflowProvisioner;
use Illuminate\Database\Seeder;

class CanonicalWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::query()->where('email', 'test@example.com')->firstOrFail();

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'valid-guide'],
            ['name' => 'valid.guide']
        );

        $enterprise = Enterprise::query()->firstOrCreate(
            ['slug' => 'valid.guide'],
            ['organization_id' => $organization->getKey(), 'name' => 'valid.guide', 'status' => 'active']
        );

        Membership::query()->firstOrCreate(
            ['user_id' => $actor->getKey(), 'organization_id' => $organization->getKey()],
            ['role' => MembershipRole::Owner]
        );

        $provisioner = app(CanonicalWorkflowProvisioner::class);
        $provisioner->provisionStrategyCreation($enterprise, $actor);
        $provisioner->provisionMarketingStrategy($enterprise, $actor);
    }
}