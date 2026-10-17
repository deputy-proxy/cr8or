<?php

namespace App\Filament\Resources\Audiences;

use App\Filament\Resources\Audiences\Pages\CreateAudience;
use App\Filament\Resources\Audiences\Pages\EditAudience;
use App\Filament\Resources\Audiences\Pages\ListAudiences;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Models\Audience;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AudienceResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Audience::class;

    protected static ?string $modelLabel = 'Persona';

    protected static ?string $pluralModelLabel = 'Personas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprises & Products';

    protected static ?string $navigationLabel = 'Personas';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->schema([
                Select::make('enterprise_id')->relationship('enterprise', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()))->searchable()->preload()->required()->live()->disabledOn('edit')->dehydrated(),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->label('Summary')->rows(3)->maxLength(10000),
                Select::make('persona_type')->options(['buyer' => 'Buyer', 'user' => 'User', 'influencer' => 'Influencer', 'decision_maker' => 'Decision maker', 'gatekeeper' => 'Gatekeeper'])->native(false),
                Select::make('status')->options(['active' => 'Active', 'archived' => 'Archived'])->default('active')->required(),
                TextInput::make('research_confidence')->numeric()->minValue(0)->maxValue(1)->step(0.01)->helperText('Confidence in this archetype, from 0 to 1.'),
                DatePicker::make('last_reviewed_at')->label('Last reviewed'),
            ])->columns(2),
            Section::make('Demographics')->description('Describe the archetype, not an identifiable individual.')->schema([
                TextInput::make('age_range')->maxLength(100)->helperText('For example, 25–34; avoid an exact birth date.'),
                TextInput::make('location')->maxLength(255),
                TextInput::make('job_title')->maxLength(255),
                TextInput::make('income_range')->maxLength(100),
                TextInput::make('education_level')->maxLength(100),
                TextInput::make('family_status')->maxLength(100),
            ])->columns(2),
            Section::make('Psychographics')->schema([
                TagsInput::make('psychographics.values')->label('Values and beliefs'),
                TagsInput::make('psychographics.interests')->label('Interests'),
                TagsInput::make('psychographics.lifestyle')->label('Lifestyle and habits'),
                TagsInput::make('psychographics.attitudes')->label('Attitudes and preferences'),
                TagsInput::make('psychographics.aspirations')->label('Aspirations'),
            ])->columns(2),
            Section::make('Goals and motivations')->schema([
                TagsInput::make('goals_and_motivations.goals')->label('Goals and desired outcomes'),
                TagsInput::make('goals_and_motivations.purchase_motivations')->label('Purchase drivers'),
                TagsInput::make('goals_and_motivations.success_criteria')->label('Success criteria'),
                TagsInput::make('goals_and_motivations.practical_motivations')->label('Practical motivations'),
                TagsInput::make('goals_and_motivations.emotional_motivations')->label('Emotional motivations'),
            ])->columns(2),
            Section::make('Pain points and challenges')->schema([
                TagsInput::make('pain_points.problems')->label('Problems and unmet needs'),
                TagsInput::make('pain_points.obstacles')->label('Obstacles and frustrations'),
                TagsInput::make('pain_points.current_alternatives')->label('Current alternatives and shortcomings'),
                TagsInput::make('pain_points.consequences_of_inaction')->label('Consequences of inaction'),
                TagsInput::make('pain_points.desired_improvements')->label('Desired improvements'),
            ])->columns(2),
            Section::make('Buying behavior')->schema([
                TagsInput::make('buying_behavior.preferred_channels')->label('Preferred discovery channels'),
                TagsInput::make('buying_behavior.research_habits')->label('Research and evaluation habits'),
                TagsInput::make('buying_behavior.decision_criteria')->label('Decision criteria'),
                TagsInput::make('buying_behavior.purchase_triggers')->label('Purchase triggers'),
                TagsInput::make('buying_behavior.objections')->label('Objections and barriers'),
                TextInput::make('buying_behavior.budget_sensitivity')->label('Budget sensitivity')->maxLength(500),
                TextInput::make('buying_behavior.purchase_frequency')->label('Purchase frequency')->maxLength(100),
                TagsInput::make('buying_behavior.preferred_content_formats')->label('Preferred content formats'),
            ])->columns(2),
            Section::make('Relevant products')->description('Only products owned by the same enterprise can be associated. This relationship describes persona relevance, not campaign targeting.')->schema([
                Select::make('product_ids')->label('Relevant products')->multiple()->options(fn (Get $get): array => Product::query()->where('enterprise_id', (int) $get('enterprise_id'))->whereHas('enterprise', fn (Builder $enterpriseQuery) => $enterpriseQuery->whereIn('organization_id', static::authorizedOrganizationIds()))->orderBy('name')->pluck('name', 'id')->all())->searchable()->preload()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->searchable()->sortable(), TextColumn::make('persona_type')->badge()->placeholder('General'), TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(), TextColumn::make('products.name')->label('Products')->listWithLineBreaks()->limitList(3), TextColumn::make('status')->badge()->sortable()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $q) => $q->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && \Illuminate\Support\Facades\Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListAudiences::route('/'), 'create' => CreateAudience::route('/create'), 'edit' => EditAudience::route('/{record}/edit')];
    }
}