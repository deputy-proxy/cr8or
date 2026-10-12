<?php

it('provides exactly one Filament resource for every model', function () {
    $models = [];

    foreach (glob(app_path('Models/*.php')) as $file) {
        $contents = file_get_contents($file);
        preg_match('/\\bclass\\s+(\\w+)/', $contents, $match);
        $models[] = $match[1];
    }

    $resources = [];

    foreach (glob(app_path('Filament/Resources/**/*Resource.php')) as $file) {
        $contents = file_get_contents($file);
        preg_match('/\\bclass\\s+(\\w+Resource)/', $contents, $match);
        preg_match('/protected static \\?string \\$model = (\\w+)::class;/', $contents, $modelMatch);

        if ($match && $modelMatch) {
            $resources[$modelMatch[1]][] = $match[1];
        }
    }

    foreach ($models as $model) {
        expect($resources[$model] ?? [])
            ->toHaveCount(1, "Model {$model} must map to exactly one Filament Resource");
    }

    foreach ($resources as $model => $mappedResources) {
        expect($models)->toContain($model);
        expect($mappedResources)->toHaveCount(1, "Model {$model} has duplicate Filament Resources");
    }
});
