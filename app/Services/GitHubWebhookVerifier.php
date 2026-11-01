<?php

namespace App\Services;

final class GitHubWebhookVerifier
{
    public function verify(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.github.webhook_secret');
        if ($secret === '' || ! is_string($signature) || ! preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }
}
