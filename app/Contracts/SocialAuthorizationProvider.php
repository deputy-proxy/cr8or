<?php

namespace App\Contracts;

use App\Data\Social\SocialDiscoveryResult;
use App\Data\Social\SocialTokenSet;
use App\Enums\SocialRevocationResult;

interface SocialAuthorizationProvider
{
    public function key(): string;

    /** @param list<string> $scopes */
    public function authorizationUrl(string $state, string $redirectUri, array $scopes, ?string $codeChallenge): string;

    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?string $codeVerifier): SocialTokenSet;

    public function refresh(SocialTokenSet $current): SocialTokenSet;

    public function revoke(SocialTokenSet $current): SocialRevocationResult;

    public function discover(SocialTokenSet $tokens, string $destinationNetwork): SocialDiscoveryResult;

    public function supportsRefresh(): bool;

    public function supportsRevocation(): bool;
}