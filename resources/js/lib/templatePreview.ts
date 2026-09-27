import type { WhatsappTemplate } from '@/types';

/**
 * Preview of a WhatsApp template with the typed values filled in.
 * Placeholders like {first_name} are shown as-is (filled when sending).
 */
export function renderTemplatePreview(
    template: WhatsappTemplate,
    values: Record<string, string>,
): string {
    const parts: string[] = [];

    for (const component of template.components) {
        const type = component.type.toUpperCase();
        const section =
            type === 'HEADER'
                ? 'header'
                : type === 'BODY'
                  ? 'body'
                  : type === 'FOOTER'
                    ? 'footer'
                    : null;

        if (section && component.text) {
            const text = component.text.replace(
                /\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g,
                (match, name: string) => values[`${section}.${name}`] || match,
            );
            parts.push(type === 'HEADER' ? `*${text}*` : text);
        }

        if (section === 'header' && !component.text && component.format) {
            parts.push(`[${component.format.toLowerCase()}]`);
        }

        if (type === 'BUTTONS' && component.buttons?.length) {
            parts.push(component.buttons.map((b) => `[${b.text}]`).join(' '));
        }
    }

    return parts.join('\n\n');
}
