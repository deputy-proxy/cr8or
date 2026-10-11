import { init, use } from 'echarts/core';
import { TreeChart } from 'echarts/charts';
import { TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { buildWorkOverviewTreeData } from './work-overview-tree.js';

use([TreeChart, TooltipComponent, CanvasRenderer]);

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll(String.fromCharCode(34), '&quot;')
        .replaceAll(String.fromCharCode(39), '&#39;');
}

function initializeWorkOverviewCharts() {
    document.querySelectorAll('[data-work-overview-tree]:not([data-initialized])').forEach((root) => {
        const sourceElement = root.querySelector('[data-work-overview-source]');
        const chartElement = root.querySelector('[data-work-overview-chart]');
        const emptyElement = root.querySelector('[data-work-overview-empty]');

        if (!sourceElement || !chartElement || !emptyElement) return;

        let source;
        try {
            source = JSON.parse(sourceElement.textContent);
        } catch (error) {
            chartElement.textContent = 'The work hierarchy data could not be read.';
            console.error('Work Overview tree data is invalid.', error);
            return;
        }

        if (!Array.isArray(source) || source.length === 0) {
            chartElement.hidden = true;
            emptyElement.hidden = false;
            root.dataset.initialized = 'true';
            return;
        }

        const chart = init(chartElement, null, { renderer: 'canvas' });
        let expansionMode = 'initial';

        function render() {
            const data = buildWorkOverviewTreeData(source, expansionMode);
            chart.setOption({
                backgroundColor: 'transparent',
                animationDuration: 250,
                tooltip: {
                    trigger: 'item',
                    backgroundColor: '#18181b',
                    borderColor: '#3f3f46',
                    textStyle: { color: '#fafafa', fontSize: 12 },
                    formatter: (params) => {
                        const node = params.data;
                        if (!node) return '';

                        const status = node.status
                            ? `<br>Status: ${escapeHtml(String(node.status).replaceAll('_', ' '))}`
                            : '';
                        const dueAt = node.dueAt
                            ? `<br>Due: ${escapeHtml(new Date(node.dueAt).toLocaleDateString())}`
                            : '';
                        const overdue = node.overdue ? '<br><strong>Overdue</strong>' : '';
                        const count = Array.isArray(node.children) ? node.children.length : 0;
                        const interaction = count
                            ? `<br>${count} child record(s). Click to expand or collapse.`
                            : node.url && node.url !== '#' ? '<br>Click to open record.' : '';

                        return `<strong>${escapeHtml(node.label || node.name)}</strong>${status}${dueAt}${overdue}${interaction}`;
                    },
                },
                series: [{
                    type: 'tree',
                    data,
                    top: 24,
                    left: 24,
                    bottom: 24,
                    right: 260,
                    orient: 'LR',
                    symbol: 'circle',
                    symbolSize: 8,
                    edgeShape: 'curve',
                    edgeForkPosition: '50%',
                    expandAndCollapse: true,
                    roam: true,
                    lineStyle: { color: '#71717a', width: 1.2, curveness: 0.5 },
                    emphasis: { focus: 'descendant' },
                    label: {
                        position: 'left',
                        verticalAlign: 'middle',
                        align: 'right',
                        color: '#e4e4e7',
                        fontSize: 11,
                        distance: 8,
                        width: 230,
                        overflow: 'truncate',
                        formatter: (params) => {
                            const node = params.data;
                            if (!node || node.type === 'root') return '';
                            const hasChildren = Array.isArray(node.children) && node.children.length > 0;
                            const marker = hasChildren ? (node.collapsed ? '▸ ' : '▾ ') : '';
                            return marker + (node.label || node.name);
                        },
                    },
                    leaves: {
                        label: {
                            position: 'right',
                            align: 'left',
                            color: '#e4e4e7',
                            fontSize: 11,
                            width: 230,
                            overflow: 'truncate',
                            formatter: (params) => params.data?.label || params.data?.name || '',
                        },
                    },
                    animationDurationUpdate: 350,
                }],
            }, true);
        }

        chart.on('click', (params) => {
            if (params.dataType !== 'node') return;
            const node = params.data;
            if (node && (!Array.isArray(node.children) || node.children.length === 0) && node.url && node.url !== '#') {
                window.location.href = node.url;
            }
        });

        root._workOverviewChart = {
            chart,
            setExpansionMode(mode) {
                expansionMode = mode;
                render();
            },
        };

        try {
            render();
            root.dataset.initialized = 'true';
        } catch (error) {
            chart.dispose();
            delete root._workOverviewChart;
            console.error('Work Overview tree failed to render.', error);
            chartElement.textContent = 'The work hierarchy could not be rendered. Check the browser console for details.';
            return;
        }

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

    const modeByAction = {
        'expand-all': 'expand',
        'collapse-all': 'collapse',
        reset: 'initial',
    };
    const mode = modeByAction[button.dataset.workOverviewAction];
    if (!mode) return;

    document.querySelectorAll('[data-work-overview-tree][data-initialized="true"]').forEach((root) => {
        root._workOverviewChart?.setExpansionMode(mode);
    });
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWorkOverviewCharts, { once: true });
} else {
    initializeWorkOverviewCharts();
}
document.addEventListener('livewire:navigated', initializeWorkOverviewCharts);