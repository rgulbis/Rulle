import { createInertiaApp, router } from '@inertiajs/react';
import { LocaleProvider } from '@/lib/i18n/context';
import { showGlobalToast, ToastProvider } from '@/lib/toast';
import './echo';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// A rate-limited request (429, e.g. someone spam-clicking) comes back as a
// plain error page, not an Inertia response — Inertia's default for that is
// to pop up a dialog showing that raw page. A dialog is too jarring for a
// rate limit specifically, so it's replaced with a quieter toast instead —
// enough feedback that the click wasn't just silently swallowed, without
// interrupting whatever they were doing.
router.on('httpException', (event) => {
    if (event.detail.response.status === 429) {
        event.preventDefault();
        showGlobalToast('toast.tooManyRequests');
    }
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    // Every page needs the current language before it renders (nav labels,
    // status text, etc.), so this wraps the whole app rather than each
    // layout wrapping its own — a page can't call useTranslation() itself
    // if the provider only exists inside the layout IT renders. ToastProvider
    // goes inside it for the same reason (toast text needs translating).
    withApp: (app, { page }) => (
        <LocaleProvider initialLocale={page.props.locale}>
            <ToastProvider>{app}</ToastProvider>
        </LocaleProvider>
    ),
});
