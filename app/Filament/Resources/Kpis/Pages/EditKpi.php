<?php
namespace App\Filament\Resources\Kpis\Pages;
use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Kpi;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
class EditKpi extends EditRecord {
 protected static string $resource=KpiResource::class;
 protected function mutateFormDataBeforeSave(array $data):array{
  /** @var Kpi $record */ $record=$this->record;
  if((int)$data['${parent}_id']!==$record->${parent}_id){throw new AuthorizationException('Cannot reassign this record.');}
  return $data;
 }
 protected function getHeaderActions():array{return [DeleteAction::make()];}
}