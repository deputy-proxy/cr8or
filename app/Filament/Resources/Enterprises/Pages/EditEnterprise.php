<?php
namespace App\Filament\Resources\Enterprises\Pages;
use App\Filament\Resources\Enterprises\EnterpriseResource;
use App\Models\Enterprise;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
class EditEnterprise extends EditRecord {
 protected static string $resource=EnterpriseResource::class;
 protected function mutateFormDataBeforeSave(array $data):array{
  /** @var Enterprise $record */ $record=$this->record;
  if((int)$data['${parent}_id']!==$record->${parent}_id){throw new AuthorizationException('Cannot reassign this record.');}
  return $data;
 }
 protected function getHeaderActions():array{return [DeleteAction::make()];}
}