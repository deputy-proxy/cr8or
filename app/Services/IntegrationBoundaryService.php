<?php

namespace App\Services;

use App\Data\Integrations\CredentialReference;
use App\Data\Integrations\IntegrationExecutionContext;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class IntegrationBoundaryService
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    /** @param array<string, mixed> $resource */
    public function authorizeConnection(
        User $actor,
        IntegrationConnection $connection,
        Enterprise $enterprise,
        string $integration,
        string $operation,
        string $correlationId,
        string $idempotencyKey,
        array $resource = [],
    ): IntegrationExecutionContext {
        $this->registry->assertOperation($integration, $connection->provider, $operation);

        if ((int) $connection->organization_id !== (int) $enterprise->organization_id
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $enterprise->id)
        ) {
            throw new AuthorizationException('The integration connection is outside the target enterprise boundary.');
        }

        if (! $connection->isOperational()) {
            throw new AuthorizationException('The integration connection is not operational.');
        }

        if ($actor->id <= 0) {
            throw new AuthorizationException('A valid actor is required for external execution.');
        }

        return new IntegrationExecutionContext(
            (int) $enterprise->organization_id,
            (int) $enterprise->id,
            $integration,
            $connection->provider,
            $resource,
            $correlationId,
            $idempotencyKey,
        );
    }

    public function credentialReference(IntegrationConnection $connection): CredentialReference
    {
        return new CredentialReference($connection->credential_reference);
    }
}