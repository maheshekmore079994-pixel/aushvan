import { useQuery, useQueryClient } from '@tanstack/react-query';
import { createContext, useContext, useEffect, type ReactNode } from 'react';
import { api, ApiError, NetworkError, setSignedOutHandler } from '@/lib/api';
import i18n, { setLanguage, type LanguageCode } from '@/lib/i18n';

export type Profile = {
    id: number;
    name: string;
    username: string;
    locale: LanguageCode;
    is_pharmacist: boolean;
    roles: string[];
    permissions: string[];
    store: { id: number; name: string; village: string | null };
};

type Session = {
    user: Profile;
    can: (permission: string) => boolean;
};

const SessionContext = createContext<Session | null>(null);

/** The signed-in user. Only usable inside the signed-in part of the app. */
export function useSession(): Session {
    const session = useContext(SessionContext);
    if (!session) throw new Error('useSession used outside a signed-in screen');
    return session;
}

export function SessionProvider({ user, children }: { user: Profile; children: ReactNode }) {
    // The user's saved language wins over what this PC remembered.
    useEffect(() => {
        if (i18n.language !== user.locale) setLanguage(user.locale);
    }, [user.locale]);

    // Any request answered "not signed in" (session ran out over lunch, login switched off) shows the sign-in screen.
    const queryClient = useQueryClient();
    useEffect(() => {
        setSignedOutHandler(() => queryClient.setQueryData(meKey, null));
        return () => setSignedOutHandler(null);
    }, [queryClient]);

    const can = (permission: string) => user.permissions.includes(permission);

    return <SessionContext.Provider value={{ user, can }}>{children}</SessionContext.Provider>;
}

export const meKey = ['auth', 'me'] as const;
export const statusKey = ['app', 'status'] as const;

/** null = not signed in. */
export function useMe() {
    return useQuery({
        queryKey: meKey,
        queryFn: async (): Promise<Profile | null> => {
            try {
                return await api<Profile>('/auth/me');
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) return null;
                throw error;
            }
        },
        retry: false,
        staleTime: 5 * 60 * 1000,
    });
}

export function useAppStatus() {
    return useQuery({
        queryKey: statusKey,
        queryFn: () => api<{ needs_setup: boolean; languages: string[] }>('/app/status'),
        staleTime: Infinity,
    });
}

export function useSignedIn() {
    const queryClient = useQueryClient();

    return (profile: Profile) => {
        queryClient.setQueryData(statusKey, (old: { needs_setup: boolean } | undefined) => ({ ...old, needs_setup: false }));
        queryClient.setQueryData(meKey, profile);
    };
}

export function useSignOut() {
    const queryClient = useQueryClient();

    return async () => {
        await api('/auth/logout', { method: 'POST' }).catch((error) => {
            if (error instanceof NetworkError) throw error; // not signed out on the server: say so, do not pretend
        });
        // Mark "nobody signed in" on the query the app is watching, so the sign-in screen shows at once.
        // (Clearing the whole cache first detached the app from that query and nothing happened on screen.)
        queryClient.setQueryData(meKey, null);
        // Then drop everything cached for the previous user.
        queryClient.removeQueries({ predicate: (query) => query.queryKey[0] !== meKey[0] && query.queryKey[0] !== statusKey[0] });
    };
}
