<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Gate;

final class ViewReport extends Page
{
    protected static string $resource = ReportResource::class;

    protected string $view = 'filament.resources.reports.pages.view-report';

    public Report $report;

    public function mount(int|string $record): void
    {
        $report = ReportResource::getEloquentQuery()
            ->with('snapshot')
            ->whereKey($record)
            ->firstOrFail();

        abort_unless($report instanceof Report, 404);
        Gate::authorize('view', $report);
        $this->report = $report;
    }
}