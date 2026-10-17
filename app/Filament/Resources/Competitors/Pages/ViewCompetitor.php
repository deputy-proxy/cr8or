<?php

namespace App\Filament\Resources\Competitors\Pages;

use App\Filament\Resources\Competitors\CompetitorResource;
use App\Models\Competitor;
use App\Services\StrategicContextService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

class ViewCompetitor extends Page
{
    protected static string $resource = CompetitorResource::class;

    protected string $view = 'filament.resources.competitors.pages.view-competitor';

    public Competitor $competitor;

    public function mount(int|string $record): void
    {
        $competitor = CompetitorResource::getEloquentQuery()->whereKey($record)->firstOrFail();
        abort_unless($competitor instanceof Competitor, 404);
        $this->competitor = $competitor;
        Gate::authorize('view', $this->competitor);
    }

    public function getTitle(): string
    {
        return $this->competitor->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revise')
                ->label('Revise')
                ->visible(fn (): bool => Gate::allows('update', $this->competitor))
                ->url(fn (): string => CompetitorResource::getUrl('edit', ['record' => $this->competitor])),
            Action::make('archive')
                ->label('Archive')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => Gate::allows('archive', $this->competitor))
                ->action(function (): void {
                    app(StrategicContextService::class)->archiveCompetitor(auth()->user(), $this->competitor);
                    $this->competitor->refresh();
                }),
        ];
    }

    /** @return list<Competitor> */
    public function getHistory(): array
    {
        $history = [$this->competitor];
        $seen = [$this->competitor->getKey() => true];
        $predecessor = $this->competitor->supersedes;

        while ($predecessor instanceof Competitor
            && (int) $predecessor->enterprise_id === (int) $this->competitor->enterprise_id
            && $predecessor->version < $this->competitor->version
            && ! isset($seen[$predecessor->getKey()])) {
            $seen[$predecessor->getKey()] = true;
            $history[] = $predecessor;
            $predecessor = $predecessor->supersedes;
        }

        $successor = $this->competitor->supersededBy()
            ->where('enterprise_id', $this->competitor->enterprise_id)
            ->where('version', '>', $this->competitor->version)
            ->orderBy('version')
            ->first();

        while ($successor instanceof Competitor
            && (int) $successor->enterprise_id === (int) $this->competitor->enterprise_id
            && ! isset($seen[$successor->getKey()])) {
            $seen[$successor->getKey()] = true;
            $history[] = $successor;
            $successor = $successor->supersededBy()
                ->where('enterprise_id', $this->competitor->enterprise_id)
                ->where('version', '>', $successor->version)
                ->orderBy('version')
                ->first();
        }

        return array_values(collect($history)->sortBy('version')->all());
    }
}
