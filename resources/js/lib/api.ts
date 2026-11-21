/** Thin wrapper over fetch for the Laravel API (same origin, session cookie, CSRF). */

export class ApiError extends Error {
    constructor(
        public status: number,
        message: string,
        public code?: string,
        public fieldErrors: Record<string, string[]> = {},
        /** The whole error answer, for rule refusals that carry extra details. */
        public payload: Record<string, unknown> = {},
    ) {
        super(message);
    }

    /** First error for a field, e.g. errors.first('gstin'). */
    first(field: string): string | undefined {
        return this.fieldErrors[field]?.[0];
    }
}

/** No answer from the server at all: internet or server is down. */
export class NetworkError extends Error {}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

type Options = {
    method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
    body?: unknown;
    /** Sent with requests that create something, so a retry never creates it twice. */
    idempotencyKey?: string;
};

async function send(path: string, options: Options): Promise<Response> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-XSRF-TOKEN': csrfToken(),
    };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    if (options.idempotencyKey) headers['Idempotency-Key'] = options.idempotencyKey;

    try {
        return await fetch(`/api/v1${path}`, {
            method: options.method ?? 'GET',
            headers,
            credentials: 'same-origin',
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
        });
    } catch {
        throw new NetworkError('No connection');
    }
}

let onSignedOut: (() => void) | null = null;

/** The app registers what to do when the server says the session has ended (expired, or the user was switched off). */
export function setSignedOutHandler(handler: (() => void) | null): void {
    onSignedOut = handler;
}

export async function api<T>(path: string, options: Options = {}): Promise<T> {
    let response = await send(path, options);

    // Session token went stale (page open for a long time): refresh it once and retry.
    if (response.status === 419) {
        try {
            await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
        } catch {
            throw new NetworkError('No connection');
        }
        response = await send(path, options);
    }

    if (response.status === 401 && path !== '/auth/me' && path !== '/auth/login') onSignedOut?.();

    if (response.status === 204) return undefined as T;

    const data = await response.json().catch(() => null);

    if (!response.ok) {
        throw new ApiError(response.status, data?.message ?? 'Something went wrong', data?.code, data?.errors ?? {}, data ?? {});
    }

    return data as T;
}

/** Unique key for one "create" action. Reuse the same key when retrying the same action. */
export function newIdempotencyKey(): string {
    return crypto.randomUUID();
}
