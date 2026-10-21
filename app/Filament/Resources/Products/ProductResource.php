<?php

namespace App\Filament\Resources\Products;

use App\Enums\ProductBillingInterval;
use App\Enums\ProductDeliveryModel;
use App\Enums\ProductMonetizationModel;
use App\Enums\ProductPricingModel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Filament\Resources\Concerns\ScopesAuthorizedRecords;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use BackedEnum;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    use ScopesAuthorizedRecords;

    protected static ?string $model = Product::class;

    protected static ?string $modelLabel = 'Product';

    protected static ?string $pluralModelLabel = 'Products';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Enterprises & Products';

    protected static ?string $navigationLabel = 'Products';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity & lifecycle')->columns(2)->schema([
                Select::make('enterprise_id')
                    ->relationship('enterprise', 'name', modifyQueryUsing: fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()))
                    ->searchable()->preload()->required()->disabledOn('edit')->dehydrated(),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('slug')->maxLength(255)->rules(['nullable', 'alpha_dash'])->helperText('Unique within this Enterprise. Generated from the name when omitted.'),
                Select::make('product_type')->options(self::enumOptions(ProductType::cases()))->default(ProductType::OTHER->value)->required()->native(false),
                Select::make('status')->options(self::enumOptions(ProductStatus::cases()))->default(ProductStatus::ACTIVE->value)->required()->native(false),
            ]),
            Section::make('Positioning & value')->columns(2)->schema([
                TextInput::make('tagline')->maxLength(255),
                Textarea::make('summary')->rows(2)->maxLength(1000)->columnSpanFull(),
                Textarea::make('description')->rows(5)->maxLength(50000)->columnSpanFull(),
                Textarea::make('problem_statement')->rows(3)->maxLength(10000),
                Textarea::make('value_proposition')->rows(3)->maxLength(10000),
                Textarea::make('primary_outcome')->rows(2)->maxLength(5000),
                Textarea::make('positioning')->rows(3)->maxLength(10000),
                self::listField('differentiators', 'Differentiators'),
            ]),
            Section::make('Definition & delivery')->columns(2)->schema([
                Select::make('delivery_model')->options(self::enumOptions(ProductDeliveryModel::cases()))->default(ProductDeliveryModel::OTHER->value)->required()->native(false),
                TextInput::make('fulfillment_method')->maxLength(255)->helperText('Use only when the concrete fulfillment method adds detail beyond the delivery model.'),
                Textarea::make('support_model')->rows(2)->maxLength(5000),
                self::listField('features', 'Features'),
                self::listField('benefits', 'Benefits'),
                self::listField('deliverables', 'Deliverables'),
                self::listField('requirements', 'Requirements'),
                self::listField('limitations', 'Limitations'),
            ]),
            Section::make('Commercial configuration')
                ->description('One inline commercial configuration per Product. Lifecycle status and commercial availability are separate decisions.')
                ->columns(2)
                ->schema([
                    Select::make('monetization_model')->options(self::enumOptions(ProductMonetizationModel::cases()))->default(ProductMonetizationModel::OTHER->value)->required()->native(false)->live(),
                    Select::make('pricing_model')->options(self::enumOptions(ProductPricingModel::cases()))->default(ProductPricingModel::OTHER->value)->required()->native(false)->live(),
                    TextInput::make('price_amount')->numeric()->minValue(0)->maxValue(9999999999.99)->step(0.01)
                        ->rules(['nullable', 'decimal:0,2', function (Get $get): Closure {
                            return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($value !== null && is_numeric($value) && (float) $value > 0
                                    && ($get('pricing_model') === ProductPricingModel::FREE->value || $get('monetization_model') === ProductMonetizationModel::FREE->value)) {
                                    $fail('A free product cannot have a positive price.');
                                }
                            };
                        }]),
                    TextInput::make('currency')->maxLength(3)->minLength(3)->required(fn (Get $get): bool => filled($get('price_amount')))
                        ->rules(['nullable', function (): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail): void {
                                if ($value !== null && $value !== '' && (! is_string($value) || ! Product::isValidCurrencyCode($value))) {
                                    $fail('Enter a valid ISO 4217 currency code.');
                                }
                            };
                        }])
                        ->helperText('Three-letter ISO 4217 code, for example USD or EUR.'),
                    Select::make('billing_interval')->options(self::enumOptions(ProductBillingInterval::cases()))
                        ->required(fn (Get $get): bool => $get('pricing_model') === ProductPricingModel::SUBSCRIPTION->value || $get('monetization_model') === ProductMonetizationModel::SUBSCRIPTION->value)
                        ->helperText('Required for recurring subscriptions; one_time is for non-recurring billing.'),
                    Toggle::make('is_commercially_available')->default(false)->helperText('Defaults to false until availability is explicitly confirmed.'),
                    DateTimePicker::make('availability_start_at'),
                    DateTimePicker::make('availability_end_at')->rules([function (Get $get): Closure {
                        return function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $start = $get('availability_start_at');
                            if (filled($start) && filled($value)
                                && \Illuminate\Support\Carbon::parse((string) $value)->lt(\Illuminate\Support\Carbon::parse((string) $start))) {
                                $fail('Availability end cannot precede availability start.');
                            }
                        };
                    }]),
                    Textarea::make('commercial_terms')->rows(3)->maxLength(10000)->columnSpanFull(),
                    Textarea::make('commercial_notes')->rows(3)->maxLength(10000)->columnSpanFull(),
                ]),
            Section::make('Governance & disclosures')->columns(2)->schema([
                self::listField('required_disclosures', 'Required disclosures'),
                self::listField('restrictions', 'Restrictions'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('enterprise.name')->label('Enterprise')->searchable()->sortable(),
            TextColumn::make('product_type')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('pricing_model')->label('Pricing')->badge(),
            TextColumn::make('price_amount')->label('Price')->placeholder('Unspecified')->sortable(),
            TextColumn::make('currency')->placeholder('—'),
            TextColumn::make('is_commercially_available')->label('Commercially available')->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No')->badge(),
        ])->filters([
            SelectFilter::make('product_type')
                ->options(self::enumOptions(ProductType::cases())),
            SelectFilter::make('status')
                ->options(self::enumOptions(ProductStatus::cases())),
            SelectFilter::make('pricing')
                ->label('Pricing')
                ->options([
                    'free' => 'Free',
                    'paid' => 'Paid / monetized',
                    'unconfigured' => 'No pricing configured',
                ])
                ->query(static function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'free' => $query->where(function (Builder $query): void {
                            $query->where('pricing_model', ProductPricingModel::FREE->value)
                                ->orWhere('monetization_model', ProductMonetizationModel::FREE->value);
                        }),
                        'paid' => $query->where(function (Builder $query): void {
                            $query->whereIn('pricing_model', [
                                ProductPricingModel::FIXED->value,
                                ProductPricingModel::USAGE_BASED->value,
                                ProductPricingModel::SUBSCRIPTION->value,
                                ProductPricingModel::QUOTE_BASED->value,
                            ])->orWhereIn('monetization_model', [
                                ProductMonetizationModel::ONE_TIME_PURCHASE->value,
                                ProductMonetizationModel::SUBSCRIPTION->value,
                                ProductMonetizationModel::SERVICE_FEE->value,
                                ProductMonetizationModel::AFFILIATE_SUPPORTED->value,
                                ProductMonetizationModel::HYBRID->value,
                                ProductMonetizationModel::QUOTE_BASED->value,
                            ])->orWhere('price_amount', '>', 0);
                        })->where('pricing_model', '!=', ProductPricingModel::FREE->value)
                            ->where('monetization_model', '!=', ProductMonetizationModel::FREE->value),
                        'unconfigured' => $query->whereNull('price_amount')
                            ->where('pricing_model', ProductPricingModel::OTHER->value)
                            ->where('monetization_model', ProductMonetizationModel::OTHER->value),
                        default => $query,
                    };
                }),
        ])->groups([
            Group::make('enterprise_id')
                ->label('Enterprise')
                ->getKeyFromRecordUsing(fn (Product $record): int|string => $record->enterprise_id)
                ->getTitleFromRecordUsing(fn (Product $record): string => $record->enterprise->name)
                ->titlePrefixedWithLabel(false)
                ->collapsible(),
        ])->defaultGroup('enterprise_id')->recordActions([
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas('enterprise', fn (Builder $query) => $query->whereIn('organization_id', static::authorizedOrganizationIds()));
    }

    public static function canViewAny(): bool
    {
        return auth()->check() && Gate::allows('viewAny', static::getModel());
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/'), 'create' => CreateProduct::route('/create'), 'edit' => EditProduct::route('/{record}/edit')];
    }

    /** @param list<BackedEnum> $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = Str::headline((string) $case->value);
        }

        return $options;
    }

    private static function listField(string $name, string $label): TagsInput
    {
        return TagsInput::make($name)
            ->label($label)
            ->rules([
                'nullable', 'array', 'list', 'max:50',
                static function (): Closure {
                    return static function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value === null) {
                            return;
                        }

                        if (! is_array($value) || ! array_is_list($value)) {
                            $fail('This field must be a list of strings.');

                            return;
                        }

                        foreach ($value as $item) {
                            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 500) {
                                $fail('Each item must be a non-empty string of at most 500 characters.');

                                return;
                            }
                        }
                    };
                },
            ]);
    }
}
