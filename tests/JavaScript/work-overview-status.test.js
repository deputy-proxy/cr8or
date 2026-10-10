import test from 'node:test';
import assert from 'node:assert/strict';
import { getWorkStatusColor } from '../../resources/js/work-overview-status.js';

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