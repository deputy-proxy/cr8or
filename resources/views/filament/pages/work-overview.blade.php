<x-filament-panels::page>
    <div class="space-y-6 bg-black text-white">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-white">Overview</h1>
                <p class="mt-1 text-sm text-gray-400">Strategy and execution for the selected enterprise.</p>
            </div>
            <div class="min-w-64">
                <label for="overview-enterprise" class="mb-1 block text-xs font-medium text-gray-400">Enterprise</label>
                <select id="overview-enterprise" wire:change="selectEnterprise($event.target.value)" class="w-full rounded-lg border border-gray-700 bg-zinc-900 px-3 py-2 text-sm text-white focus:border-gray-500 focus:outline-none">
                    @foreach ($enterprises as $enterpriseOption)
                        <option value="{{ $enterpriseOption->id }}" @selected($selectedEnterprise?->id === $enterpriseOption->id)>{{ $enterpriseOption->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @if (!$selectedEnterprise)
            <section class="rounded-xl border border-gray-800 bg-zinc-950 p-8 text-center">
                <h2 class="font-medium text-white">No enterprise available</h2>
                <p class="mt-2 text-sm text-gray-400">You do not currently have access to an enterprise to display here.</p>
            </section>
        @else
            {{-- Row 1: headline counts --}}
            <section aria-label="Strategy summary" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($summaryCards as $card)
                    <article class="rounded-xl border border-gray-800 bg-zinc-950 p-5">
                        <div class="text-sm text-gray-400">{{ $card['label'] }}</div>
                        @if ($card['count'] !== null)
                            @if ($card['url'])
                                <a href="{{ $card['url'] }}" class="mt-3 block text-3xl font-semibold tracking-tight text-white hover:text-gray-300">{{ number_format($card['count']) }}</a>
                            @else
                                <div class="mt-3 text-3xl font-semibold tracking-tight text-white">{{ number_format($card['count']) }}</div>
                            @endif
                        @else
                            <div class="mt-3 text-2xl font-semibold text-gray-500">Unavailable</div>
                            <p class="mt-1 text-xs text-gray-500">Not accessible to your account</p>
                        @endif
                    </article>
                @endforeach
            </section>

            {{-- Row 2: compact work lists --}}
            <section aria-label="Current work" class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($listCards as $card)
                    <article class="min-w-0 rounded-xl border border-gray-800 bg-zinc-950 p-4">
                        <div class="mb-4 flex items-center justify-between gap-2">
                            <h2 class="font-medium text-white">{{ $card['label'] }}</h2>
                            @if ($card['url']) <a href="{{ $card['url'] }}" class="text-xs text-gray-400 underline decoration-gray-700 underline-offset-4 hover:text-white">View all</a> @endif
                        </div>
                        @forelse ($card['items'] as $item)
                            <div class="border-t border-gray-800 py-3 first:border-0 first:pt-0">
                                <div class="break-words text-sm text-gray-200">{{ $item['title'] }}</div>
                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                    @if ($item['status']) <span>{{ str($item['status'])->replace('_', ' ')->headline() }}</span> @endif
                                    @if ($item['due']) <span>Due {{ $item['due'] }}</span> @endif
                                </div>
                            </div>
                        @empty
                            <p class="border-t border-gray-800 pt-3 text-sm text-gray-500">No {{ strtolower($card['label']) }} to show.</p>
                        @endforelse
                    </article>
                @endforeach
            </section>

            {{-- Row 3: lifecycle history is rendered from validated snapshots and transitions --}}
            <section aria-label="Daily activity history" class="rounded-xl border border-gray-800 bg-zinc-950 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium text-white">Daily activity</h2>
                        <p class="mt-1 text-sm text-gray-400">Validated daily lifecycle history for Goals, Objectives, Tasks, Milestones and GitHub Issues</p>
                    </div>
                    <span class="rounded-md border border-gray-700 px-2 py-1 text-xs text-gray-400">Last 14 complete days</span>
                </div>
                <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-2">
                    @foreach (['goals' => 'Goals', 'objectives' => 'Objectives', 'tasks' => 'Tasks', 'milestones' => 'Milestones', 'issues' => 'GitHub Issues'] as $entityType => $entityLabel)
                        @php($history = $lifecycleHistory[$entityType] ?? ['available' => false, 'reason' => 'history_not_loaded', 'history_available_from' => null, 'points' => []])
                        <article class="min-w-0 rounded-lg border border-gray-800 bg-black/30 p-4 {{ $entityType === 'issues' ? 'xl:col-span-2' : '' }}" data-lifecycle-history-chart>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-medium text-gray-200">{{ $entityLabel }}</h3>
                                    @if ($history['history_available_from'])
                                        <p class="mt-1 text-xs text-gray-500">History from {{ $history['history_available_from'] }}</p>
                                    @endif
                                </div>
                                <span class="text-xs {{ $history['available'] ? 'text-emerald-400' : 'text-gray-500' }}" data-lifecycle-history-status>
                                    {{ $history['available'] ? 'Validated' : 'Collecting history' }}
                                </span>
                            </div>
                            <div class="mt-3 h-64 w-full" data-lifecycle-history-plot @if (!$history['available']) hidden @endif></div>
                            <div class="flex min-h-36 flex-col items-center justify-center px-4 py-6 text-center" data-lifecycle-history-empty @if ($history['available']) hidden @endif>
                                <p class="text-sm text-gray-300">{{ $entityType === 'issues' ? 'GitHub transition coverage is not yet verifiable.' : 'Waiting for complete start/end snapshots and reconciled lifecycle events.' }}</p>
                                <p class="mt-2 max-w-xl text-xs leading-5 text-gray-500">{{ $entityType === 'issues' ? 'Current-state synchronization cannot prove that intermediate close/reopen transitions were not missed.' : 'Values will appear automatically once daily boundary snapshots and recorded transitions reconcile. No history is inferred from current state or creation dates.' }}</p>
                            </div>
                            <script type="application/json" data-lifecycle-history-source>{!! json_encode($history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
                        </article>
                    @endforeach
                </div>
            </section>

            {{-- Row 4: recent activity and actionable task panels --}}
            <section aria-label="Activity and upcoming work" class="grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-4">
                @foreach ($activityPanels as $panel)
                    <article class="min-w-0 rounded-xl border border-gray-800 bg-zinc-950 p-4">
                        <div class="mb-4 flex items-center justify-between gap-2">
                            <h2 class="font-medium text-white">{{ $panel['label'] }}</h2>
                            @if ($panel['url']) <a href="{{ $panel['url'] }}" class="text-xs text-gray-400 underline decoration-gray-700 underline-offset-4 hover:text-white">View all</a> @endif
                        </div>
                        @forelse ($panel['items'] as $item)
                            <div class="border-t border-gray-800 py-3 first:border-0 first:pt-0">
                                <div class="break-words text-sm text-gray-200">{{ $item['title'] }}</div>
                                <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-gray-500">
                                    @if ($item['status']) <span>{{ str($item['status'])->replace('_', ' ')->headline() }}</span> @endif
                                    @if ($item['due']) <span>{{ $panel['label'] === 'Recent activity' ? $item['due'] : 'Due '.$item['due'] }}</span> @endif
                                </div>
                            </div>
                        @empty
                            <p class="border-t border-gray-800 pt-3 text-sm text-gray-500">Nothing to show here.</p>
                        @endforelse
                    </article>
                @endforeach
            </section>
        @endif
    </div>
</x-filament-panels::page>