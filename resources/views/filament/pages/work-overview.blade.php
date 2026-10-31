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

            <section aria-label="Daily lifecycle" class="w-full min-w-0 space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium text-white">Daily lifecycle</h2>
                        <p class="mt-1 text-sm text-gray-400">Daily opening balance, items opened and closed, and closing balance · {{ $lifecycleChart['rangeLabel'] }}</p>
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

                @if (!$lifecycleChart['available'])
                    <p class="rounded-lg border border-gray-800 bg-zinc-950 px-4 py-6 text-center text-sm text-gray-400">Lifecycle metrics are not available to your account.</p>
                @else
                    <div aria-label="Lifecycle summary metrics" class="grid grid-cols-1 gap-3 xl:grid-cols-3">
                        @foreach ($lifecycleChart['entities'] as $entity)
                            <article class="min-w-0 rounded-xl border border-gray-800 bg-zinc-950 p-4">
                                <h3 class="mb-3 font-medium text-white">{{ $entity['label'] }}</h3>
                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-2 2xl:grid-cols-4">
                                    <div>
                                        <div class="text-xs text-gray-500">Opening</div>
                                        <div class="mt-1 text-xl font-semibold tabular-nums text-blue-300">{{ $entity['summary']['open_start'] === null ? '—' : number_format($entity['summary']['open_start']) }}</div>
                                        <div class="text-[11px] text-gray-500">First day · 00:00</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Opened</div>
                                        <div class="mt-1 text-xl font-semibold tabular-nums text-green-400">{{ $entity['summary']['created'] === null ? '—' : number_format($entity['summary']['created']) }}</div>
                                        <div class="text-[11px] text-gray-500">During range</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Closed</div>
                                        <div class="mt-1 text-xl font-semibold tabular-nums text-red-400">{{ $entity['summary']['closed'] === null ? '—' : number_format($entity['summary']['closed']) }}</div>
                                        <div class="text-[11px] text-gray-500">During range</div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Closing</div>
                                        <div class="mt-1 text-xl font-semibold tabular-nums text-blue-500">{{ $entity['summary']['open_end'] === null ? '—' : number_format($entity['summary']['open_end']) }}</div>
                                        <div class="text-[11px] text-gray-500">Last complete day · 23:59</div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-x-5 gap-y-2 rounded-xl border border-gray-800 bg-zinc-950 px-4 py-3">
                        @foreach ([
                            ['label' => 'Open at 00:00', 'color' => '#60a5fa'],
                            ['label' => 'Opened during day', 'color' => '#22c55e'],
                            ['label' => 'Closed during day', 'color' => '#ef4444'],
                            ['label' => 'Open at 23:59', 'color' => '#2563eb'],
                        ] as $legend)
                            <span class="inline-flex items-center gap-2 text-xs text-gray-400">
                                <span class="h-2.5 w-2.5 rounded-sm" style="background-color: {{ $legend['color'] }}"></span>
                                {{ $legend['label'] }}
                            </span>
                        @endforeach
                    </div>

                    @foreach ($lifecycleChart['entities'] as $entity)
                        <article aria-label="{{ $entity['label'] }} daily waterfall" class="w-full min-w-0 rounded-xl border border-gray-800 bg-zinc-950 p-4">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-medium text-white">{{ $entity['label'] }}</h3>
                                <span class="text-xs text-gray-500">Each day starts and ends independently</span>
                            </div>
                            <div class="w-full overflow-x-auto">
                                <svg viewBox="0 0 {{ $entity['chart']['width'] }} {{ $entity['chart']['height'] }}" role="img" aria-label="{{ $entity['label'] }} daily opening, opened, closed and closing counts" class="block h-[250px] w-full min-w-[900px]">
                                    <title>{{ $entity['label'] }} daily waterfall for {{ $lifecycleChart['rangeLabel'] }}</title>
                                    @foreach ($entity['chart']['ticks'] as $tick)
                                        <line x1="24" y1="{{ $tick['y'] }}" x2="{{ $entity['chart']['width'] - 12 }}" y2="{{ $tick['y'] }}" stroke="#27272a" stroke-width="1" />
                                        <text x="18" y="{{ $tick['y'] + 4 }}" text-anchor="end" fill="#71717a" font-size="10">{{ $tick['value'] }}</text>
                                    @endforeach
                                    <line x1="24" y1="{{ $entity['chart']['baseline'] }}" x2="{{ $entity['chart']['width'] - 12 }}" y2="{{ $entity['chart']['baseline'] }}" stroke="#52525b" stroke-width="1.2" />
                                    @foreach ($entity['chart']['days'] as $dayIndex => $day)
                                        @foreach ($day['connectors'] as $connector)
                                            <line x1="{{ $connector['x1'] }}" y1="{{ $connector['y'] }}" x2="{{ $connector['x2'] }}" y2="{{ $connector['y'] }}" stroke="#71717a" stroke-width="1" stroke-dasharray="3 3" />
                                        @endforeach
                                        @foreach ($day['bars'] as $bar)
                                            @php($barLabel = match ($bar['key']) { 'open_start' => 'Open at 00:00', 'created' => 'Opened during day', 'closed' => 'Closed during day', default => 'Open at 23:59' })
                                            <rect x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['width'] }}" height="{{ $bar['height'] }}" rx="1.5" fill="{{ $bar['color'] }}">
                                                <title>{{ $barLabel }}: {{ abs($bar['value']) }} on {{ $day['date'] }}</title>
                                            </rect>
                                            <text x="{{ $bar['x'] + ($bar['width'] / 2) }}" y="{{ $bar['label_y'] }}" text-anchor="middle" fill="{{ $bar['color'] }}" font-size="10" font-weight="600">{{ $bar['key'] === 'created' ? '+' : '' }}{{ $bar['value'] }}</text>
                                        @endforeach
                                        <text x="{{ 32 + ($dayIndex * 84) + 42 }}" y="232" text-anchor="middle" fill="#a1a1aa" font-size="10">{{ $day['label'] }}</text>
                                        @if ($day['reconciles'] === false)
                                            <text x="{{ 32 + ($dayIndex * 84) + 42 }}" y="246" text-anchor="middle" fill="#fbbf24" font-size="9">Check</text>
                                        @endif
                                    @endforeach
                                </svg>
                            </div>
                        </article>
                    @endforeach

                    @if (count($lifecycleChart['limitations']) > 0)
                        <div class="rounded-lg border border-amber-900/60 bg-amber-950/20 px-3 py-2 text-xs text-amber-200">
                            <p class="font-medium">Some lifecycle history is incomplete</p>
                            <ul class="mt-1 list-disc space-y-1 pl-4 text-amber-100/80">
                                @foreach ($lifecycleChart['limitations'] as $limitation)
                                    <li>{{ $limitation }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <p class="text-xs text-gray-500">The current day’s closing balance is not available until the day ends. A “Check” marker indicates that the historical opening, opened, closed and closing counts do not reconcile.</p>
                @endif
            </section>

            <section aria-label="Daily activity" class="rounded-xl border border-gray-800 bg-zinc-950 p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-medium text-white">Daily activity</h2>
                        <p class="mt-1 text-sm text-gray-400">Recorded activity · {{ $activityRangeLabel }}</p>
                    </div>
                    <span class="rounded-md border border-gray-700 px-2 py-1 text-xs text-gray-400">Live · refreshes every 30 seconds</span>
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
