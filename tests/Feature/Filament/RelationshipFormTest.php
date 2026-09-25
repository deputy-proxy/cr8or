<?php

use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;

function relationshipSelects(mixed $component): array
{
    $selects = [];

    if ($component instanceof Select && $component->getRelationshipName() !== null) {
        $selects[] = $component;
    }

    foreach (['getComponents', 'getChildComponents'] as $method) {
        if (! method_exists($component, $method)) {
            continue;
        }

        foreach ($component->{$method}() as $child) {
            $selects = [...$selects, ...relationshipSelects($child)];
        }

        break;
    }

    return $selects;
}

it('resolves every relationship field on every create and edit resource form', function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament/Resources')),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_ends_with($file->getFilename(), 'Resource.php')) {
            continue;
        }

        $relative = str_replace(
            [app_path('Filament/Resources').DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
            ['', '\\'],
            $file->getPathname(),
        );
        $resource = 'App\\Filament\\Resources\\'.str_replace('.php', '', $relative);

        if (! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
            continue;
        }

        $pages = $resource::getPages();
        $modelClass = $resource::getModel();

        foreach (['create', 'edit'] as $pageKey) {
            if (! isset($pages[$pageKey])) {
                continue;
            }

            $page = app($pages[$pageKey]->getPage());
            $schema = Schema::make()
                ->livewire($page)
                ->model(new $modelClass);

            $configured = $resource::form($schema);

            foreach (relationshipSelects($configured) as $select) {
                expect($select->getRelationshipName())
                    ->not->toBeEmpty()
                    ->and($select->getRelationship())
                    ->not->toBeNull();
            }
        }
    }
});