const STATUS_COLORS = {
    completed: '#22c55e',
    done: '#22c55e',
    active: '#3b82f6',
    in_progress: '#3b82f6',
    planned: '#a1a1aa',
    todo: '#a1a1aa',
    pending: '#a1a1aa',
    blocked: '#ef4444',
    failed: '#ef4444',
    error: '#ef4444',
    cancelled: '#71717a',
    canceled: '#71717a',
};

export function getWorkStatusColor(status, overdue = false) {
    if (overdue) return '#ef4444';

    return STATUS_COLORS[String(status ?? '').trim().toLowerCase()] ?? '#a1a1aa';
}