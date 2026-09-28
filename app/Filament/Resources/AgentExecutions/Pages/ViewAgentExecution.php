<?php

namespace App\Filament\Resources\AgentExecutions\Pages;

use App\Filament\Resources\AgentExecutions\AgentExecutionResource;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\AgentExecutionOperationsService;
use Filament\Resources\Pages\Page;

class ViewAgentExecution extends Page
{
    protected static string $resource = AgentExecutionResource::class;

    protected string $view = 'filament.resources.agent-executions.pages.view-agent-execution';

    /** @var array<string, mixed> */
    public array $inspection = [];

    public AgentExecution $record;

    public function mount(int|string $record): void
    {
        $this->record = AgentExecution::query()->findOrFail($record);

        $user = auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $this->inspection = app(AgentExecutionOperationsService::class)->inspect($user, $this->record);
    }
}