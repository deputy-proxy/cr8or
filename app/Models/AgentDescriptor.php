<?php

namespace App\Models;

use App\Agents\Agent;
use Database\Factories\AgentDescriptorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @property int $id
 * @property string $slug
 * @property class-string<Agent> $runtime_class
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[Fillable(['slug', 'runtime_class', 'enabled'])]
class AgentDescriptor extends Model
{
    /** @use HasFactory<AgentDescriptorFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (AgentDescriptor $descriptor): void {
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
     * @return class-string<Agent>
     */
    public function resolveRuntimeClass(): string
    {
        $this->validateRuntimeClass();

        /** @var class-string<Agent> $runtimeClass */
        $runtimeClass = $this->runtime_class;

        return $runtimeClass;
    }

    protected function validateRuntimeClass(): void
    {
        if (! class_exists($this->runtime_class) || ! is_a($this->runtime_class, Agent::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Agent descriptor runtime class [%s] must extend [%s].',
                $this->runtime_class,
                Agent::class,
            ));
        }
    }
}
