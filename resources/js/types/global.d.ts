import type { Locale } from '@/lib/i18n/context';
import type { Auth } from '@/types/auth';
import type { Park } from '@/types/park';

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
            park: Park;
            locale: Locale | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
