<?php

namespace App\Models;

use App\Experts\Expert;
use Database\Factories\ExpertDescriptorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $slug
 * @property class-string<Expert> $runtime_class
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[Fillable(['slug', 'runtime_class', 'enabled'])]
class ExpertDescriptor extends Model
{
    /** @use HasFactory<ExpertDescriptorFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (ExpertDescriptor $descriptor): void {
            $descriptor->validateRuntimeClass();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return class-string<Expert>
     */
    public function resolveRuntimeClass(): string
    {
        $this->validateRuntimeClass();

        /** @var class-string<Expert> $runtimeClass */
        $runtimeClass = $this->runtime_class;

        return $runtimeClass;
    }

    protected function validateRuntimeClass(): void
    {
        if (! class_exists($this->runtime_class) || ! is_a($this->runtime_class, Expert::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Expert descriptor runtime class [%s] must extend [%s].',
                $this->runtime_class,
                Expert::class,
            ));
        }
    }
}
