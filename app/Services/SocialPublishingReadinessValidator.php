<?php

namespace App\Services;

use Throwable;

final class SocialPublishingReadinessValidator
{
    /** @return array<string, list<string>> */
    public function validate(): array
    {
        $errors = [];
        $adapters = config('publishing.adapters', []);
        $providers = config('social_authorization.providers', []);
        $networkProviders = config('social_authorization.network_to_provider', []);
        $resolvers = config('social_authorization.credential_resolvers', []);
        $production = app()->environment('production');

        if (! is_array($adapters)) {
            return ['publishing' => ['Publishing adapter configuration must be a keyed map.']];
        }

        foreach ($adapters as $network => $adapter) {
            if (! is_array($adapter) || ($adapter['enabled'] ?? false) !== true) {
                continue;
            }

            $networkErrors = [];
            $providerKey = is_array($networkProviders) ? ($networkProviders[$network] ?? null) : null;
            $provider = is_string($providerKey) && is_array($providers) ? ($providers[$providerKey] ?? null) : null;

            if (! is_string($providerKey) || ! is_array($provider)) {
                $networkErrors[] = 'No social authorization provider is configured for this network.';
            } else {
                if (($provider['enabled'] ?? false) !== true) {
                    $networkErrors[] = 'The mapped social authorization provider is disabled or missing required credentials.';
                }

                if (! is_string($provider['client_id'] ?? null) || trim($provider['client_id']) === '') {
                    $networkErrors[] = 'The mapped social authorization provider is missing its client identifier.';
                }

                if (($provider['driver'] ?? null) !== 'atproto_oauth'
                    && (! is_string($provider['client_secret'] ?? null) || trim($provider['client_secret']) === '')) {
                    $networkErrors[] = 'The mapped social authorization provider is missing its client secret.';
                }

                if (! is_array($resolvers) || ($resolvers[$providerKey] ?? null) !== 'social') {
                    $networkErrors[] = 'The provider is not mapped to the canonical social credential resolver.';
                }

                $redirectUri = $provider['redirect_uri'] ?? null;
                $redirect = is_string($redirectUri) ? parse_url($redirectUri) : false;
                $applicationUrl = parse_url((string) config('app.url'));
                $expectedPath = '/social/authorization/callback/'.$providerKey;

                if (! is_array($redirect)
                    || ! is_string($redirect['host'] ?? null)
                    || ! in_array($redirect['scheme'] ?? null, $production ? ['https'] : ['https', 'http'], true)
                    || ($redirect['path'] ?? null) !== $expectedPath
                    || isset($redirect['user'])
                    || isset($redirect['pass'])
                    || isset($redirect['query'])
                    || isset($redirect['fragment'])
                    || ($production && (isset($redirect['port']) || ! is_array($applicationUrl) || strtolower($redirect['host']) !== strtolower((string) ($applicationUrl['host'] ?? ''))))) {
                    $networkErrors[] = 'The OAuth callback URL is invalid for the configured application environment.';
                }

                if (($provider['driver'] ?? null) === 'atproto_oauth') {
                    $clientId = is_string($provider['client_id'] ?? null) ? parse_url($provider['client_id']) : false;
                    $clientUri = is_string($provider['client_uri'] ?? null) ? parse_url($provider['client_uri']) : false;
                    if (! is_array($clientId)
                        || ($clientId['scheme'] ?? null) !== 'https'
                        || ($clientId['path'] ?? null) !== '/oauth-client-metadata.json'
                        || isset($clientId['port'])
                        || isset($clientId['query'])
                        || isset($clientId['fragment'])
                        || isset($clientId['user'])
                        || isset($clientId['pass'])
                        || ! is_array($clientUri)
                        || ($clientUri['scheme'] ?? null) !== 'https'
                        || strtolower((string) ($clientId['host'] ?? '')) !== strtolower((string) ($clientUri['host'] ?? ''))) {
                        $networkErrors[] = 'The AT Protocol OAuth client metadata URL and client URI must use the same public HTTPS host.';
                    }
                }
            }

            if ($network === 'x') {
                $apiUrl = parse_url((string) config('publishing.x.api_base_url'));
                if (! is_array($apiUrl)
                    || ($apiUrl['scheme'] ?? null) !== 'https'
                    || strtolower((string) ($apiUrl['host'] ?? '')) !== 'api.x.com'
                    || rtrim((string) ($apiUrl['path'] ?? ''), '/') !== '/2'
                    || isset($apiUrl['user'])
                    || isset($apiUrl['pass'])) {
                    $networkErrors[] = 'The X API base URL must be https://api.x.com/2.';
                }
            }

            try {
                app(PublishingAdapterRegistry::class)->resolve((string) $network);
            } catch (Throwable) {
                $networkErrors[] = 'The enabled publishing adapter does not satisfy its configured contract.';
            }

            if ($networkErrors !== []) {
                $errors[(string) $network] = array_values(array_unique($networkErrors));
            }
        }

        if (collect($adapters)->contains(fn ($adapter): bool => is_array($adapter) && ($adapter['enabled'] ?? false) === true)) {
            $queueConnection = (string) config('publishing.status_reconciliation.queue_connection', 'database');
            if (! is_array(config('queue.connections.'.$queueConnection))) {
                $errors['publishing'][] = 'The configured publication status queue connection is not defined.';
            }

            $maxPolls = (int) config('publishing.status_reconciliation.max_polls', 48);
            if ($maxPolls < 1 || $maxPolls > 100) {
                $errors['publishing'][] = 'Publication status max polls must be between 1 and 100.';
            }
            $initialDelay = (int) config('publishing.status_reconciliation.initial_delay_seconds', 60);
            $pollDelay = (int) config('publishing.status_reconciliation.poll_delay_seconds', 300);
            if ($initialDelay < 1 || $initialDelay > 3600 || $pollDelay < 1 || $pollDelay > 3600) {
                $errors['publishing'][] = 'Publication status delays must be between 1 and 3600 seconds.';
            }
        }

        return $errors;
    }
}
