<?php

namespace App\Services;

use App\Contracts\PublishingAdapter;
use App\Data\PublishingAdapterResolution;
use App\Exceptions\PublishingAdapterException;
use LogicException;

final class PublishingAdapterRegistry
{
    /** @var array<string, PublishingAdapterResolution> */
    private array $resolved = [];

    public function resolve(string $network): PublishingAdapterResolution
    {
        if (isset($this->resolved[$network])) {
            return $this->resolved[$network];
        }

        $definition = config("publishing.adapters.{$network}");
        if (! is_array($definition) || ($definition['enabled'] ?? false) !== true) {
            throw new PublishingAdapterException("Publishing is not enabled for network [{$network}].", 'unsupported_network');
        }

        $class = $definition['class'] ?? null;
        if (! is_string($class) || ! class_exists($class)) {
            throw new LogicException("Publishing adapter for network [{$network}] is misconfigured.");
        }

        $adapter = app($class);
        if (! $adapter instanceof PublishingAdapter || ! $adapter->supports('publication.publish')) {
            throw new LogicException("Publishing adapter for network [{$network}] does not implement the publishing contract.");
        }

        $capabilities = $adapter->capabilities();
        if (! $capabilities->supportsNetwork($network)) {
            throw new LogicException("Publishing adapter [{$adapter->providerKey()}] does not declare support for network [{$network}].");
        }

        $adapterKey = $definition['key'] ?? $adapter->providerKey();
        if (! is_string($adapterKey) || trim($adapterKey) === '') {
            throw new LogicException("Publishing adapter key for network [{$network}] is invalid.");
        }

        return $this->resolved[$network] = new PublishingAdapterResolution(
            $network,
            $adapterKey,
            $adapter,
            $capabilities,
            ($definition['native_scheduling'] ?? false) === true && $capabilities->supportsScheduling,
        );
    }
}