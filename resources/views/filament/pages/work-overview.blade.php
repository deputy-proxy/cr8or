<x-filament-panels::page>
    <x-filament::section
        heading="Work Overview"
        description="Explore the work hierarchy across the organizations you can access. Select any node with children to expand or collapse that branch."
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-4 text-xs text-gray-600 dark:text-gray-300">
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-gray-400"></span>Organization</span>
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-blue-500"></span>Planning</span>
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-green-500"></span>Work items</span>
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-amber-500"></span>Supporting records</span>
                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-purple-500"></span>Governance</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-filament::button color="gray" size="sm" outlined data-work-overview-action="expand-all">Expand all</x-filament::button>
                <x-filament::button color="gray" size="sm" outlined data-work-overview-action="collapse-all">Collapse all</x-filament::button>
                <x-filament::button color="gray" size="sm" outlined data-work-overview-action="reset">Reset view</x-filament::button>
            </div>
        </div>

        @if (empty($sankeyData['nodes']))
            <div class="rounded-sm border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                No work records are available in your authorized organizations yet.
            </div>
        @else
            <div
                wire:ignore
                data-work-overview-sankey
                class="overflow-x-auto rounded-sm border border-gray-200 bg-gray-950 dark:border-gray-700"
            >
                <script type="application/json" data-work-overview-source>{!! json_encode($sankeyData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
                <div data-work-overview-chart class="h-[620px] min-w-[900px] w-full"></div>
                <div data-work-overview-empty hidden class="p-8 text-center text-sm text-gray-400">There are no linked work records to visualize yet. Add projects or related records to see the hierarchy.</div>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Sample-free view of persisted records. Link widths are illustrative. Tasks follow their current project/parent-task relationships; they are not implicitly attached to Work Items.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Record counts" description="Counts are scoped to your authorized organizations. Select a category to open its records.">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($overview as $item)
                <a
                    href="{{ $item['url'] }}"
                    class="group block rounded-sm border border-gray-200 p-5 transition hover:border-primary-500 hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-white/5"
                >
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="text-sm font-medium text-gray-600 group-hover:text-primary-600 dark:text-gray-300 dark:group-hover:text-primary-400">{{ $item['label'] }}</h2>
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-4 w-4 text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400" />
                    </div>
                    <p class="mt-3 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ number_format($item['count']) }}</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">View records</p>
                </a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>


@once
    @vite('resources/js/work-overview.js')
@endonce