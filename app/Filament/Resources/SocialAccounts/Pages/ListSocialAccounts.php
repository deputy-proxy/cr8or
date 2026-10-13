<?php

namespace App\Filament\Resources\SocialAccounts\Pages;

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\Social\SocialAuthorizationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class ListSocialAccounts extends ListRecords
{
    protected static string $resource = SocialAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connectSocialAccount')
                ->label('Connect social account')
                ->icon('heroicon-o-plus')
                ->modalHeading('Connect a social account')
                ->modalDescription('Authorize a social network, then choose the destinations to attach to your organization.')
                ->form([
                    Select::make('network')
                        ->label('Social network')
                        ->options([
                            'facebook' => 'Facebook Pages',
                            'instagram' => 'Instagram',
                            'linkedin' => 'LinkedIn',
                            'tiktok' => 'TikTok',
                            'bsky' => 'Bluesky',
                            'youtube' => 'YouTube',
                            'threads' => 'Threads',
                            'x' => 'X',
                        ])
                        ->required()
                        ->default('facebook'),
                    Select::make('organization_id')
                        ->label('Organization')
                        ->options(fn (): array => Organization::query()
                            ->whereIn('id', Membership::query()->select('organization_id')->where('user_id', auth()->id()))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->required()
                        ->searchable(),
                    Select::make('enterprise_id')
                        ->label('Enterprise (optional)')
                        ->options(fn (callable $get): array => Enterprise::query()
                            ->where('organization_id', $get('organization_id'))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (callable $get): bool => filled($get('organization_id')))
                        ->live()
                        ->searchable(),
                    Select::make('channel_id')
                        ->label('Channel (optional)')
                        ->options(fn (callable $get): array => Channel::query()
                            ->whereHas('enterprise', fn ($query) => $query->where('organization_id', $get('organization_id'))
                                ->when(filled($get('enterprise_id')), fn ($query) => $query->whereKey($get('enterprise_id'))))
                            ->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (callable $get): bool => filled($get('organization_id')))
                        ->searchable(),
                ])
                ->action(function (array $data, SocialAuthorizationService $authorization): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User) {
                        throw new AuthorizationException;
                    }

                    $organizationId = (int) $data['organization_id'];
                    Gate::forUser($actor)->authorize('update', Organization::query()->findOrFail($organizationId));

                    $enterpriseId = filled($data['enterprise_id'] ?? null) ? (int) $data['enterprise_id'] : null;
                    $channelId = filled($data['channel_id'] ?? null) ? (int) $data['channel_id'] : null;
                    $url = $authorization->start(
                        $actor,
                        $organizationId,
                        (string) $data['network'],
                        $enterpriseId,
                        $channelId,
                    );

                    $this->redirect($url, navigate: false);
                }),
        ];
    }
}
