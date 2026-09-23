<?php

namespace App\Services;

use App\Contracts\MediaStorage;
use Illuminate\Support\Facades\Storage;

final class FilesystemMediaStorage implements MediaStorage
{
    public function store(string $path, string $contents, array $metadata = []): string
    {
        $disk = (string) ($metadata['disk'] ?? config('filesystems.default'));
        Storage::disk($disk)->put($path, $contents);

        return $path;
    }
}
