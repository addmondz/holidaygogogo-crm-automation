const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

/**
 * "just now", "5 min ago", "yesterday", or a date for anything older than a week.
 */
export function relativeTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 45) {
        return 'just now';
    }

    if (abs < 3600) {
        return rtf.format(Math.round(seconds / 60), 'minute');
    }

    if (abs < 86400) {
        return rtf.format(Math.round(seconds / 3600), 'hour');
    }

    if (abs < 7 * 86400) {
        return rtf.format(Math.round(seconds / 86400), 'day');
    }

    return date.toLocaleDateString();
}

/**
 * Short timestamp for the chat list: time today, weekday this week, else date.
 */
export function listTime(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const date = new Date(iso);
    const now = new Date();

    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    if (now.getTime() - date.getTime() < 6 * 86400 * 1000) {
        return date.toLocaleDateString([], { weekday: 'short' });
    }

    return date.toLocaleDateString([], { day: 'numeric', month: 'short' });
}

export function messageTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function dayLabel(iso: string): string {
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
        return 'Today';
    }

    if (date.toDateString() === yesterday.toDateString()) {
        return 'Yesterday';
    }

    return date.toLocaleDateString([], {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}

export function formatPhone(phone: string | null | undefined): string {
    return phone ? `+${phone}` : '';
}

/**
 * Hours and minutes left, e.g. "5h 12m left".
 */
export function timeLeft(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.round((new Date(iso).getTime() - Date.now()) / 60000),
    );
    const hours = Math.floor(minutes / 60);

    return hours > 0 ? `${hours}h ${minutes % 60}m left` : `${minutes}m left`;
}
