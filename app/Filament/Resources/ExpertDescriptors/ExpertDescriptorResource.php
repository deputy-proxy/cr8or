<?php

namespace AppFilamentResourcesExpertDescriptors;

use AppExpertsExpert;
use AppFilamentResourcesConcernsScopesPhaseOneRecords;
use AppFilamentResourcesExpertDescriptorsPagesEditExpertDescriptor;
use AppFilamentResourcesExpertDescriptorsPagesListExpertDescriptors;
use AppModelsExpertDescriptor;
use BackedEnum;
use FilamentFormsComponentsPlaceholder;
use FilamentFormsComponentsTextInput;
use FilamentFormsComponentsToggle;
use FilamentResourcesResource;
use FilamentSchemasSchema;
use FilamentSupportIconsHeroicon;
use FilamentTablesColumnsIconColumn;
use FilamentTablesColumnsTextColumn;
use FilamentTablesTable;
use IlluminateDatabaseEloquentBuilder;
use ReflectionClass;

class ExpertDescriptorResource extends Resource
{
    use ScopesPhaseOneRecords;

    protected static ?string $model = ExpertDescriptor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->maxLength(255),
            TextInput::make('runtime_class')->disabled()->dehydrated(false),
            Toggle::make('enabled')->required(),
            Placeholder::make('runtime_name')->label('Runtime name')->content(fn (?ExpertDescriptor $record): string => self::runtimeValue($record, 'name')),
            Placeholder::make('runtime_description')->label('Runtime description')->content(fn (?ExpertDescriptor $record): string => self::runtimeValue($record, 'description')),
            Placeholder::make('runtime_responsibilities')->label('Responsibilities')->content(fn (?ExpertDescriptor $record): string => self::runtimeList($record, 'responsibilities')),
            Placeholder::make('runtime_capabilities')->label('Capabilities')->content(fn (?ExpertDescriptor $record): string => self::runtimeList($record, 'capabilities')),
            Placeholder::make('runtime_required_context')->label('Required context')->content(fn (?ExpertDescriptor $record): string => self::runtimeList($record, 'requiredContext')),
            Placeholder::make('runtime_methodology')->label('Methodology')->content(fn (?ExpertDescriptor $record): string => self::runtimeValue($record, 'methodology')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('slug')->searchable()->sortable(),
            TextColumn::make('runtime_class')->label('Runtime class')->searchable(),
            IconColumn::make('enabled')->boolean(),
        ])->recordActions([
            \Filament\Actions\EditAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && static::canManageAnyOrganization();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListExpertDescriptors::route('/'), 'edit' => EditExpertDescriptor::route('/{record}/edit')];
    }

    private static function runtimeValue(?ExpertDescriptor $record, string $method): string
    {
        $runtime = self::runtime($record);

        return $runtime instanceof Expert ? $runtime->{$method}() : 'Runtime class is not instantiable.';
    }

    private static function runtimeList(?ExpertDescriptor $record, string $method): string
    {
        $runtime = self::runtime($record);

        return $runtime instanceof Expert ? implode(', ', $runtime->{$method}()) : 'Runtime class is not instantiable.';
    }

    private static function runtime(?ExpertDescriptor $record): ?Expert
    {
        if ($record === null || ! class_exists($record->runtime_class)) {
            return null;
        }
        $reflection = new ReflectionClass($record->runtime_class);
        if ($reflection->isAbstract()) {
            return null;
        }
        $runtime = app($record->resolveRuntimeClass());

        return $runtime instanceof Expert ? $runtime : null;
    }
}