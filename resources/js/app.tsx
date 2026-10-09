import { createInertiaApp, router } from '@inertiajs/react';
import { LocaleProvider } from '@/lib/i18n/context';
import { showGlobalToast, ToastProvider } from '@/lib/toast';
import './echo';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// A failed in-page action (a POST/DELETE that gets a 403, 429, 5xx...) comes
// back as a plain error page, not an Inertia response - Inertia's default for
// that is to pop up a dialog showing that raw page. A dialog is too jarring
// (and shows Laravel's unstyled markup), so it's replaced with a quieter toast
// - enough feedback that the click wasn't just silently swallowed, without
// interrupting whatever they were doing. Full page visits never get here: the
// server answers those with the styled error page instead.
router.on('httpException', (event) => {
    const { status, headers } = event.detail.response;

    // Inertia fires this for Inertia responses with an error status too -
    // which is exactly the styled error page the server sends for a failed
    // page visit. Let that render instead of hiding it behind a toast.
    if (headers['x-inertia']) {
        return;
    }

    if (status === 429) {
        showGlobalToast('toast.tooManyRequests');
    } else if (status === 403) {
        showGlobalToast('toast.forbidden');
    } else if (status >= 500 || status === 419) {
        showGlobalToast('toast.requestFailed');
    } else {
        return;
    }

    event.preventDefault();
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
    // Every page needs the current language before it renders (nav labels,
    // status text, etc.), so this wraps the whole app rather than each
    // layout wrapping its own - a page can't call useTranslation() itself
    // if the provider only exists inside the layout IT renders. ToastProvider
    // goes inside it for the same reason (toast text needs translating).
    withApp: (app, { page }) => (
        <LocaleProvider initialLocale={page.props.locale}>
            <ToastProvider>{app}</ToastProvider>
        </LocaleProvider>
    ),
});
