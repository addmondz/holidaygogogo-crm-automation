export type UseInitialsReturn = {
    getInitials: (fullName?: string) => string;
};

function getInitial(name: string): string {
    return Array.from(name)[0] ?? '';
}

export function getInitials(fullName?: string): string {
    if (!fullName) {
        return '';
    }

    // Ignore brackets and symbols, e.g. "Aisyah (Agent)" -> "AA".
    const names = fullName
        .replace(/[^\p{L}\p{N}\s]/gu, '')
        .trim()
        .split(/\s+/u)
        .filter(Boolean);

    if (names.length === 0) {
        return '';
    }

    if (names.length === 1) {
        return getInitial(names[0]).toUpperCase();
    }

    return `${getInitial(names[0])}${getInitial(names[names.length - 1])}`.toUpperCase();
}

export function useInitials(): UseInitialsReturn {
    return { getInitials };
}
