<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Mail\CapabilityEmail;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class SendEmail implements Operation
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{accepted: true, recipient: string, subject: string, mailer: string}
     */
    public function execute(User $actor, array $input): array
    {
        $enterprise = ($input['enterprise'] ?? null) instanceof Enterprise
            ? $input['enterprise']
            : Enterprise::query()->findOrFail((int) $input['enterprise_id']);

        Gate::forUser($actor)->authorize('view', $enterprise);

        if (! $actor->hasVerifiedEmail() || filter_var($actor->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Email delivery requires a verified email address on the authenticated account.');
        }

        $subject = trim((string) ($input['subject'] ?? ''));
        $textBody = (string) ($input['text_body'] ?? '');
        if ($subject === '' || mb_strlen($subject) > 255 || $textBody === '' || mb_strlen($textBody) > 100000) {
            throw new RuntimeException('Email subject and body are required and must remain within the supported length limits.');
        }
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new RuntimeException('Email subject must be a single line.');
        }

        $mailer = (string) config('mail.default');
        $mailerConfig = (array) config("mail.mailers.{$mailer}", []);
        $transport = (string) ($mailerConfig['transport'] ?? $mailer);
        $hasNonDeliveryFallback = false;
        $fallbackMailers = $mailerConfig['mailers'] ?? [];
        if ($transport === 'failover' && is_array($fallbackMailers)) {
            foreach ($fallbackMailers as $fallback) {
                if (is_string($fallback) && in_array((string) config("mail.mailers.{$fallback}.transport"), ['log', 'array'], true)) {
                    $hasNonDeliveryFallback = true;
                    break;
                }
            }
        }
        if (in_array($transport, ['log', 'array'], true) || $hasNonDeliveryFallback) {
            throw new RuntimeException('Email delivery is disabled because the configured mailer can accept messages without delivering them.');
        }

        try {
            Mail::to($actor->email)->send(new CapabilityEmail(
                $subject,
                $textBody,
            ));
        } catch (Throwable $exception) {
            throw new RuntimeException('The configured mail transport failed to accept the email.', previous: $exception);
        }

        return [
            'accepted' => true,
            'recipient' => $actor->email,
            'subject' => $subject,
            'mailer' => $mailer,
        ];
    }
}
