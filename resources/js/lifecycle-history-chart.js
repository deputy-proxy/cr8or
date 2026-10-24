import { init, use } from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

use([LineChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer]);

function initializeLifecycleHistoryCharts() {
    document.querySelectorAll('[data-lifecycle-history-chart]:not([data-initialized])').forEach((root) => {
        const source = root.querySelector('[data-lifecycle-history-source]');
        const plot = root.querySelector('[data-lifecycle-history-plot]');
        const empty = root.querySelector('[data-lifecycle-history-empty]');

        if (!source || !plot || !empty) return;

        let history;
        try {
            history = JSON.parse(source.textContent);
        } catch (error) {
            console.error('Lifecycle history chart data is invalid.', error);
            return;
        }

        if (!history?.available || !Array.isArray(history.points) || history.points.length === 0) {
            root.dataset.initialized = 'true';
            return;
        }

        plot.hidden = false;
        empty.hidden = true;

        const chart = init(plot, null, { renderer: 'canvas' });
        chart.setOption({
            backgroundColor: 'transparent',
            animationDuration: 250,
            color: ['#a1a1aa', '#fafafa', '#34d399', '#f87171'],
            tooltip: {
                trigger: 'axis',
                backgroundColor: '#18181b',
                borderColor: '#3f3f46',
                textStyle: { color: '#fafafa', fontSize: 12 },
            },
            legend: {
                bottom: 0,
                textStyle: { color: '#a1a1aa', fontSize: 11 },
                itemWidth: 12,
                itemHeight: 8,
            },
            grid: { top: 16, left: 42, right: 16, bottom: 56 },
            xAxis: {
                type: 'category',
                boundaryGap: false,
                data: history.points.map((point) => point.date),
                axisLabel: { color: '#71717a', fontSize: 10, hideOverlap: true },
                axisLine: { lineStyle: { color: '#3f3f46' } },
                axisTick: { show: false },
            },
            yAxis: {
                type: 'value',
                minInterval: 1,
                axisLabel: { color: '#71717a', fontSize: 10 },
                splitLine: { lineStyle: { color: '#27272a' } },
            },
            series: [
                { name: 'Open at start', type: 'line', smooth: false, showSymbol: false, data: history.points.map((point) => point.open_at_start) },
                { name: 'Open at end', type: 'line', smooth: false, showSymbol: false, data: history.points.map((point) => point.open_at_end) },
                { name: 'Opened', type: 'line', smooth: false, showSymbol: false, data: history.points.map((point) => point.opened) },
                { name: 'Closed', type: 'line', smooth: false, showSymbol: false, data: history.points.map((point) => point.closed) },
            ],
        });

        root._lifecycleHistoryChart = chart;
        root.dataset.initialized = 'true';

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(() => chart.resize());
            observer.observe(plot);
            root._lifecycleHistoryResizeObserver = observer;
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeLifecycleHistoryCharts, { once: true });
} else {
    initializeLifecycleHistoryCharts();
}
document.addEventListener('livewire:navigated', initializeLifecycleHistoryCharts);
