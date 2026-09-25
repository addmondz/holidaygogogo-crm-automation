import { http } from '@inertiajs/vue3';

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors: Record<string, string> = {},
    ) {
        super(message);
    }
}

/**
 * JSON requests for chat actions (sending messages, loading history).
 * Uses Inertia's HTTP client, which adds the CSRF token.
 */
export async function api<T>(
    method: Method,
    url: string,
    data?: Record<string, unknown> | FormData,
): Promise<T> {
    try {
        const response = await http.getClient().request({
            method,
            url,
            data,
            headers: { Accept: 'application/json' },
        });

        return (response.data ? JSON.parse(response.data) : null) as T;
    } catch (error: any) {
        const response = error?.response;

        if (!response) {
            throw new ApiError(
                'Network error. Check your connection and try again.',
                0,
            );
        }

        let body: any = {};

        try {
            body =
                typeof response.data === 'string'
                    ? JSON.parse(response.data)
                    : response.data;
        } catch {
            body = {};
        }

        const errors: Record<string, string> = {};

        for (const [key, value] of Object.entries(body?.errors ?? {})) {
            errors[key] = Array.isArray(value)
                ? String(value[0])
                : String(value);
        }

        const message =
            Object.values(errors)[0] ??
            body?.message ??
            (response.status === 419
                ? 'Your session expired. Please refresh the page.'
                : 'Something went wrong. Please try again.');

        throw new ApiError(message, response.status, errors);
    }
}
