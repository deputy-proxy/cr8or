<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section :heading="'Execution #'.$inspection['execution']['id']">
            <div class="grid gap-4 md:grid-cols-4">
                @foreach ([
                    'Status' => $inspection['execution']['status'],
                    'Agent' => $inspection['execution']['agent_slug'],
                    'Enterprise' => $inspection['execution']['enterprise_name'],
                    'Step' => $inspection['execution']['current_step'].' / '.$inspection['execution']['max_steps'],
                    'Retries' => $inspection['execution']['retry_count'].' / '.$inspection['execution']['max_retries'],
                    'Actor' => $inspection['execution']['actor_name'],
                    'Correlation' => $inspection['execution']['correlation_id'],
                    'Reason' => $inspection['execution']['state_reason'] ?: '—',
                ] as $label => $value)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</div>
                        <div class="mt-1 break-words text-sm font-semibold">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section heading="Execution timeline" description="Structured lifecycle events only. Private model reasoning is never displayed.">
            <ol class="space-y-4">
                @forelse ($inspection['timeline'] as $event)
                    <li class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold">{{ $event['type'] }}</span>
                            <span class="text-xs text-gray-500">{{ $event['occurred_at'] }}</span>
                        </div>
                        @if ($event['data'] !== [])
                            <pre class="mt-3 overflow-x-auto whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-300">{{ json_encode($event['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @endif
                    </li>
                @empty
                    <li class="text-sm text-gray-500">No structured events recorded.</li>
                @endforelse
            </ol>
        </x-filament::section>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section heading="Steps">
                <div class="space-y-2">
                    @forelse ($inspection['steps'] as $step)
                        <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <span>#{{ $step['number'] }} · {{ str($step['type'])->headline() }}</span>
                            <span>{{ str($step['status'])->headline() }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No steps recorded.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Approvals">
                <div class="space-y-2">
                    @forelse ($inspection['approvals'] as $approval)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="font-medium">{{ $approval['capability'] }}</div>
                            <div class="text-sm text-gray-500">{{ str($approval['status'])->headline() }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No approvals associated with this execution.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Delegations">
                <div class="space-y-2">
                    @forelse ($inspection['delegations'] as $delegation)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="font-medium">{{ $delegation['target_agent_slug'] }}</div>
                            <div class="text-sm text-gray-500">{{ $delegation['capability'] }} · {{ str($delegation['status'])->headline() }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No delegations from this execution.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="External execution">
                <div class="space-y-2">
                    @forelse ($inspection['integration_results'] as $result)
                        <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <div class="font-medium">{{ $result['provider'] }} · {{ $result['operation'] }}</div>
                            <div class="text-sm text-gray-500">{{ str($result['status'])->headline() }} · {{ str($result['processing_status'])->headline() }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">No external integration result correlated to this execution.</p>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>