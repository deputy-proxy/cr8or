<?php

namespace App\Filament\Resources\ExpertDescriptors;

use App\Experts\Expert;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\ExpertDescriptors\Pages\EditExpertDescriptor;
use App\Filament\Resources\ExpertDescriptors\Pages\ListExpertDescriptors;
use App\Models\ExpertDescriptor;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use ReflectionClass;

class ExpertDescriptorResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = ExpertDescriptor::class;

    protected static ?string $modelLabel = 'Expert';

    protected static ?string $pluralModelLabel = 'Experts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|\UnitEnum|null $navigationGroup = 'Agent Configuration';

    protected static ?string $navigationLabel = 'Experts';

    protected static ?int $navigationSort = 20;

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
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('create', static::getModel());
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
