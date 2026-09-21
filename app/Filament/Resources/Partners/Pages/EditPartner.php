<?php
namespace App\Filament\Resources\Partners\Pages;
use App\Filament\Resources\Partners\PartnerResource;
use App\Models\Partner;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
class EditPartner extends EditRecord {
 protected static string $resource=PartnerResource::class;
 protected function mutateFormDataBeforeSave(array $data):array{
  /** @var Partner $record */
  $record=$this->record;
  if((int)$data['enterprise_id']!==$record->enterprise_id){throw new AuthorizationException('Cannot reassign this record.');}
  return $data;
 }
 protected function getHeaderActions():array{return [DeleteAction::make()];}
}