<?php

namespace App\Filament\Resources\Competitors\Pages;

use App\Filament\Resources\Competitors\CompetitorResource;
use App\Models\Competitor;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompetitors extends ListRecords
{
    protected static string $resource = CompetitorResource::class;

    public function mount(): void
    {
        parent::mount();

        if (blank($this->tableFilters)) {
            $this->tableFilters = ['status' => ['value' => Competitor::STATUS_ACTIVE]];
        }
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}