<?php

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

it('applies tenant-aware create and update guards to every Filament record page', function () {
    $directory = app_path('Filament/Resources');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (! is_string($source)) {
            continue;
        }

        if (str_contains($source, 'extends CreateRecord')) {
            expect(str_contains($source, 'AuthorizesScopedRecordCreation'))->toBeTrue($file->getPathname());
        }

        if (str_contains($source, 'extends EditRecord')) {
            expect(str_contains($source, 'AuthorizesScopedRecordUpdate'))->toBeTrue($file->getPathname());
        }
    }
});
