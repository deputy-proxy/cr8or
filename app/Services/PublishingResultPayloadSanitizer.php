<?php

namespace App\Services;

final class PublishingResultPayloadSanitizer
{
    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $redact = function (array $values) use (&$redact): array {
            $safe = [];
            foreach ($values as $key => $value) {
                $normalizedKey = preg_replace('/[^a-z]/', '', strtolower((string) $key)) ?? '';
                if (str_contains($normalizedKey, 'token')
                    || str_contains($normalizedKey, 'secret')
                    || str_contains($normalizedKey, 'password')
                    || str_contains($normalizedKey, 'authorization')
                    || str_contains($normalizedKey, 'apikey')
                    || str_contains($normalizedKey, 'codeverifier')
                    || str_contains($normalizedKey, 'authorizationcode')) {
                    continue;
                }

                $safe[$key] = is_array($value) ? $redact($value) : $value;
            }

            return $safe;
        };

        return $redact($payload);
    }
}