export const broadcastStatusClasses: Record<string, string> = {
    draft: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    scheduled: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
    sending:
        'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    completed:
        'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    cancelled:
        'bg-neutral-200 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
    failed: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',
};

export function percent(part: number, total: number): string {
    return total > 0 ? `${Math.round((part / total) * 100)}%` : '—';
}

export function formatDateTime(iso: string | null | undefined): string {
    return iso
        ? new Date(iso).toLocaleString([], {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';
}
