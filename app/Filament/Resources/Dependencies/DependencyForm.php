<?php

namespace App\Filament\Resources\Dependencies;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Services\DependencyService;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DependencyForm
{
    use ScopesAuthorizedRecords;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('enterprise_id')
                ->relationship('enterprise', 'name')
                ->searchable()
                ->preload()
                ->required(),
            Select::make('project_id')
                ->relationship('project', 'name')
                ->searchable()
                ->preload(),
            Select::make('predecessor_type')
                ->label('Predecessor type')
                ->options(self::workRecordTypes())
                ->live()
                ->required(),
            Select::make('predecessor_id')
                ->label('Predecessor')
                ->options(fn (Get $get): array => self::workRecordOptions($get('predecessor_type')))
                ->searchable()
                ->preload()
                ->disabled(fn (Get $get): bool => blank($get('predecessor_type')))
                ->required(),
            Select::make('successor_type')
                ->label('Successor type')
                ->options(self::workRecordTypes())
                ->live()
                ->required(),
            Select::make('successor_id')
                ->label('Successor')
                ->options(fn (Get $get): array => self::workRecordOptions($get('successor_type')))
                ->searchable()
                ->preload()
                ->disabled(fn (Get $get): bool => blank($get('successor_type')))
                ->required(),
            Select::make('type')
                ->options(['blocks' => 'Blocks'])
                ->default('blocks')
                ->required(),
        ]);
    }

    /** @return array<string, string> */
    private static function workRecordTypes(): array
    {
        return collect(DependencyService::ENDPOINT_TYPES)
            ->mapWithKeys(fn (string $class): array => [$class => class_basename($class)])
            ->all();
    }

    /** @return array<int, string> */
    private static function workRecordOptions(?string $type): array
    {
        if ($type === null || $type === '') {
            return [];
        }

        $model = DependencyService::endpointClass($type);

        return $model::query()
            ->whereIn('enterprise_id', self::manageableEnterpriseIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
