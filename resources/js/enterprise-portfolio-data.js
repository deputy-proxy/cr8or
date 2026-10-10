import { getWorkStatusColor } from './work-overview-status.js';

export function getPortfolioStatusColor(status, overdue = false) {
    return getWorkStatusColor(status, overdue);
}

export function normalizeEnterpriseGraphData(data) {
    const nodes = (Array.isArray(data?.nodes) ? data.nodes : [])
        .filter((node) => node && typeof node.id === 'string' && Number.isInteger(Number(node.enterpriseId)) && Number(node.enterpriseId) > 0)
        .map((node) => ({
            id: node.id,
            enterpriseId: Number(node.enterpriseId),
            name: String(node.name ?? ''),
            group: String(node.group ?? ''),
            category: String(node.category ?? ''),
            status: String(node.status ?? ''),
        }));
    const ids = new Set(nodes.map((node) => node.id));
    const links = (Array.isArray(data?.links) ? data.links : [])
        .filter((link) => link && ids.has(String(link.source)) && ids.has(String(link.target)))
        .map((link) => ({
            source: String(link.source),
            target: String(link.target),
            type: String(link.type ?? 'related_to'),
            description: String(link.description ?? ''),
        }));

    return { nodes, links };
}
