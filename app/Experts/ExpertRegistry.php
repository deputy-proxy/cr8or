<?php

namespace App\Experts;

use App\Models\ExpertDescriptor;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ExpertRegistry
{
    /** @return array<string, Expert> */
    public function all(): array
    {
        $descriptors = ExpertDescriptor::query()
            ->get()
            ->keyBy('slug');

        $experts = [];

        foreach (glob(app_path('Experts/*Expert.php')) ?: [] as $path) {
            $class = 'App\\Experts\\'.pathinfo($path, PATHINFO_FILENAME);

            if ($class === Expert::class || ! class_exists($class) || ! is_a($class, Expert::class, true)) {
                continue;
            }

            $slug = Str::of(class_basename($class))->beforeLast('Expert')->kebab()->toString();
            $descriptor = $descriptors->get($slug);

            if ($descriptor instanceof ExpertDescriptor) {
                if (! $descriptor->enabled) {
                    continue;
                }

                $class = $descriptor->resolveRuntimeClass();
            }

            $runtime = app($class);

            if (! $runtime instanceof Expert) {
                throw new InvalidArgumentException("Expert [{$slug}] has an invalid runtime.");
            }

            $experts[$slug] = $runtime;
        }

        ksort($experts);

        return $experts;
    }

    public function resolve(string $slug): Expert
    {
        $expert = $this->all()[$slug] ?? null;

        if (! $expert instanceof Expert) {
            throw new InvalidArgumentException("Unknown or disabled Expert [{$slug}].");
        }

        return $expert;
    }

    /** @return array<string, string> */
    public function options(): array
    {
        return collect($this->all())
            ->mapWithKeys(fn (Expert $expert, string $slug): array => [$slug => $expert->name()])
            ->all();
    }
}