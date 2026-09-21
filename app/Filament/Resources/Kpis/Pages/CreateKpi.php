<?php
namespace App\Filament\Resources\Kpis\Pages;
use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Kpi;
use App\Models\Enterprise;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Gate;
class CreateKpi extends CreateRecord {
 protected static string $resource=KpiResource::class;
 protected function mutateFormDataBeforeCreate(array $data):array{
  $parent=Enterprise::query()->findOrFail((int)$data['enterprise_id']);
  Gate::authorize('create',[Kpi::class,$parent]);
  
  return $data;
 }
}