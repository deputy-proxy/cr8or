<?php

namespace App\Services;

final class DiagnosticSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'client_secret',
        'private_key',
        'credential',
        'prompt',
        'model_context',
        'chain_of_thought',
        'request_body',
        'response_body',
    ];

    public function message(string $value): string
    {
        $value = preg_replace(
            '/((?:bearer|basic)\s+)[A-Za-z0-9._~+\/-]+=*/i',
            '$1[REDACTED]',
            $value,
        ) ?? $value;

        $value = preg_replace(
            '/((?:access[_-]?token|refresh[_-]?token|api[_-]?key|client[_-]?secret|authorization|prompt|model[_-]?context|chain[_-]?of[_-]?thought)\s*[:=]\s*)[^\s,;]+/i',
            '$1[REDACTED]',
            $value,
        ) ?? $value;

        return mb_substr($value, 0, 2000);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function context(array $context): array
    {
        return $this->value($context);
    }

    private function value(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_string($value)) {
            return $this->message($value);
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $itemKey => $item) {
                $result[$itemKey] = $this->value($item, (string) $itemKey);
            }

            return $result;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($normalized === $sensitive || str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}