<?php

namespace App\Services;

use App\Contracts\MediaStorage;
use App\Exceptions\MediaStorageException;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class R2MediaStorage implements MediaStorage
{
    public function store(string $path, string $contents, array $metadata = []): string
    {
        try {
            $stored = Storage::disk('r2')->put($path, $contents, [
                'Metadata' => $metadata,
            ]);

            if ($stored !== true) {
                throw new MediaStorageException('R2 storage rejected the write.', 'storage.unavailable', true);
            }

            return $path;
        } catch (MediaStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MediaStorageException('R2 storage could not complete the write.', 'storage.unavailable', true, $exception);
        }
    }
}