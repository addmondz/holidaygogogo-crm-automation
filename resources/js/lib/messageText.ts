import type { Contact } from '@/types';

function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Safe HTML for a chat bubble: escapes everything, then applies WhatsApp
 * formatting (*bold*, _italic_, ~strike~) and turns links into anchors.
 */
export function formatMessage(text: string | null | undefined): string {
    if (!text) {
        return '';
    }

    return escapeHtml(text)
        .replace(
            /(https?:\/\/[^\s<]+[^\s<.,:;"')\]!?])/g,
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="underline break-all">$1</a>',
        )
        .replace(/(^|\s)\*([^*\n]+)\*(?=\s|$|[.,!?])/g, '$1<strong>$2</strong>')
        .replace(/(^|\s)_([^_\n]+)_(?=\s|$|[.,!?])/g, '$1<em>$2</em>')
        .replace(/(^|\s)~([^~\n]+)~(?=\s|$|[.,!?])/g, '$1<del>$2</del>');
}

/**
 * Same placeholders as the server: {name}, {first_name}, {phone},
 * {agent_name}, with an optional fallback: {first_name|there}.
 */
export function fillPlaceholders(
    text: string,
    contact: Contact | null,
    agentName: string,
): string {
    const firstName = contact?.name?.trim().split(/\s+/)[0] ?? '';

    const values: Record<string, string> = {
        name: contact?.name ?? '',
        first_name: firstName,
        phone: contact?.phone ? `+${contact.phone}` : '',
        agent_name: agentName,
    };

    return text.replace(
        /\{(name|first_name|phone|agent_name)(?:\|([^}]*))?\}/g,
        (_, key: string, fallback?: string) => values[key] || fallback || '',
    );
}
