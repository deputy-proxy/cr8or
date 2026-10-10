import test from 'node:test';
import assert from 'node:assert/strict';
import { getPortfolioStatusColor, normalizeEnterpriseGraphData } from '../../resources/js/enterprise-portfolio-data.js';

test('portfolio uses shared semantic status colors with a neutral fallback', () => {
    assert.equal(getPortfolioStatusColor('active'), '#3b82f6');
    assert.equal(getPortfolioStatusColor('published'), '#22c55e');
    assert.equal(getPortfolioStatusColor('failed'), '#ef4444');
    assert.equal(getPortfolioStatusColor('unknown_status'), '#a1a1aa');
    assert.equal(getPortfolioStatusColor('active', true), '#ef4444');
});

test('graph normalization removes edges to nodes outside the authorized node set', () => {
    const graph = normalizeEnterpriseGraphData({
        nodes: [{ id: 'enterprise:1', enterpriseId: 1, name: 'Visible', status: 'active' }],
        links: [
            { source: 'enterprise:1', target: 'enterprise:1', type: 'supports' },
            { source: 'enterprise:1', target: 'enterprise:999', type: 'related_to' },
        ],
    });
    assert.equal(graph.nodes.length, 1);
    assert.equal(graph.links.length, 1);
    assert.equal(graph.links[0].target, 'enterprise:1');
});

test('graph normalization returns stable primitive fields for ECharts', () => {
    const graph = normalizeEnterpriseGraphData({
        nodes: [{ id: 'enterprise:1', enterpriseId: '1', name: '<script>', group: null, category: null }],
        links: [{ source: 'enterprise:1', target: 'enterprise:1', direction: 'bidirectional' }],
    });
    assert.deepEqual(graph.nodes[0], {
        id: 'enterprise:1',
        enterpriseId: 1,
        name: '<script>',
        group: '',
        category: '',
        status: '',
    });
    assert.equal(graph.links[0].type, 'related_to');
});
