<?php

namespace App\Services;

use App\Contracts\MediaStorage;
use Illuminate\Support\Facades\Storage;

final class FilesystemMediaStorage implements MediaStorage
{
    public function store(string $path, string $contents, array $metadata = []): string
    {
        Storage::disk('r2')->put($path, $contents);

        return $path;
    }
}
