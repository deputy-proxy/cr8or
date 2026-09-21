<?php
namespace App\Filament\Resources\EnterpriseDecisions\Pages;
use App\Filament\Resources\EnterpriseDecisions\EnterpriseDecisionResource;
use App\Models\EnterpriseDecision;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
class EditEnterpriseDecision extends EditRecord {
 protected static string $resource=EnterpriseDecisionResource::class;
 protected function mutateFormDataBeforeSave(array $data):array{
  /** @var EnterpriseDecision $record */ $record=$this->record;
  $data['enterprise_id']=$record->enterprise_id; $data['actor_id']=$record->actor_id; $data['actor_name']=$record->actor_name; $data['decided_at']=$record->decided_at;
  return $data;
 }
 protected function getHeaderActions():array{return [DeleteAction::make()];}
}