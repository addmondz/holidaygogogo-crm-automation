let audioContext: AudioContext | null = null;

/**
 * A short two-tone "ding", generated so no sound file is needed.
 */
export function playNotificationSound(): void {
    try {
        audioContext ??= new AudioContext();
        const now = audioContext.currentTime;

        [880, 1320].forEach((frequency, i) => {
            const oscillator = audioContext!.createOscillator();
            const gain = audioContext!.createGain();
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, now + i * 0.12);
            gain.gain.exponentialRampToValueAtTime(0.15, now + i * 0.12 + 0.01);
            gain.gain.exponentialRampToValueAtTime(
                0.0001,
                now + i * 0.12 + 0.2,
            );
            oscillator.connect(gain).connect(audioContext!.destination);
            oscillator.start(now + i * 0.12);
            oscillator.stop(now + i * 0.12 + 0.22);
        });
    } catch {
        // Browsers block audio until the page has been clicked once.
    }
}

export function desktopNotificationsSupported(): boolean {
    return typeof window !== 'undefined' && 'Notification' in window;
}

export function showDesktopNotification(
    title: string,
    body: string,
    onClick?: () => void,
): void {
    if (
        !desktopNotificationsSupported() ||
        Notification.permission !== 'granted' ||
        !document.hidden
    ) {
        return;
    }

    const notification = new Notification(title, {
        body,
        icon: '/favicon.ico',
    });

    notification.onclick = () => {
        window.focus();
        onClick?.();
        notification.close();
    };
}
