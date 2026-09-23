<?php

namespace App\Filament\Resources\Decisions\Pages;

use App\Filament\Resources\Decisions\DecisionResource;
use App\Models\Decision;
use Filament\Resources\Pages\EditRecord;

class EditDecision extends EditRecord
{
    protected static string $resource = DecisionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Decision $record */
        $record = $this->record;

        $data['enterprise_id'] = $record->enterprise_id;
        $data['type'] = $record->type;
        $data['actor_id'] = $record->actor_id;
        $data['actor_name'] = $record->actor_name;
        $data['objective_id'] = $record->objective_id;
        $data['strategy_id'] = $record->strategy_id;
        $data['plan_id'] = $record->plan_id;
        $data['initiative_id'] = $record->initiative_id;
        $data['project_id'] = $record->project_id;
        $data['task_id'] = $record->task_id;
        $data['work_item_id'] = $record->work_item_id;
        $data['decided_at'] = $record->decided_at;

        return $data;
    }
}
