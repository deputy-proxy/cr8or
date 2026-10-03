<?php

namespace App\Services;

use App\Contracts\CommandWebhookAuthenticator;
use App\Data\CommandWebhookIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class HmacCommandWebhookAuthenticator implements CommandWebhookAuthenticator
{
    public function authenticate(Request $request): CommandWebhookIdentity
    {
        $keyId = trim((string) $request->header('X-CR8OR-Command-Key', ''));
        $timestamp = trim((string) $request->header('X-CR8OR-Command-Timestamp', ''));
        $signature = trim((string) $request->header('X-CR8OR-Command-Signature', ''));
        $credential = config("services.command_webhooks.credentials.{$keyId}");

        if ($keyId === '' || ! is_array($credential)) {
            throw new UnauthorizedHttpException('CR8OR command webhook credentials are invalid.');
        }

        if (! ctype_digit($timestamp)) {
            throw new UnauthorizedHttpException('CR8OR command webhook timestamp is invalid.');
        }

        $tolerance = max(1, (int) config('services.command_webhooks.tolerance', 300));

        $timestampValue = (int) $timestamp;

        if (abs(now()->getTimestamp() - $timestampValue) > $tolerance) {
            throw new UnauthorizedHttpException('CR8OR command webhook timestamp is outside the allowed window.');
        }

        if (! preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)) {
            throw new UnauthorizedHttpException('CR8OR command webhook signature is invalid.');
        }

        $secret = (string) ($credential['secret'] ?? '');
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, strtolower($signature))) {
            throw new UnauthorizedHttpException('CR8OR command webhook signature is invalid.');
        }

        $actorId = (int) ($credential['actor_id'] ?? 0);
        $actor = User::query()->find($actorId);

        if ($actor === null) {
            throw new UnauthorizedHttpException('CR8OR command webhook actor is unavailable.');
        }

        $capabilities = array_values(array_filter(
            is_array($credential['capabilities'] ?? null) ? $credential['capabilities'] : [],
            static fn (mixed $capability): bool => is_string($capability) && $capability !== '',
        ));

        return new CommandWebhookIdentity($keyId, $actor, $capabilities);
    }
}