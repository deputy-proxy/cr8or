<?php

use Illuminate\Support\Facades\Gate;

it('has an explicit policy with the complete CRUD contract for every model', function () {
    foreach (glob(app_path('Models/*.php')) as $file) {
        $model = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        $policy = 'App\\Policies\\'.class_basename($model).'Policy';

        expect(class_exists($policy))->toBeTrue("Missing policy for {$model}");

        $reflection = new ReflectionClass($policy);

        foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
            expect($reflection->hasMethod($ability))->toBeTrue("{$policy} is missing {$ability}()");
        }

        expect(Gate::getPolicyFor($model))->not->toBeNull();
    }
});

it('does not let an unauthenticated request through a resource collection authorization override', function () {
    foreach (glob(app_path('Filament/Resources/**/*Resource.php')) as $file) {
        $source = file_get_contents($file);

        foreach (['canViewAny', 'canCreate'] as $method) {
            if (! preg_match("/public static function {$method}\\(\\): bool\\s*\\{(.*?)\\n    \\}/s", $source, $match)) {
                continue;
            }

            expect($match[1])
                ->toContain('auth()->check()')
                ->toContain('Gate::allows(');
        }
    }
});
