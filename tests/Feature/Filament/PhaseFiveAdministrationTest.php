<?php

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\AssetVersions\AssetVersionResource;
use App\Filament\Resources\GenerationJobs\GenerationJobResource;
use App\Filament\Resources\GenerationRequests\GenerationRequestResource;
use App\Filament\Resources\IntegrationConnections\IntegrationConnectionResource;
use App\Filament\Resources\PublicationResults\PublicationResultResource;
use App\Filament\Resources\Publications\PublicationResource;
use App\Filament\Resources\PublicationSchedules\PublicationScheduleResource;
use App\Filament\Resources\PublishingJobs\PublishingJobResource;
use App\Filament\Resources\RenderJobs\RenderJobResource;
use App\Filament\Resources\RenderOutputs\RenderOutputResource;
use App\Filament\Resources\RenderRequests\RenderRequestResource;
use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Filament\Resources\Transformations\TransformationResource;
use App\Models\Asset;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\GenerationRequest;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\RenderRequest;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('scopes phase 5 media and publishing resources to the authenticated organizations', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();

    Membership::factory()->owner()->create([
        'user_id' => $user,
        'organization_id' => $organization,
    ]);

    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $otherOrganization]);

    $asset = Asset::factory()->create(['enterprise_id' => $enterprise]);
    $foreignAsset = Asset::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $generationRequest = GenerationRequest::factory()->create(['enterprise_id' => $enterprise, 'asset_id' => $asset]);
    $foreignGenerationRequest = GenerationRequest::factory()->create(['enterprise_id' => $foreignEnterprise, 'asset_id' => $foreignAsset]);
    $renderRequest = RenderRequest::factory()->create(['enterprise_id' => $enterprise, 'asset_id' => $asset]);
    $foreignRenderRequest = RenderRequest::factory()->create(['enterprise_id' => $foreignEnterprise, 'asset_id' => $foreignAsset]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise]);
    $foreignChannel = Channel::factory()->create(['enterprise_id' => $foreignEnterprise]);
    $socialAccount = SocialAccount::factory()->create(['enterprise_id' => $enterprise, 'channel_id' => $channel]);
    $foreignSocialAccount = SocialAccount::factory()->create(['enterprise_id' => $foreignEnterprise, 'channel_id' => $foreignChannel]);

    $this->actingAs($user);

    expect(AssetResource::getEloquentQuery()->pluck('id')->all())
        ->toContain($asset->id)
        ->not->toContain($foreignAsset->id)
        ->and(GenerationRequestResource::getEloquentQuery()->pluck('id')->all())->toContain($generationRequest->id)->not->toContain($foreignGenerationRequest->id)
        ->and(RenderRequestResource::getEloquentQuery()->pluck('id')->all())->toContain($renderRequest->id)->not->toContain($foreignRenderRequest->id)
        ->and(SocialAccountResource::getEloquentQuery()->pluck('id')->all())->toContain($socialAccount->id)->not->toContain($foreignSocialAccount->id)
        ->and(AssetVersionResource::canViewAny())->toBeTrue()
        ->and(GenerationRequestResource::canViewAny())->toBeTrue()
        ->and(RenderRequestResource::canViewAny())->toBeTrue()
        ->and(RenderOutputResource::canViewAny())->toBeTrue()
        ->and(GenerationJobResource::canViewAny())->toBeTrue()
        ->and(RenderJobResource::canViewAny())->toBeTrue()
        ->and(TransformationResource::canViewAny())->toBeTrue()
        ->and(SocialAccountResource::canViewAny())->toBeTrue()
        ->and(PublicationResource::canViewAny())->toBeTrue()
        ->and(PublicationScheduleResource::canViewAny())->toBeTrue()
        ->and(PublishingJobResource::canViewAny())->toBeTrue()
        ->and(PublicationResultResource::canViewAny())->toBeTrue()
        ->and(IntegrationConnectionResource::canViewAny())->toBeTrue();
});

it('limits mutable phase 5 administration to enterprise managers', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    Enterprise::factory()->create(['organization_id' => $organization]);

    $this->actingAs($owner);

    expect(AssetResource::canCreate())->toBeTrue()
        ->and(SocialAccountResource::canCreate())->toBeTrue()
        ->and(PublicationResource::canCreate())->toBeTrue()
        ->and(PublicationScheduleResource::canCreate())->toBeTrue()
        ->and(IntegrationConnectionResource::canCreate())->toBeTrue();

    $this->actingAs($member);

    expect(AssetResource::canCreate())->toBeFalse()
        ->and(SocialAccountResource::canCreate())->toBeFalse()
        ->and(PublicationResource::canCreate())->toBeFalse()
        ->and(PublicationScheduleResource::canCreate())->toBeFalse()
        ->and(IntegrationConnectionResource::canCreate())->toBeFalse();
});

it('keeps historical and external execution resources read-only', function () {
    expect(AssetVersionResource::getPages())->not->toHaveKey('edit')
        ->and(GenerationRequestResource::getPages())->not->toHaveKey('edit')
        ->and(GenerationRequestResource::canCreate())->toBeFalse()
        ->and(RenderRequestResource::getPages())->not->toHaveKey('edit')
        ->and(GenerationJobResource::getPages())->not->toHaveKey('edit')
        ->and(RenderJobResource::getPages())->not->toHaveKey('edit')
        ->and(RenderOutputResource::getPages())->not->toHaveKey('edit')
        ->and(TransformationResource::getPages())->not->toHaveKey('edit')
        ->and(PublishingJobResource::getPages())->not->toHaveKey('edit')
        ->and(PublicationResultResource::getPages())->not->toHaveKey('edit');
});

it('uses existing policies for mutable phase 5 records', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $user, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $asset = Asset::factory()->create(['enterprise_id' => $enterprise]);

    expect(Gate::forUser($user)->allows('view', $asset))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $asset))->toBeTrue()
        ->and(Gate::forUser($user)->allows('delete', $asset))->toBeTrue();
});