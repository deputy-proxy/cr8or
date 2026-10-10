import { init, use } from 'echarts/core';
import { SankeyChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

use([SankeyChart, TooltipComponent, CanvasRenderer]);

const colors = {
    organization: '#a1a1aa',
    planning: '#3b82f6',
    work: '#22c55e',
    supporting: '#f59e0b',
    governance: '#a855f7',
};

function initializeWorkOverviewCharts() {
    document.querySelectorAll('[data-work-overview-sankey]:not([data-initialized])').forEach((root) => {
        const sourceElement = root.querySelector('[data-work-overview-source]');
        const chartElement = root.querySelector('[data-work-overview-chart]');
        const emptyElement = root.querySelector('[data-work-overview-empty]');

        if (!sourceElement || !chartElement || !emptyElement) return;

        let source;
        try {
            source = JSON.parse(sourceElement.textContent);
        } catch (error) {
            chartElement.textContent = 'The work hierarchy data could not be read.';
            console.error('Work Overview Sankey data is invalid.', error);
            return;
        }

        if (!source.nodes?.length || !source.links?.length) {
            chartElement.hidden = true;
            emptyElement.hidden = false;
            root.dataset.initialized = 'true';
            return;
        }

        const chart = init(chartElement, null, { renderer: 'canvas' });
        const expanded = new Set(source.nodes.filter((node) => node.type === 'organization').map((node) => node.name));
        const childrenOf = (id) => source.links.filter((link) => link.source === id).map((link) => link.target);

        function descendantsOf(id, result = []) {
            childrenOf(id).forEach((child) => {
                result.push(child);
                descendantsOf(child, result);
            });
            return result;
        }

        function visibleIds() {
            const visible = new Set();
            const roots = source.nodes.filter((node) => !source.links.some((link) => link.target === node.name));
            const visit = (id) => {
                if (visible.has(id)) return;
                visible.add(id);
                if (expanded.has(id)) childrenOf(id).forEach(visit);
            };
            roots.forEach(visit);
            return visible;
        }

        function render() {
            const visible = visibleIds();
            const nodes = source.nodes.filter((node) => visible.has(node.name)).map((node) => ({
                name: node.name,
                label: {
                    formatter: () => {
                        const hasChildren = childrenOf(node.name).length > 0;
                        const marker = hasChildren ? (expanded.has(node.name) ? '▾ ' : '▸ ') : '';
                        return marker + node.label;
                    },
                },
                itemStyle: { color: colors[node.type] || '#71717a' },
            }));
            const links = source.links.filter((link) => visible.has(link.source) && visible.has(link.target));

            chart.setOption({
                backgroundColor: 'transparent',
                animationDuration: 250,
                tooltip: {
                    trigger: 'item',
                    backgroundColor: '#18181b',
                    borderColor: '#3f3f46',
                    textStyle: { color: '#fafafa', fontSize: 12 },
                    formatter: (params) => {
                        if (params.dataType === 'edge') {
                            const from = source.nodes.find((node) => node.name === params.data.source);
                            const to = source.nodes.find((node) => node.name === params.data.target);
                            return `${from?.label || params.data.source} → ${to?.label || params.data.target}`;
                        }
                        const node = source.nodes.find((item) => item.name === params.data.name);
                        const count = childrenOf(params.data.name).length;
                        return `<strong>${node?.label || params.data.name}</strong>${count ? `<br>${count} child record(s). Click to ${expanded.has(node.name) ? 'collapse' : 'expand'}.` : '<br>Click to open records.'}`;
                    },
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
                        fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
                    },
                }],
            }, true);
        }

        chart.on('click', (params) => {
            if (params.dataType !== 'node') return;
            const id = params.data.name;
            if (!childrenOf(id).length) {
                const node = source.nodes.find((item) => item.name === id);
                if (node?.url && node.url !== '#') window.location.href = node.url;
                return;
            }

            if (expanded.has(id)) {
                descendantsOf(id).forEach((child) => expanded.delete(child));
                expanded.delete(id);
            } else {
                expanded.add(id);
            }
            render();
        });

        root.dataset.initialized = 'true';
        root._workOverviewChart = { chart, source, expanded, childrenOf, render };
        render();

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(() => chart.resize());
            observer.observe(chartElement);
            root._workOverviewResizeObserver = observer;
        } else {
            window.addEventListener('resize', () => chart.resize());
        }
    });
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-work-overview-action]');
    if (!button) return;

    const action = button.dataset.workOverviewAction;
    document.querySelectorAll('[data-work-overview-sankey][data-initialized="true"]').forEach((root) => {
        const state = root._workOverviewChart;
        if (!state) return;

        if (action === 'expand-all') {
            state.source.nodes.forEach((node) => {
                if (state.childrenOf(node.name).length) state.expanded.add(node.name);
            });
        } else if (action === 'collapse-all') {
            state.expanded.clear();
        } else if (action === 'reset') {
            state.expanded.clear();
            state.source.nodes.filter((node) => node.type === 'organization').forEach((node) => state.expanded.add(node.name));
        }
        state.render();
    });
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWorkOverviewCharts, { once: true });
} else {
    initializeWorkOverviewCharts();
}
document.addEventListener('livewire:navigated', initializeWorkOverviewCharts);