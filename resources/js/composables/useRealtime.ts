import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { onBeforeUnmount } from 'vue';

export function realtimeEnabled(): boolean {
    return echoIsConfigured();
}

/**
 * Listen to a private channel event; stops automatically when the
 * component unmounts. Returns a function to stop early.
 */
export function useChannelListener() {
    const stops: (() => void)[] = [];

    function listen<T>(
        channel: string,
        event: string,
        callback: (payload: T) => void,
    ): () => void {
        if (!echoIsConfigured()) {
            return () => {};
        }

        const subscription = echo().private(channel);
        subscription.listen(event, callback);

        const stop = () => subscription.stopListening(event, callback);
        stops.push(stop);

        return stop;
    }

    onBeforeUnmount(() => stops.forEach((stop) => stop()));

    return { listen };
}
