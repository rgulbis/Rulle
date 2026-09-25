import { createInertiaApp } from '@inertiajs/react';
import { LocaleProvider } from '@/lib/i18n/context';
import './echo';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    // Every page needs the current language before it renders (nav labels,
    // status text, etc.), so this wraps the whole app rather than each
    // layout wrapping its own — a page can't call useTranslation() itself
    // if the provider only exists inside the layout IT renders.
    withApp: (app) => <LocaleProvider>{app}</LocaleProvider>,
});
