<?php

namespace App\Filament\Resources\Dependencies;

use App\Filament\Resources\Concerns\ScopesPhaseOneRecords;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkItem;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DependencyForm
{
    use ScopesPhaseOneRecords;

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
                ->options(['blocks' => 'Blocks', 'relates_to' => 'Relates to'])
                ->required(),
        ]);
    }

    /** @return array<class-string, string> */
    private static function workRecordTypes(): array
    {
        return [
            Project::class => 'Project',
            Task::class => 'Task',
            WorkItem::class => 'Work item',
        ];
    }

    /** @return array<int, string> */
    private static function workRecordOptions(?string $type): array
    {
        $model = match ($type) {
            Project::class => Project::class,
            Task::class => Task::class,
            WorkItem::class => WorkItem::class,
            default => null,
        };

        if ($model === null) {
            return [];
        }

        return $model::query()
            ->whereIn('enterprise_id', self::manageableEnterpriseIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
