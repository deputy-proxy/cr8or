<?php

namespace App\Http\Controllers;

use App\Enums\SocialRevocationResult;
use App\Exceptions\SocialAuthorizationException;
use App\Models\Channel;
use App\Models\IntegrationConnection;
use App\Models\SocialAuthorizationAttempt;
use App\Models\User;
use App\Services\Social\SocialAuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SocialAuthorizationController
{
    public function start(Request $request, string $network, SocialAuthorizationService $authorization): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'enterprise_id' => ['nullable', 'integer', 'exists:enterprises,id'],
            'channel_id' => ['nullable', 'integer', 'exists:channels,id'],
        ]);

        try {
            $url = $authorization->start(
                $this->actor($request),
                (int) $validated['organization_id'],
                $network,
                isset($validated['enterprise_id']) ? (int) $validated['enterprise_id'] : null,
                isset($validated['channel_id']) ? (int) $validated['channel_id'] : null,
            );

            return redirect()->away($url);
        } catch (SocialAuthorizationException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }
    }

    public function callback(Request $request, string $provider, SocialAuthorizationService $authorization): RedirectResponse
    {
        try {
            $attempt = $authorization->complete(
                $this->actor($request),
                $provider,
                (string) $request->query('state', ''),
                $request->query('code') === null ? null : (string) $request->query('code'),
                $request->query('error') === null ? null : (string) $request->query('error'),
            );

            if ($attempt->status === SocialAuthorizationAttempt::STATUS_DENIED) {
                return redirect()->route('dashboard')->with('error', 'The social provider denied authorization.');
            }

            return redirect()->route('social.authorization.destinations', ['attempt' => $attempt->id]);
        } catch (SocialAuthorizationException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }
    }

    public function destinations(
        Request $request,
        SocialAuthorizationAttempt $attempt,
        SocialAuthorizationService $authorization,
    ): View|RedirectResponse {
        try {
            $authorization->assertCanViewAttempt($this->actor($request), $attempt);
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($attempt->status !== SocialAuthorizationAttempt::STATUS_COMPLETED || $attempt->destinations_attached_at !== null) {
            return redirect()->route('dashboard')->with('error', 'The authorization attempt has no destinations available for selection.');
        }

        $channels = Channel::query()
            ->whereHas('enterprise', function ($query) use ($attempt): void {
                $query->where('organization_id', $attempt->organization_id);
                if ($attempt->enterprise_id !== null) {
                    $query->whereKey($attempt->enterprise_id);
                }
            })
            ->when($attempt->channel_id !== null, fn ($query) => $query->whereKey($attempt->channel_id))
            ->orderBy('name')
            ->get();

        return view('social.authorization.destinations', [
            'attempt' => $attempt,
            'channels' => $channels,
            'destinations' => $attempt->discovered_destinations ?? [],
        ]);
    }

    public function attach(Request $request, SocialAuthorizationAttempt $attempt, SocialAuthorizationService $authorization): RedirectResponse
    {
        $validated = $request->validate([
            'channel_id' => ['required', 'integer', 'exists:channels,id'],
            'destination_indexes' => ['required', 'array', 'min:1', 'max:100'],
            'destination_indexes.*' => ['required', 'integer', 'min:0', 'max:999'],
        ]);

        try {
            $count = $authorization->attachDestinations(
                $this->actor($request),
                $attempt,
                (int) $validated['channel_id'],
                $validated['destination_indexes'],
            );

            return redirect()->route('dashboard')->with('status', "Connected {$count} social destination(s).");
        } catch (SocialAuthorizationException $exception) {
            return redirect()->route('social.authorization.destinations', ['attempt' => $attempt->id])
                ->with('error', $exception->getMessage());
        }
    }

    public function disconnect(Request $request, IntegrationConnection $connection, SocialAuthorizationService $authorization): RedirectResponse
    {
        try {
            $result = $authorization->disconnect($this->actor($request), $connection);
        } catch (SocialAuthorizationException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return match ($result) {
            SocialRevocationResult::Revoked => redirect()->route('dashboard')->with('status', 'The connection was disabled locally and remote revocation was confirmed.'),
            SocialRevocationResult::Unsupported => redirect()->route('dashboard')->with('warning', 'The connection was disabled locally. The provider does not support remote revocation.'),
            SocialRevocationResult::Failed => redirect()->route('dashboard')->with('warning', 'The connection was disabled locally, but remote revocation could not be confirmed.'),
        };
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}