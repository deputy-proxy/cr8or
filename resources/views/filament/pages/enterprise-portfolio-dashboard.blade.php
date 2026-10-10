<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
            <x-filament::section>
                <p class="text-sm text-gray-500">Authorized Enterprises</p>
                <p class="text-2xl font-semibold">{{ $graphEnterpriseTotal }}</p>
            </x-filament::section>
            <x-filament::section>
                <p class="text-sm text-gray-500">Groups</p>
                <p class="text-2xl font-semibold">{{ $groups->count() }}</p>
            </x-filament::section>
            <x-filament::section>
                <p class="text-sm text-gray-500">Categories</p>
                <p class="text-2xl font-semibold">{{ $categories->count() }}</p>
            </x-filament::section>
            <x-filament::section>
                <p class="text-sm text-gray-500">Connections</p>
                <p class="text-2xl font-semibold">{{ count($graphData['links']) }}</p>
            </x-filament::section>
        </div>

        <x-filament::section heading="Enterprise ecosystem" description="Each node is an Enterprise. Edges show the configured direction. The graph is limited to 250 authorized Enterprises.">
            <div wire:ignore data-enterprise-portfolio-chart role="img" aria-label="Enterprise ecosystem connection graph" class="min-h-[28rem] w-full rounded-md border border-gray-200 dark:border-gray-800"></div>
            <script type="application/json" data-enterprise-portfolio-graph>{!! json_encode($graphData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
            <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-300">
                <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-blue-500"></span>Active Enterprise</span>
                <span><span class="mr-1 inline-block h-2 w-2 rounded-full bg-gray-400"></span>Archived / unknown</span>

            </div>
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-gray-600 dark:text-gray-300">
                <span><span class="mr-1 inline-block w-4 border-t-2 border-red-500 align-middle"></span>Depends on</span>
                <span><span class="mr-1 inline-block w-4 border-t-2 border-green-500 align-middle"></span>Supports</span>
                <span><span class="mr-1 inline-block w-4 border-t-2 border-blue-500 align-middle"></span>Integrates with</span>
                <span><span class="mr-1 inline-block w-4 border-t-2 border-gray-400 align-middle"></span>Related to</span>
                <span><span class="mr-1 inline-block w-4 border-t-2 border-amber-500 align-middle"></span>Competes with</span>
                <span><span class="mr-1 inline-block w-4 border-t-2 border-purple-500 align-middle"></span>Owns</span>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Select a node to inspect the Enterprise. Bidirectional connections render arrows in both directions. The portfolio cards and the connection list below provide a text alternative to the graph.</p>
            <details class="mt-3 rounded-md border border-gray-200 p-3 dark:border-gray-800">
                <summary class="cursor-pointer text-sm font-medium">Accessible connection list ({{ min(count($graphData['links']), 30) }} of {{ count($graphData['links']) }} shown)</summary>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse (array_slice($graphData['links'], 0, 30) as $link)
                        <li class="border-t border-gray-100 pt-2 first:border-0 first:pt-0 dark:border-gray-800">
                            <span class="font-medium">{{ $link['sourceName'] }} → {{ $link['targetName'] }}</span>
                            <span class="text-gray-500">· {{ str($link['type'])->replace('_', ' ')->headline() }}</span>
                            @if ($link['description'])<p class="mt-1 text-xs text-gray-500">{{ $link['description'] }}</p>@endif
                        </li>
                    @empty
                        <li class="text-sm text-gray-500">No Enterprise connections have been configured.</li>
                    @endforelse
                </ul>
            </details>
            @if ($graphEnterpriseTotal > 250)
                <p class="mt-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">Showing the first 250 Enterprises by name. Use the filters below to narrow the portfolio list.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Portfolio" description="Cards and summaries are derived from persisted CR8OR records.">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <label class="block text-sm font-medium">Search
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, slug, domain, repository..." class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                </label>
                <label class="block text-sm font-medium">Group
                    <select wire:model.live="groupFilter" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <option value="">All groups</option>
                        @foreach ($groups as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium">Category
                    <select wire:model.live="categoryFilter" class="mt-1 w-full rounded-md border-gray-300 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                    </select>
                </label>
            </div>
            <p class="mt-4 text-sm text-gray-500">{{ $enterprises->total() }} Enterprise(s) match.</p>
            <div class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                @forelse ($enterprises as $enterprise)
                    <button type="button" wire:click="selectEnterprise({{ $enterprise->id }})" class="rounded-md border p-4 text-left transition hover:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 {{ $selectedEnterprise?->id === $enterprise->id ? 'border-primary-500 bg-primary-50 dark:bg-primary-950' : 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate font-semibold">{{ $enterprise->name }}</h3>
                                <p class="mt-1 text-xs text-gray-500">{{ $enterprise->group?->name ?? 'Ungrouped' }} · {{ $enterprise->category?->name ?? 'Uncategorized' }}</p>
                            </div>
                            <span class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs dark:bg-gray-800">{{ str($enterprise->status)->headline() }}</span>
                        </div>
                        @if ($enterprise->context?->description)<p class="mt-3 line-clamp-2 text-sm text-gray-600 dark:text-gray-300">{{ $enterprise->context->description }}</p>@endif
                        @if ($enterprise->context?->target_market)<p class="mt-2 text-xs text-gray-500">Target market: {{ $enterprise->context->target_market }}</p>@endif
                        <div class="mt-4 grid grid-cols-3 gap-2 border-t border-gray-100 pt-3 text-xs dark:border-gray-800 sm:grid-cols-6">
                            <span>Projects <strong>{{ $enterprise->projects_count }}</strong></span>
                            <span>Tasks <strong>{{ $enterprise->tasks_count }}</strong></span>
                            <span>Work items <strong>{{ $enterprise->work_items_count }}</strong></span>
                            <span>Milestones <strong>{{ $enterprise->milestones_count }}</strong></span>
                            <span>Content <strong>{{ $enterprise->content_items_count }}</strong></span>
                            <span>Issues <strong>{{ $enterprise->issues_count }}</strong></span>
                        </div>
                    </button>
                @empty
                    <div class="col-span-full rounded-md border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                        <p class="font-medium">No Enterprises match these filters.</p>
                        <p class="mt-1 text-sm text-gray-500">Clear filters or create an Enterprise in an organization you manage.</p>
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $enterprises->links() }}</div>
        </x-filament::section>

        @if ($selectedEnterprise)
            <x-filament::section :heading="$selectedEnterprise->name" description="Business context, activity, GitHub issues, work, content, publications, and workflow execution.">
                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <div class="space-y-4">
                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <h3 class="font-semibold">Business context</h3>
                            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs text-gray-500">Group</dt><dd>{{ $selectedEnterprise->group?->name ?? 'Unassigned' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Category</dt><dd>{{ $selectedEnterprise->category?->name ?? 'Unassigned' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Industry</dt><dd>{{ $selectedEnterprise->context?->industry ?? 'Not configured' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Business model</dt><dd>{{ $selectedEnterprise->context?->business_model ?? 'Not configured' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Target market</dt><dd>{{ $selectedEnterprise->context?->target_market ?? 'Not configured' }}</dd></div>
                                <div><dt class="text-xs text-gray-500">Geography</dt><dd>{{ $selectedEnterprise->context?->geography ?? 'Not configured' }}</dd></div>
                            </dl>
                            @if ($selectedEnterprise->context?->description)<p class="mt-4 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $selectedEnterprise->context->description }}</p>@endif
                        </section>

                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="font-semibold">GitHub issues</h3>
                                @can('update', $selectedEnterprise)<button type="button" wire:click="syncGitHubIssues" wire:loading.attr="disabled" class="rounded-md bg-primary-600 px-3 py-2 text-xs font-medium text-white disabled:opacity-50">Queue sync</button>@endcan
                            </div>
                            @if (session('portfolioSyncQueued'))<p class="mt-2 text-sm text-green-700">{{ session('portfolioSyncQueued') }}</p>@endif
                            @error('githubSync')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
                            <p class="mt-2 text-xs text-gray-500">
                                @if ($selectedEnterprise->github_issues_sync_status === 'failed') Last sync failed: {{ $selectedEnterprise->github_issues_sync_error ?? 'Unknown error' }}
                                @elseif ($selectedEnterprise->github_issues_synced_at) Last synced {{ $selectedEnterprise->github_issues_synced_at->diffForHumans() }} ({{ $selectedEnterprise->github_issues_sync_status ?? 'unknown' }})
                                @else No successful sync recorded. @endif
                            </p>
                            <ul class="mt-3 space-y-3">
                                @forelse ($selectedEnterprise->recentIssues as $issue)
                                    <li class="border-t border-gray-100 pt-3 first:border-0 first:pt-0 dark:border-gray-800">
                                        <a href="{{ $issue->url }}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 hover:underline dark:text-primary-400">#{{ $issue->number }} · {{ $issue->title }}</a>
                                        <p class="mt-1 text-xs text-gray-500">{{ str($issue->state)->headline() }} · Updated {{ $issue->github_updated_at?->diffForHumans() ?? 'unknown' }} @if ($issue->labels) · {{ implode(', ', $issue->labels) }} @endif</p>
                                    </li>
                                @empty<li class="text-sm text-gray-500">No synchronized issues for the configured repository.</li>@endforelse
                            </ul>
                        </section>

                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <h3 class="font-semibold">Recent activity</h3>
                            <ul class="mt-3 space-y-3">
                                @forelse ($selectedEnterprise->recentEvents as $event)
                                    <li class="border-t border-gray-100 pt-3 first:border-0 first:pt-0 dark:border-gray-800">
                                        <p class="text-sm font-medium">{{ $event->description }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ str($event->source)->upper() }} · {{ $event->occurred_at?->format('M j, Y H:i') ?? 'Time unavailable' }}</p>
                                    </li>
                                @empty<li class="text-sm text-gray-500">No activity has been recorded yet.</li>@endforelse
                            </ul>
                        </section>
                    </div>

                    <div class="space-y-4">
                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <h3 class="font-semibold">Work management</h3>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 text-sm">
                                <div>Projects <strong>{{ $selectedEnterprise->projects_count }}</strong></div>
                                <div>Milestones <strong>{{ $selectedEnterprise->milestones_count }}</strong></div>
                                <div>Work items <strong>{{ $selectedEnterprise->work_items_count }}</strong></div>
                                <div>Tasks <strong>{{ $selectedEnterprise->tasks_count }}</strong></div>
                                <div>Content <strong>{{ $selectedEnterprise->content_items_count }}</strong></div>
                            </div>
                            <p class="mt-3 text-xs text-gray-500">Projects, Tasks, Work Items, and Milestones remain distinct CR8OR records. These counts do not infer relationships between them.</p>
                        </section>

                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <h3 class="font-semibold">Recent content</h3>
                            <ul class="mt-3 space-y-3">
                                @forelse ($selectedEnterprise->recentContent as $content)
                                    <li class="border-t border-gray-100 pt-3 first:border-0 first:pt-0 dark:border-gray-800">
                                        <p class="font-medium">{{ $content->title ?: 'Untitled content' }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ str($content->status)->headline() }} · Updated {{ $content->updated_at?->diffForHumans() }}</p>
                                    </li>
                                @empty<li class="text-sm text-gray-500">No content items recorded.</li>@endforelse
                            </ul>
                        </section>

                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <h3 class="font-semibold">Publications and schedule</h3>
                            <ul class="mt-3 space-y-3">
                                @forelse ($selectedEnterprise->upcomingSchedules as $schedule)
                                    <li class="border-t border-gray-100 pt-3 first:border-0 first:pt-0 dark:border-gray-800">
                                        <p class="font-medium">{{ $schedule->publication?->contentItem?->title ?? 'Scheduled publication' }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ str($schedule->status)->headline() }} · {{ $schedule->scheduled_at?->format('M j, Y H:i') ?? 'No time set' }}</p>
                                    </li>
                                @empty<li class="text-sm text-gray-500">No upcoming publication schedules.</li>@endforelse
                                @foreach ($selectedEnterprise->recentPublications as $publication)
                                    <li class="border-t border-gray-100 pt-3 dark:border-gray-800">
                                        <p class="font-medium">{{ $publication->contentItem?->title ?? 'Publication' }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ str($publication->status)->headline() }} · Updated {{ $publication->updated_at?->diffForHumans() }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </section>

                        <section class="rounded-md border border-gray-200 p-4 dark:border-gray-800">
                            <h3 class="font-semibold">Workflow executions</h3>
                            <ul class="mt-3 space-y-3">
                                @forelse ($selectedEnterprise->recentWorkflowExecutions as $execution)
                                    <li class="border-t border-gray-100 pt-3 first:border-0 first:pt-0 dark:border-gray-800">
                                        <p class="font-medium">{{ $execution->workflow?->name ?? 'Workflow execution' }}</p>
                                        <p class="mt-1 text-xs text-gray-500">{{ str($execution->status)->headline() }} · Updated {{ $execution->updated_at?->diffForHumans() }}</p>
                                    </li>
                                @empty<li class="text-sm text-gray-500">No workflow executions recorded.</li>@endforelse
                            </ul>
                        </section>
                    </div>
                </div>
            </x-filament::section>
        @else
            <x-filament::section heading="Enterprise details"><p class="text-sm text-gray-500">Select an Enterprise to inspect its persisted records.</p></x-filament::section>
        @endif
    </div>
</x-filament-panels::page>