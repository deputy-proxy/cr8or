import { init, use } from 'echarts/core';
import { GraphChart } from 'echarts/charts';
import { AriaComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { getPortfolioStatusColor, normalizeEnterpriseGraphData } from './enterprise-portfolio-data.js';

use([GraphChart, TooltipComponent, CanvasRenderer, AriaComponent]);

const charts = new WeakMap();

const CONNECTION_COLORS = {
    depends_on: '#ef4444',
    supports: '#22c55e',
    integrates_with: '#3b82f6',
    related_to: '#a1a1aa',
    competes_with: '#f59e0b',
    owns: '#a855f7',
};

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
}

function mountPortfolioGraph(root = document) {
    const element = root.querySelector('[data-enterprise-portfolio-chart]');
    const source = root.querySelector('[data-enterprise-portfolio-graph]');
    if (!element || !source || typeof window.Livewire === 'undefined') return;

    const existing = charts.get(element);
    if (existing) {
        window.removeEventListener('resize', existing.resize);
        existing.chart.dispose();
        charts.delete(element);
    }

    let graph;
    try {
        graph = normalizeEnterpriseGraphData(JSON.parse(source.textContent || '{}'));
    } catch {
        element.textContent = 'The Enterprise graph could not be loaded.';
        return;
    }

    if (graph.nodes.length === 0) {
        element.textContent = 'No authorized Enterprises are available to display.';
        return;
    }

    const chart = init(element);
    chart.setOption({
        animationDuration: 350,
        tooltip: {
            trigger: 'item',
            confine: true,
            formatter: (parameter) => {
                const item = parameter.data || {};
                if (parameter.dataType === 'edge') {
                    const sourceNode = graph.nodes.find((node) => node.id === item.source);
                    const targetNode = graph.nodes.find((node) => node.id === item.target);
                    return '<strong>' + escapeHtml(sourceNode?.name ?? item.source) + ' → ' + escapeHtml(targetNode?.name ?? item.target) + '</strong><br>'
                        + escapeHtml(item.type) + (item.description ? '<br>' + escapeHtml(item.description) : '');
                }

                return '<strong>' + escapeHtml(item.name) + '</strong><br>Group: ' + escapeHtml(item.group)
                    + '<br>Category: ' + escapeHtml(item.category) + '<br>Status: ' + escapeHtml(item.status);
            },
        },
        series: [{
            type: 'graph',
            layout: 'force',
            roam: true,
            draggable: true,
            data: graph.nodes.map((node) => ({
                ...node,
                symbolSize: 28,
                itemStyle: { color: getPortfolioStatusColor(node.status) },
                label: { show: true, position: 'right', formatter: (params) => params.data.name, width: 130, overflow: 'truncate' },
            })),
            links: graph.links.map((link) => ({
                ...link,
                symbol: ['none', 'arrow'],
                symbolSize: 8,
                lineStyle: { color: CONNECTION_COLORS[link.type] ?? '#a1a1aa', opacity: 0.8, curveness: 0.12 },
            })),
            force: { repulsion: 190, edgeLength: [80, 180], gravity: 0.08 },
            emphasis: { focus: 'adjacency', lineStyle: { width: 3 } },
            scaleLimit: { min: 0.4, max: 3 },
            lineStyle: { color: 'source', width: 1.5 },
        }],
    });

    chart.on('click', (parameter) => {
        if (parameter.dataType !== 'node' || !Number.isInteger(parameter.data?.enterpriseId)) return;
        window.Livewire.dispatch('select-enterprise', { enterpriseId: parameter.data.enterpriseId });
    });

    const resize = () => chart.resize();
    charts.set(element, { chart, resize });
    window.addEventListener('resize', resize, { passive: true });
}

document.addEventListener('DOMContentLoaded', () => mountPortfolioGraph());
document.addEventListener('livewire:init', () => mountPortfolioGraph());
document.addEventListener('livewire:navigating', () => disposePortfolioGraphs());
document.addEventListener('livewire:navigated', () => mountPortfolioGraph());
window.addEventListener('enterprise-portfolio:refresh', () => mountPortfolioGraph());

export function disposePortfolioGraphs() {
    document.querySelectorAll('[data-enterprise-portfolio-chart]').forEach((element) => {
        const existing = charts.get(element);
    if (existing) {
        window.removeEventListener('resize', existing.resize);
        existing.chart.dispose();
        charts.delete(element);
    }
        charts.delete(element);
    });
}
