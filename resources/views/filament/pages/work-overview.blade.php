<x-filament-panels::page>
    <div class="space-y-6 bg-black text-white" wire:poll.30s.visible="refreshDashboard">
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
            <section aria-label="Strategy summary" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ($summaryCards as $card)
                    <article aria-label="{{ $card['label'] }}: {{ $card['count'] === null ? 'Unavailable' : number_format($card['count']) }}" class="rounded-xl border border-gray-800 bg-zinc-950 p-5">
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

            <section aria-label="Lifecycle chart" class="rounded-xl border border-gray-800 bg-zinc-950 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium text-white">Lifecycle</h2>
                        <p class="mt-1 text-sm text-gray-400">Open inventory and daily created/closed counts · {{ $lifecycleChart['rangeLabel'] }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="overview-lifecycle-entity" class="sr-only">Lifecycle entity type</label>
                        <select id="overview-lifecycle-entity" wire:change="setLifecycleEntityType($event.target.value)" class="rounded-md border border-gray-700 bg-zinc-900 px-2 py-1 text-xs text-gray-300">
                            @foreach ($lifecycleChart['entityOptions'] as $value => $label)
                                <option value="{{ $value }}" @selected($lifecycleChart['selectedType'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if (!$lifecycleChart['available'])
                    <p class="mt-5 rounded-lg border border-gray-800 bg-black/30 px-4 py-6 text-center text-sm text-gray-400">Lifecycle metrics are not available to your account.</p>
                @else
                    <div class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                        @foreach ($lifecycleChart['series'] as $series)
                            <span class="inline-flex items-center gap-2 text-xs text-gray-400">
                                <span class="h-2 w-2 rounded-full" style="background-color: {{ $series['color'] }}"></span>
                                {{ $series['label'] }}
                            </span>
                        @endforeach
                    </div>
                    <div class="mt-3 overflow-x-auto">
                        <svg viewBox="0 0 900 230" role="img" aria-label="Daily lifecycle metrics chart" class="h-64 min-w-[620px] w-full">
                            <title>Daily lifecycle metrics for {{ $lifecycleChart['rangeLabel'] }}</title>
                            @foreach ([18, 74, 130, 186] as $gridY)
                                <line x1="42" y1="{{ $gridY }}" x2="862" y2="{{ $gridY }}" stroke="#27272a" stroke-width="1" />
                            @endforeach
                            @foreach ($lifecycleChart['series'] as $series)
                                @if ($series['path'] !== '')
                                    <path d="{{ $series['path'] }}" fill="none" stroke="{{ $series['color'] }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                @endif
                                @foreach ($series['points'] as $point)
                                    <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="3.5" fill="{{ $series['color'] }}">
                                        <title>{{ $series['label'] }}: {{ $point['value'] }} on {{ $lifecycleChart['days'][$point['index']]['date'] }}</title>
                                    </circle>
                                @endforeach
                            @endforeach
                            @foreach ($lifecycleChart['days'] as $index => $day)
                                @if ($index === 0 || $index === count($lifecycleChart['days']) - 1 || $index % 2 === 0)
                                    <text x="{{ count($lifecycleChart['days']) > 1 ? 42 + (820 / (count($lifecycleChart['days']) - 1) * $index) : 452 }}" y="218" text-anchor="middle" fill="#71717a" font-size="11">{{ $day['label'] }}</text>
                                @endif
                            @endforeach
                        </svg>
                    </div>
                    @if (count($lifecycleChart['limitations']) > 0)
                        <div class="mt-3 rounded-lg border border-amber-900/60 bg-amber-950/20 px-3 py-2 text-xs text-amber-200">
                            <p class="font-medium">Some lifecycle history is incomplete</p>
                            <ul class="mt-1 list-disc space-y-1 pl-4 text-amber-100/80">
                                @foreach ($lifecycleChart['limitations'] as $limitation)
                                    <li>{{ $limitation }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($lifecycleChart['selectedType'] === 'all')
                        <p class="mt-2 text-xs text-gray-600">All work combines entity types your account can access.</p>
                    @endif
                    <p class="mt-2 text-xs text-gray-600">Today’s end-of-day count is not shown until the day has ended. Reopened records cannot be reconstructed before their latest reopening.</p>
                @endif
            </section>

            <section aria-label="Daily activity" class="rounded-xl border border-gray-800 bg-zinc-950 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium text-white">Daily activity</h2>
                        <p class="mt-1 text-sm text-gray-400">Recorded activity · {{ $activityRangeLabel }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <label for="overview-activity-range" class="sr-only">Dashboard date range</label>
                        <select id="overview-activity-range" wire:change="setActivityRange($event.target.value)" class="rounded-md border border-gray-700 bg-zinc-900 px-2 py-1 text-xs text-gray-300">
                            <option value="today" @selected($this->activityRange === 'today')>Today</option>
                            <option value="14_days" @selected($this->activityRange === '14_days')>Last 14 days</option>
                        </select>
                        <span class="rounded-md border border-gray-700 px-2 py-1 text-xs text-gray-400">Live · refreshes every 30 seconds</span>
                    </div>
                </div>

                @if (!$activityAvailable)
                    <p class="mt-5 rounded-lg border border-gray-800 bg-black/30 px-4 py-6 text-center text-sm text-gray-400">Activity is not available to your account.</p>
                @elseif (count($activityDays) === 0)
                    <p class="mt-5 rounded-lg border border-gray-800 bg-black/30 px-4 py-6 text-center text-sm text-gray-400">No activity has been recorded for this enterprise {{ $activityRangeLabel === 'Today' ? 'today' : 'in the last 14 days' }}.</p>
                @else
                    <div class="mt-5 space-y-6">
                        @foreach ($activityDays as $day)
                            <div>
                                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ $day['date'] }}</h3>
                                <ol class="space-y-0 border-l border-gray-700 pl-4">
                                    @foreach ($day['events'] as $event)
                                        <li class="relative border-b border-gray-800 py-3 last:border-0">
                                            <span class="absolute -left-[21px] top-4 h-2 w-2 rounded-full bg-gray-500"></span>
                                            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                                                <p class="min-w-0 flex-1 break-words text-sm text-gray-200">{{ $event['description'] }}</p>
                                                <time class="shrink-0 text-xs tabular-nums text-gray-500">{{ $event['time'] }}</time>
                                            </div>
                                            <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                                <span>{{ $event['type'] }}</span>
                                                <span>{{ $event['actor'] }}</span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-right text-xs text-gray-600">Showing up to 100 most recent events.</p>
                @endif
            </section>

            <section aria-label="Upcoming work" class="grid grid-cols-1 gap-3 md:grid-cols-3">
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
                                    @if ($item['due']) <span>Due {{ $item['due'] }}</span> @endif
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
