<?php

it('does not filter direct enterprise relationship selects by the child foreign key', function () {
    $resources = glob(app_path('Filament/Resources/**/*.php'));

    foreach ($resources as $resource) {
        $source = file_get_contents($resource);

        expect($source)->not->toMatch('/relationship\(\s*[\'\"]enterprise[\'\"][^\n]*whereIn\(\s*[\'\"]enterprise_id[\'\"]/');
    }
});
