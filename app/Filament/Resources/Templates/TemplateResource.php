<?php

namespace App\Filament\Resources\Templates;

use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Templates\Pages\ListTemplates;
use App\Models\Template;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class TemplateResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Template::class;

    protected static ?string $modelLabel = 'Template';

    protected static ?string $pluralModelLabel = 'Templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Media Production & Publishing';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('key')->searchable()->sortable(),
            TextColumn::make('project')->searchable()->sortable()->placeholder('Shared'),
            TextColumn::make('output_format')->badge()->searchable()->sortable(),
            TextColumn::make('ownership_scope')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('publishedVersion.version')->label('Published version')->placeholder('Not published'),
            TextColumn::make('versions_count')->counts('versions')->label('Versions')->sortable(),
        ])->filters([
            SelectFilter::make('output_format')->options(['image' => 'Still image', 'video' => 'Motion video']),
            SelectFilter::make('status')->options([
                Template::STATUS_DRAFT => 'Draft',
                Template::STATUS_PUBLISHED => 'Published',
                Template::STATUS_ARCHIVED => 'Archived',
            ]),
            SelectFilter::make('ownership_scope')->options([
                Template::SCOPE_PLATFORM => 'Platform',
                Template::SCOPE_ORGANIZATION => 'Organization',
                Template::SCOPE_ENTERPRISE => 'Enterprise',
                Template::SCOPE_USER => 'User',
            ]),
        ])->defaultSort('name')->recordActions([
            ViewAction::make()->schema([
                TextInput::make('key'),
                TextInput::make('name'),
                Textarea::make('description'),
                TextInput::make('purpose'),
                TextInput::make('project'),
                TextInput::make('output_format'),
                TextInput::make('renderer_targets')->formatStateUsing(fn ($state): string => implode(', ', is_array($state) ? $state : [])),
                TextInput::make('ownership_scope'),
                TextInput::make('status'),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        if ($user === null || ! $user->memberships()->exists()) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($user): void {
                $query->where('ownership_scope', Template::SCOPE_PLATFORM)
                    ->orWhere(function (Builder $owned): void {
                        $owned->where('ownership_scope', Template::SCOPE_ORGANIZATION)
                            ->whereIn('organization_id', static::authorizedOrganizationIds());
                    })
                    ->orWhere(function (Builder $owned): void {
                        $owned->where('ownership_scope', Template::SCOPE_ENTERPRISE)
                            ->whereIn('enterprise_id', static::authorizedEnterpriseIds());
                    })
                    ->orWhere(function (Builder $owned) use ($user): void {
                        $owned->where('ownership_scope', Template::SCOPE_USER)->where('user_id', $user->getKey());
                    });
            })->with('publishedVersion')->withCount('versions');
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function canCreate(): bool
    {
        return auth()->check() && Gate::allows('create', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListTemplates::route('/')];
    }
}