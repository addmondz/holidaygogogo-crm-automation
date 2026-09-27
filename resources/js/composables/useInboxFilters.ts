import { router } from '@inertiajs/vue3';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';

export type InboxFilters = {
    folder: 'all' | 'mine' | 'unassigned';
    status: 'open' | 'closed';
    channel: number | null;
    tag: number | null;
    agent: number | null;
    q: string;
    limit: number;
};

const DEFAULT_LIMIT = 50;

/**
 * Only non-default filters go in the URL, so links stay short.
 */
export function filterQuery(
    filters: InboxFilters,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.folder !== 'all') query.folder = filters.folder;
    if (filters.status !== 'open') query.status = filters.status;
    if (filters.channel) query.channel = filters.channel;
    if (filters.tag) query.tag = filters.tag;
    if (filters.agent) query.agent = filters.agent;
    if (filters.q) query.q = filters.q;
    if (filters.limit > DEFAULT_LIMIT) query.limit = filters.limit;

    return query;
}

export function conversationUrl(id: number, filters: InboxFilters): string {
    return InboxController.show(id, { query: filterQuery(filters) }).url;
}

/**
 * Apply new filters, keeping the open chat (if any) selected.
 */
export function applyFilters(
    filters: InboxFilters,
    selectedId: number | null,
): void {
    const query = filterQuery(filters);
    const url = selectedId
        ? InboxController.show(selectedId, { query }).url
        : InboxController.index({ query }).url;

    router.visit(url, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['conversations', 'counts', 'hasMore', 'filters'],
    });
}
