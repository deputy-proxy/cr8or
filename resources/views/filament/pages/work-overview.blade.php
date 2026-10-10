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
                <x-filament::button color="gray" size="sm" outlined x-on:click="$dispatch('work-overview-expand-all')">Expand all</x-filament::button>
                <x-filament::button color="gray" size="sm" outlined x-on:click="$dispatch('work-overview-collapse-all')">Collapse all</x-filament::button>
                <x-filament::button color="gray" size="sm" outlined x-on:click="$dispatch('work-overview-reset')">Reset view</x-filament::button>
            </div>
        </div>

        @if (empty($sankeyData['nodes']))
            <div class="rounded-sm border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                No work records are available in your authorized organizations yet.
            </div>
        @else
            <div
                wire:ignore
                x-data="workOverviewSankey(@js($sankeyData))"
                x-init="init()"
                x-on:work-overview-expand-all.window="expandAll()"
                x-on:work-overview-collapse-all.window="collapseAll()"
                x-on:work-overview-reset.window="reset()"
                class="overflow-x-auto rounded-sm border border-gray-200 bg-gray-950 dark:border-gray-700"
            >
                <div x-ref="chart" class="h-[620px] min-w-[900px] w-full"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.6.0/dist/echarts.min.js"></script>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('workOverviewSankey', (source) => ({
                chart: null,
                source,
                expanded: new Set(),

                init() {
                    if (!window.echarts || !this.$refs.chart) return;

                    this.chart = window.echarts.init(this.$refs.chart);
                    this.source.nodes.filter((node) => node.type === 'organization').forEach((node) => this.expanded.add(node.name));
                    this.render();

                    this.chart.on('click', (params) => {
                        if (params.dataType !== 'node') return;
                        const id = params.data.name;
                        if (!this.childrenOf(id).length) {
                            const node = this.source.nodes.find((item) => item.name === id);
                            if (node?.url && node.url !== '#') window.location.href = node.url;
                            return;
                        }

                        if (this.expanded.has(id)) {
                            this.descendantsOf(id).forEach((child) => this.expanded.delete(child));
                            this.expanded.delete(id);
                        } else {
                            this.expanded.add(id);
                        }
                        this.render();
                    });

                    window.addEventListener('resize', () => this.chart?.resize());
                },

                childrenOf(id) {
                    return this.source.links.filter((link) => link.source === id).map((link) => link.target);
                },

                descendantsOf(id, result = []) {
                    this.childrenOf(id).forEach((child) => {
                        result.push(child);
                        this.descendantsOf(child, result);
                    });
                    return result;
                },

                visibleIds() {
                    const visible = new Set();
                    const roots = this.source.nodes.filter((node) => !this.source.links.some((link) => link.target === node.name));
                    const visit = (id) => {
                        if (visible.has(id)) return;
                        visible.add(id);
                        if (this.expanded.has(id)) this.childrenOf(id).forEach(visit);
                    };
                    roots.forEach(visit);
                    return visible;
                },

                render() {
                    const visible = this.visibleIds();
                    const nodes = this.source.nodes.filter((node) => visible.has(node.name)).map((node) => ({
                        name: node.name,
                        label: {
                            formatter: () => {
                                const hasChildren = this.childrenOf(node.name).length > 0;
                                const marker = hasChildren ? (this.expanded.has(node.name) ? '▾ ' : '▸ ') : '';
                                return marker + node.label;
                            }
                        },
                        itemStyle: {
                            color: ({
                                organization: '#a1a1aa',
                                planning: '#3b82f6',
                                work: '#22c55e',
                                supporting: '#f59e0b',
                                governance: '#a855f7'
                            })[node.type] || '#71717a'
                        }
                    }));
                    const links = this.source.links.filter((link) => visible.has(link.source) && visible.has(link.target));

                    this.chart.setOption({
                        backgroundColor: 'transparent',
                        animationDuration: 250,
                        tooltip: {
                            trigger: 'item',
                            backgroundColor: '#18181b',
                            borderColor: '#3f3f46',
                            textStyle: { color: '#fafafa', fontSize: 12 },
                            formatter: (params) => {
                                if (params.dataType === 'edge') {
                                    const from = this.source.nodes.find((node) => node.name === params.data.source);
                                    const to = this.source.nodes.find((node) => node.name === params.data.target);
                                    return (from?.label || params.data.source) + ' → ' + (to?.label || params.data.target);
                                }
                                const node = this.source.nodes.find((item) => item.name === params.data.name);
                                const count = this.childrenOf(params.data.name).length;
                                return '<strong>' + (node?.label || params.data.name) + '</strong>' +
                                    (count ? '<br>' + count + ' child record(s). Click to ' + (this.expanded.has(node.name) ? 'collapse' : 'expand') + '.' : '<br>Click to open records.');
                            }
                        },
                        series: [{
                            type: 'sankey',
                            orient: 'horizontal',
                            left: 24,
                            right: 310,
                            top: 24,
                            bottom: 24,
                            nodeAlign: 'left',
                            nodeWidth: 14,
                            nodeGap: 16,
                            nodeSort: null,
                            layoutIterations: 32,
                            data: nodes,
                            links,
                            emphasis: { focus: 'adjacency', lineStyle: { opacity: 0.8 } },
                            itemStyle: { borderWidth: 0, borderRadius: 3 },
                            lineStyle: { color: 'gradient', opacity: 0.3, curveness: 0.48 },
                            label: {
                                position: 'right',
                                color: '#e4e4e7',
                                fontSize: 11,
                                distance: 8,
                                width: 285,
                                overflow: 'truncate',
                                fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif'
                            }
                        }]
                    }, true);
                },

                expandAll() {
                    this.source.nodes.forEach((node) => {
                        if (this.childrenOf(node.name).length) this.expanded.add(node.name);
                    });
                    this.render();
                },

                collapseAll() {
                    this.expanded.clear();
                    this.render();
                },

                reset() {
                    this.expanded.clear();
                    this.source.nodes.filter((node) => node.type === 'organization').forEach((node) => this.expanded.add(node.name));
                    this.render();
                }
            }));
        });
    </script>
@endonce