<?php

namespace App\Filament\Resources\AgentDescriptors;

use App\Agents\Agent;
use App\Filament\Resources\AgentDescriptors\Pages\EditAgentDescriptor;
use App\Filament\Resources\AgentDescriptors\Pages\ListAgentDescriptors;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\AgentDescriptor;
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

class AgentDescriptorResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = AgentDescriptor::class;

    protected static ?string $modelLabel = 'Agent';

    protected static ?string $pluralModelLabel = 'Agents';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'Agentic Flow';

    protected static ?string $navigationLabel = 'Agents';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('slug')->required()->maxLength(255),
            TextInput::make('runtime_class')->disabled()->dehydrated(false),
            Toggle::make('enabled')->required(),
            Placeholder::make('runtime_name')->label('Runtime name')->content(fn (?AgentDescriptor $record): string => self::runtimeValue($record, 'name')),
            Placeholder::make('runtime_description')->label('Runtime description')->content(fn (?AgentDescriptor $record): string => self::runtimeValue($record, 'description')),
            Placeholder::make('runtime_instructions')->label('Runtime instructions')->content(fn (?AgentDescriptor $record): string => self::runtimeValue($record, 'instructions')),
            Placeholder::make('runtime_responsibilities')->label('Responsibilities')->content(fn (?AgentDescriptor $record): string => self::runtimeList($record, 'responsibilities')),
            Placeholder::make('runtime_experts')->label('Experts')->content(fn (?AgentDescriptor $record): string => self::runtimeList($record, 'experts')),
            Placeholder::make('runtime_capabilities')->label('Capabilities')->content(fn (?AgentDescriptor $record): string => self::runtimeList($record, 'capabilities')),
            Placeholder::make('runtime_required_context')->label('Required context')->content(fn (?AgentDescriptor $record): string => self::runtimeList($record, 'requiredContext')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('slug')->searchable()->sortable(),
            TextColumn::make('runtime_class')->label('Runtime class')->searchable(),
            IconColumn::make('enabled')->boolean(),
            TextColumn::make('created_at')->dateTime()->sortable(),
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

    private static function runtimeValue(?AgentDescriptor $record, string $method): string
    {
        $runtime = self::runtime($record);

        return $runtime instanceof Agent ? $runtime->{$method}() : 'Runtime class is not instantiable.';
    }

    private static function runtimeList(?AgentDescriptor $record, string $method): string
    {
        $runtime = self::runtime($record);

        return $runtime instanceof Agent ? implode(', ', $runtime->{$method}()) : 'Runtime class is not instantiable.';
    }

    private static function runtime(?AgentDescriptor $record): ?Agent
    {
        if ($record === null || ! class_exists($record->runtime_class)) {
            return null;
        }

        $reflection = new ReflectionClass($record->runtime_class);
        if ($reflection->isAbstract()) {
            return null;
        }

        $runtime = app($record->resolveRuntimeClass());

        return $runtime instanceof Agent ? $runtime : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentDescriptors::route('/'),
            'edit' => EditAgentDescriptor::route('/{record}/edit'),
        ];
    }
}
