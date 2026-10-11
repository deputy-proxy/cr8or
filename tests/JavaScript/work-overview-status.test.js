import test from 'node:test';
import assert from 'node:assert/strict';
import { getWorkStatusColor } from '../../resources/js/work-overview-status.js';
import { buildWorkOverviewTreeData, getWorkOverviewNodeEditUrl } from '../../resources/js/work-overview-tree.js';

test('maps persisted work statuses to consistent colors', () => {
    assert.equal(getWorkStatusColor('completed'), '#22c55e');
    assert.equal(getWorkStatusColor('done'), '#22c55e');
    assert.equal(getWorkStatusColor('active'), '#3b82f6');
    assert.equal(getWorkStatusColor('in_progress'), '#3b82f6');
    assert.equal(getWorkStatusColor('planned'), '#a1a1aa');
    assert.equal(getWorkStatusColor('todo'), '#a1a1aa');
    assert.equal(getWorkStatusColor('cancelled'), '#71717a');
});

test('uses a neutral fallback for unknown statuses', () => {
    assert.equal(getWorkStatusColor('custom_status'), '#a1a1aa');
    assert.equal(getWorkStatusColor(null), '#a1a1aa');
});

test('overdue presentation takes precedence over the persisted status color', () => {
    assert.equal(getWorkStatusColor('todo', true), '#ef4444');
    assert.equal(getWorkStatusColor('in_progress', true), '#ef4444');
});

test('builds tree nodes with initial, expanded, and collapsed states', () => {
    const tree = [{ name: 'root', type: 'root', children: [{ name: 'enterprise:1', type: 'organization', children: [{ name: 'project:1', type: 'planning', children: [{ name: 'group:tasks:1', type: 'group', children: [{ name: 'task:1', type: 'supporting', status: 'todo', children: [] }] }] }] }] }];
    const initial = buildWorkOverviewTreeData(tree, 'initial')[0];
    assert.equal(initial.collapsed, false);
    assert.equal(initial.children[0].collapsed, false);
    assert.equal(initial.children[0].children[0].collapsed, false);
    assert.equal(initial.children[0].children[0].children[0].collapsed, true);
    const expanded = buildWorkOverviewTreeData(tree, 'expand')[0];
    assert.equal(expanded.children[0].children[0].children[0].collapsed, false);
    const collapsed = buildWorkOverviewTreeData(tree, 'collapse')[0];
    assert.equal(collapsed.collapsed, false);
    assert.equal(collapsed.children[0].collapsed, true);
});

test('preserves overdue colors and neutral structural group colors in the tree', () => {
    const [group] = buildWorkOverviewTreeData([{ name: 'group', type: 'group', children: [{ name: 'task', type: 'supporting', status: 'todo', overdue: true, children: [] }] }]);
    assert.equal(group.itemStyle.color, '#71717a');
    assert.equal(group.children[0].itemStyle.color, '#ef4444');
});

test('only persisted record nodes expose edit navigation targets', () => {
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'organization', url: '/admin/enterprises/1/edit' }), '/admin/enterprises/1/edit');
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'supporting', url: '/admin/tasks/12/edit' }), '/admin/tasks/12/edit');
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'group', url: '/admin/tasks' }), null);
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'root', url: '/admin/tasks' }), null);
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'unknown', url: '/admin/tasks/1/edit' }), null);
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'supporting', url: '#' }), null);
    assert.equal(getWorkOverviewNodeEditUrl({ type: 'supporting' }), null);
    assert.equal(getWorkOverviewNodeEditUrl(null), null);
});