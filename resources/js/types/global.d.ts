import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            activeOrganization: {
                id: number;
                name: string;
                timezone: string;
                role: string;
            } | null;
            organizations: { id: number; name: string; role: string }[];
            sidebarOpen: boolean;
            notificationsUnreadCount: number;
            [key: string]: unknown;
        };
    }
}
