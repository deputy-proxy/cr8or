import { getWorkStatusColor } from './work-overview-status.js';

const TYPE_COLORS = {
    organization: '#a1a1aa',
    planning: '#3b82f6',
    work: '#22c55e',
    supporting: '#f59e0b',
    governance: '#a855f7',
    group: '#71717a',
    root: 'transparent',
};

function collapsedFor(node, mode, hasChildren) {
    if (!hasChildren) return undefined;
    if (node.type === 'root') return false;
    if (mode === 'expand') return false;
    if (mode === 'collapse') return true;
    if (node.type === 'organization' || node.type === 'planning') return false;
    return true;
}

/**
 * Produce fresh ECharts tree nodes for the requested expansion state.
 * Ancestor tracking prevents malformed cyclic input from causing infinite recursion.
 */
export function buildWorkOverviewTreeData(tree, mode = 'initial') {
    const visit = (node, ancestors = new Set()) => {
        if (!node || typeof node.name !== 'string' || ancestors.has(node.name)) return null;

        const nextAncestors = new Set(ancestors);
        nextAncestors.add(node.name);
        const children = (Array.isArray(node.children) ? node.children : [])
            .map((child) => visit(child, nextAncestors))
            .filter(Boolean);
        const hasChildren = children.length > 0;
        const color = node.type === 'group'
            ? TYPE_COLORS.group
            : node.status
                ? getWorkStatusColor(node.status, node.overdue)
                : TYPE_COLORS[node.type] || '#71717a';

        const result = {
            ...node,
            children,
            itemStyle: {
                ...(node.type === 'root' ? { opacity: 0 } : {}),
                color,
                borderWidth: 0,
            },
            symbol: node.type === 'root' ? 'none' : 'circle',
            symbolSize: node.type === 'root' ? 0 : (hasChildren ? 9 : 7),
        };

        const collapsed = collapsedFor(node, mode, hasChildren);
        if (collapsed !== undefined) result.collapsed = collapsed;
        else delete result.collapsed;

        return result;
    };

    return (Array.isArray(tree) ? tree : []).map((node) => visit(node)).filter(Boolean);
}

/**
 * Return an edit URL only for persisted records. Structural nodes intentionally
 * have no navigation target, even if malformed input happens to include one.
 */
export function getWorkOverviewNodeEditUrl(node) {
    const recordTypes = ['organization', 'planning', 'work', 'supporting', 'governance'];

    if (!node || !recordTypes.includes(node.type)) return null;
    if (typeof node.url !== 'string' || node.url.trim() === '' || node.url === '#') return null;

    return node.url;
}