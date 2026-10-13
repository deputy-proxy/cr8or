<?php

namespace App\Contracts;

use App\Data\Social\SocialAuthorizationInitiation;
use App\Data\Social\SocialTokenSet;

interface StatefulSocialAuthorizationProvider extends SocialAuthorizationProvider
{
    /** @param list<string> $scopes */
    public function initiate(string $state, string $redirectUri, array $scopes, string $codeVerifier, ?string $accountIdentifier): SocialAuthorizationInitiation;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $callbackParameters
     */
    public function exchangeWithContext(string $code, string $redirectUri, string $codeVerifier, array $context, array $callbackParameters): SocialTokenSet;
}
