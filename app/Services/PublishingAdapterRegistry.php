<?php

namespace App\Services;

use App\Contracts\PublishingAdapter;
use App\Contracts\PublishingAnalyticsAdapter;
use App\Contracts\PublishingDeleteAdapter;
use App\Contracts\PublishingEditAdapter;
use App\Contracts\PublishingStatusLookupAdapter;
use App\Data\PublishingAdapterResolution;
use App\Data\PublishingCapabilities;
use App\Enums\PublishingCapabilitySupport;
use App\Exceptions\PublishingAdapterException;
use LogicException;

final class PublishingAdapterRegistry
{
    public function resolve(string $network): PublishingAdapterResolution
    {
        $this->assertUniqueAdapterKeys();

        $definition = config("publishing.adapters.{$network}");
        if (! is_array($definition) || ($definition['enabled'] ?? false) !== true) {
            throw new PublishingAdapterException("Publishing is not enabled for network [{$network}].", 'unsupported_network');
        }

        $class = $definition['class'] ?? null;
        if (! is_string($class) || ! class_exists($class)) {
            throw new LogicException("Publishing adapter for network [{$network}] is misconfigured.");
        }

        $adapter = app($class);
        if (! ($adapter instanceof PublishingAdapter) || $adapter->integrationKey() !== 'publishing' || ! $adapter->supports('publication.publish')) {
            throw new LogicException("Publishing adapter for network [{$network}] does not implement the publishing contract.");
        }

        if ($adapter->network() !== $network) {
            throw new LogicException("Publishing adapter [{$adapter->providerKey()}] declares network [{$adapter->network()}], not [{$network}].");
        }

        $capabilities = $adapter->capabilities();
        if (! $capabilities->supportsNetwork($network)) {
            throw new LogicException("Publishing adapter [{$adapter->providerKey()}] does not declare support for network [{$network}].");
        }

        $adapterKey = $definition['key'] ?? null;
        if (! is_string($adapterKey) || trim($adapterKey) === '' || $adapterKey !== $adapter->providerKey()) {
            throw new LogicException("Publishing adapter key for network [{$network}] must be stable and match the adapter declaration.");
        }

        if ($capabilities->immediatePublishing !== PublishingCapabilitySupport::Supported) {
            throw new LogicException("Publishing adapter [{$adapterKey}] cannot be enabled until immediate publishing is fully supported.");
        }

        $this->assertOptionalCapabilityContracts($adapter, $capabilities);

        return new PublishingAdapterResolution(
            $network,
            $adapterKey,
            $adapter,
            $capabilities,
            ($definition['native_scheduling'] ?? false) === true && $capabilities->supportsScheduling,
        );
    }

    private function assertUniqueAdapterKeys(): void
    {
        $definitions = config('publishing.adapters', []);
        if (! is_array($definitions)) {
            throw new LogicException('Publishing adapter configuration must be a keyed map.');
        }

        $keys = [];
        foreach ($definitions as $network => $definition) {
            if (! is_string($network) || preg_match('/^[a-z][a-z0-9_-]*$/D', $network) !== 1 || ! is_array($definition)) {
                throw new LogicException('Publishing adapter configuration contains an invalid network entry.');
            }

            $key = $definition['key'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_-]*$/D', $key) !== 1) {
                throw new LogicException("Publishing adapter key for network [{$network}] is invalid.");
            }

            if (isset($keys[$key]) && $keys[$key] !== $network) {
                throw new LogicException("Publishing adapter key [{$key}] is registered more than once.");
            }

            $keys[$key] = $network;
        }
    }

    private function assertOptionalCapabilityContracts(PublishingAdapter $adapter, PublishingCapabilities $capabilities): void
    {
        $contracts = [
            'status_lookup' => PublishingStatusLookupAdapter::class,
            'editing' => PublishingEditAdapter::class,
            'deletion' => PublishingDeleteAdapter::class,
            'analytics' => PublishingAnalyticsAdapter::class,
        ];

        foreach ($contracts as $capability => $contract) {
            if ($capabilities->supportFor($capability) !== PublishingCapabilitySupport::Unsupported && ! ($adapter instanceof $contract)) {
                throw new LogicException("Publishing adapter [{$adapter->providerKey()}] declares [{$capability}] but does not implement its optional contract.");
            }
        }
    }
}