<?php

namespace App\Services;

final class PlatformEventSanitizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitize(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            if (preg_match('/(?:chain.?of.?thought|reasoning|analysis|thought|prompt|instruction|model.?output)/i', $key) === 1) {
                continue;
            }

            $safe[$key] = self::value($value);
        }

        return $safe;
    }

    private static function value(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::sanitize($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return is_object($value) && method_exists($value, 'getKey')
            ? ['id' => $value->getKey()]
            : (string) $value;
    }
}