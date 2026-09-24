<x-filament-panels::page>
    @forelse ($reports as $report)
        <x-filament::section :heading="$report['enterprise']['name']" :description="$report['enterprise']['slug']">
            <div class="grid gap-4 md:grid-cols-4">
                @foreach ([
                    'Executions' => $report['execution_summary'],
                    'Delegations' => $report['delegation_summary'],
                    'Workflows' => $report['workflow_summary'],
                    'Approvals' => $report['approval_summary'],
                ] as $label => $summary)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-2 text-2xl font-semibold">{{ $summary['total'] }}</div>
                        <div class="mt-2 space-y-1 text-sm">
                            @foreach ($summary as $status => $count)
                                @continue($status === 'total')
                                <div class="flex justify-between gap-4">
                                    <span>{{ str($status)->headline() }}</span>
                                    <span>{{ $count }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-6">
                <h3 class="text-sm font-semibold">Agent activity</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left"><th class="px-2 py-2">Agent</th><th class="px-2 py-2">Executions</th><th class="px-2 py-2">Failed</th><th class="px-2 py-2">Delegations out</th><th class="px-2 py-2">Delegations in</th></tr></thead>
                        <tbody>
                            @forelse ($report['agent_activity'] as $activity)
                                <tr class="border-t border-gray-200 dark:border-gray-700">
                                    <td class="px-2 py-2">{{ $activity['agent_slug'] }}</td>
                                    <td class="px-2 py-2">{{ $activity['execution_count'] }}</td>
                                    <td class="px-2 py-2">{{ $activity['executions_failed'] }}</td>
                                    <td class="px-2 py-2">{{ $activity['delegations_out'] }}</td>
                                    <td class="px-2 py-2">{{ $activity['delegations_in'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-2 py-4 text-gray-500">No Agent activity recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section heading="Agent Collaboration Report">
            <p class="text-sm text-gray-500">No permitted Enterprises are available.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
