<?php

namespace App\Services;

use App\Data\Integrations\IntegrationDefinition;
use App\Data\Integrations\ProviderDefinition;
use LogicException;

final class IntegrationRegistry
{
    /** @return array<string, IntegrationDefinition> */
    public function integrations(): array
    {
        return [
            'creative' => new IntegrationDefinition('creative', 'Creative', 'Bounded creative execution with external design providers.'),
            'publishing' => new IntegrationDefinition('publishing', 'Publishing', 'Bounded social publishing execution.'),
            'storage' => new IntegrationDefinition('storage', 'Storage', 'Canonical file and generated-media storage.'),
            'media' => new IntegrationDefinition('media', 'Media', 'Specialized media generation and rendering execution.'),
            'source_control' => new IntegrationDefinition('source_control', 'Source Control', 'Repository and software-development execution.'),
        ];
    }

    /** @return array<string, ProviderDefinition> */
    public function providers(): array
    {
        return [
            'canva' => new ProviderDefinition('canva', 'creative', 'Canva', ['design.create']),
            'postiz' => new ProviderDefinition('postiz', 'publishing', 'Postiz', ['publication.publish']),
            'cloudflare-r2' => new ProviderDefinition('cloudflare-r2', 'storage', 'Cloudflare R2', ['file.store']),
            'cr8or-media' => new ProviderDefinition('cr8or-media', 'media', 'CR8OR Media', ['media.generate', 'media.render']),
            'github' => new ProviderDefinition('github', 'source_control', 'GitHub', ['repository.execute']),
        ];
    }

    public function provider(string $key): ProviderDefinition
    {
        return $this->providers()[$key] ?? throw new LogicException("Unknown integration provider [{$key}].");
    }

    public function integration(string $key): IntegrationDefinition
    {
        return $this->integrations()[$key] ?? throw new LogicException("Unknown integration [{$key}].");
    }

    public function assertOperation(string $integration, string $provider, string $operation): void
    {
        $definition = $this->provider($provider);

        if ($definition->integration !== $integration || ! in_array($operation, $definition->operations, true)) {
            throw new LogicException("Provider [{$provider}] does not support [{$integration}.{$operation}].");
        }
    }
}