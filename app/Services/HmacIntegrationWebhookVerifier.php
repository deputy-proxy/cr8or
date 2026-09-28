<?php

namespace App\Services;

use App\Contracts\IntegrationWebhookVerifier;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class HmacIntegrationWebhookVerifier implements IntegrationWebhookVerifier
{
    public function verify(string $provider, Request $request): void
    {
        $secret = (string) (
            config("services.integrations.webhooks.secrets.{$provider}")
            ?: config('services.integrations.webhooks.default_secret', '')
        );

        $signature = trim((string) $request->header('X-CR8OR-Signature', ''));

        if ($secret === '' || ! preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)) {
            throw new UnauthorizedHttpException('CR8OR webhook signature is missing or unavailable.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, strtolower($signature))) {
            throw new UnauthorizedHttpException('CR8OR webhook signature is invalid.');
        }
    }
}