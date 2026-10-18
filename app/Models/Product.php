<?php

namespace App\Models;

use App\Enums\ProductBillingInterval;
use App\Enums\ProductDeliveryModel;
use App\Enums\ProductMonetizationModel;
use App\Enums\ProductPricingModel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Pivots\AudienceProduct;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use LogicException;
use ResourceBundle;

/**
 * @property int $id
 * @property int $enterprise_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $product_type
 * @property string|null $tagline
 * @property string|null $summary
 * @property string|null $description
 * @property string|null $problem_statement
 * @property string|null $value_proposition
 * @property string|null $primary_outcome
 * @property string|null $positioning
 * @property list<string>|null $differentiators
 * @property list<string>|null $features
 * @property list<string>|null $benefits
 * @property list<string>|null $deliverables
 * @property list<string>|null $requirements
 * @property list<string>|null $limitations
 * @property string $delivery_model
 * @property string|null $fulfillment_method
 * @property string|null $support_model
 * @property string $monetization_model
 * @property string $pricing_model
 * @property numeric-string|null $price_amount
 * @property string|null $currency
 * @property string|null $billing_interval
 * @property string|null $commercial_terms
 * @property string|null $commercial_notes
 * @property bool $is_commercially_available
 * @property \Illuminate\Support\Carbon|null $availability_start_at
 * @property \Illuminate\Support\Carbon|null $availability_end_at
 * @property list<string>|null $required_disclosures
 * @property list<string>|null $restrictions
 */
#[Fillable([
    'enterprise_id', 'name', 'slug', 'status', 'product_type', 'tagline', 'summary', 'description',
    'problem_statement', 'value_proposition', 'primary_outcome', 'positioning', 'differentiators',
    'features', 'benefits', 'deliverables', 'requirements', 'limitations', 'delivery_model',
    'fulfillment_method', 'support_model', 'monetization_model', 'pricing_model', 'price_amount',
    'currency', 'billing_interval', 'commercial_terms', 'commercial_notes', 'is_commercially_available',
    'availability_start_at', 'availability_end_at', 'required_disclosures', 'restrictions',
])]
class Product extends Model
{
    public const STATUS_DRAFT = ProductStatus::DRAFT->value;

    public const STATUS_ACTIVE = ProductStatus::ACTIVE->value;

    public const STATUS_ARCHIVED = ProductStatus::ARCHIVED->value;

    public const STATUS_DISCONTINUED = ProductStatus::DISCONTINUED->value;

    /** @var list<string> */
    public const STRUCTURED_LIST_FIELDS = [
        'differentiators', 'features', 'benefits', 'deliverables', 'requirements', 'limitations',
        'required_disclosures', 'restrictions',
    ];

    /** @var list<string>|null */
    private static ?array $validCurrencyCodes = null;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'differentiators' => 'array',
            'features' => 'array',
            'benefits' => 'array',
            'deliverables' => 'array',
            'requirements' => 'array',
            'limitations' => 'array',
            'required_disclosures' => 'array',
            'restrictions' => 'array',
            'price_amount' => 'decimal:2',
            'is_commercially_available' => 'boolean',
            'availability_start_at' => 'datetime',
            'availability_end_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            $product->status ??= self::STATUS_ACTIVE;
            $product->product_type ??= ProductType::OTHER->value;
            $product->delivery_model ??= ProductDeliveryModel::OTHER->value;
            $product->monetization_model ??= ProductMonetizationModel::OTHER->value;
            $product->pricing_model ??= ProductPricingModel::OTHER->value;
            $product->is_commercially_available ??= false;
        });

        static::saving(function (Product $product): void {
            if (! in_array($product->status, self::enumValues(ProductStatus::cases()), true)) {
                throw new LogicException("Invalid product status [{$product->status}].");
            }

            if (! in_array($product->product_type, self::enumValues(ProductType::cases()), true)) {
                throw new LogicException("Invalid product type [{$product->product_type}].");
            }

            if (! in_array($product->delivery_model, self::enumValues(ProductDeliveryModel::cases()), true)) {
                throw new LogicException("Invalid product delivery model [{$product->delivery_model}].");
            }

            if (! in_array($product->monetization_model, self::enumValues(ProductMonetizationModel::cases()), true)) {
                throw new LogicException("Invalid product monetization model [{$product->monetization_model}].");
            }

            if (! in_array($product->pricing_model, self::enumValues(ProductPricingModel::cases()), true)) {
                throw new LogicException("Invalid product pricing model [{$product->pricing_model}].");
            }

            if ($product->billing_interval !== null
                && ! in_array($product->billing_interval, self::enumValues(ProductBillingInterval::cases()), true)) {
                throw new LogicException("Invalid product billing interval [{$product->billing_interval}].");
            }

            foreach (self::STRUCTURED_LIST_FIELDS as $field) {
                $value = $product->getAttribute($field);
                if ($value !== null && (! is_array($value) || ! array_is_list($value) || count($value) > 50)) {
                    throw new LogicException("Product {$field} must be a list of at most 50 strings or null.");
                }

                foreach ($value ?? [] as $item) {
                    if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 500) {
                        throw new LogicException("Product {$field} items must be non-empty strings of at most 500 characters.");
                    }
                }
            }

            if ($product->currency !== null) {
                $product->currency = strtoupper(trim((string) $product->currency));
                if (! self::isValidCurrencyCode($product->currency)) {
                    throw new LogicException('Product currency must be a valid ISO 4217 currency code.');
                }
            }

            $rawPrice = $product->getAttributes()['price_amount'] ?? null;
            if ($rawPrice !== null && (! is_numeric($rawPrice)
                || preg_match('/^\d+(?:\.\d{1,2})?$/', (string) $rawPrice) !== 1
                || (float) $rawPrice > 9999999999.99)) {
                throw new LogicException('Product price must fit decimal(12,2) precision.');
            }

            if ($product->price_amount !== null && ($product->currency === null || ! self::isValidCurrencyCode((string) $product->currency))) {
                throw new LogicException('A product price requires a valid ISO 4217 currency.');
            }

            if ($product->price_amount !== null && (float) $product->price_amount < 0) {
                throw new LogicException('Product price cannot be negative.');
            }

            if (($product->pricing_model === ProductPricingModel::FREE->value
                || $product->monetization_model === ProductMonetizationModel::FREE->value)
                && $product->price_amount !== null
                && (float) $product->price_amount > 0) {
                throw new LogicException('A free product cannot have a positive price.');
            }

            $recurring = $product->pricing_model === ProductPricingModel::SUBSCRIPTION->value
                || $product->monetization_model === ProductMonetizationModel::SUBSCRIPTION->value;
            if ($recurring && ! in_array($product->billing_interval, ProductBillingInterval::recurringValues(), true)) {
                throw new LogicException('Recurring products require a supported recurring billing interval.');
            }

            if ($product->availability_start_at !== null && $product->availability_end_at !== null
                && Carbon::parse((string) $product->availability_end_at)->lt(Carbon::parse((string) $product->availability_start_at))) {
                throw new LogicException('Product availability end cannot precede its start.');
            }

            if ($product->exists && $product->isDirty('enterprise_id')) {
                throw new LogicException('Product enterprise ownership cannot be changed.');
            }
        });
    }

    /** @param list<\BackedEnum> $cases
     * @return list<string>
     */
    private static function enumValues(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): string => (string) $case->value, $cases);
    }

    public static function isValidCurrencyCode(string $currency): bool
    {
        if (self::$validCurrencyCodes === null) {
            $bundle = ResourceBundle::create('en', 'ICUDATA-curr');
            $currencies = $bundle instanceof ResourceBundle ? $bundle->get('Currencies') : null;
            $codes = [];

            if ($currencies instanceof ResourceBundle) {
                foreach ($currencies as $code => $_) {
                    if (is_string($code)) {
                        $codes[] = $code;
                    }
                }
            }

            self::$validCurrencyCodes = $codes;
        }

        $normalized = strtoupper(trim($currency));

        return ! in_array($normalized, ['XXX', 'XTS'], true)
            && in_array($normalized, self::$validCurrencyCodes, true);
    }

    /** @return BelongsToMany<Audience, $this, AudienceProduct> */
    public function audiences(): BelongsToMany
    {
        return $this->belongsToMany(Audience::class)->using(AudienceProduct::class)->withTimestamps();
    }

    /** @return BelongsTo<Enterprise, $this> */
    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }
}
