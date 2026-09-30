import {
    createContext,
    PropsWithChildren,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';
import type { TranslationKey } from '@/lib/i18n/en';
import { useTranslation } from '@/lib/i18n/context';

type Toast = { id: number; key: TranslationKey };

type ToastContextValue = {
    showToast: (key: TranslationKey) => void;
};

const ToastContext = createContext<ToastContextValue | null>(null);

// ToastProvider only exists inside the React tree, but the one thing that
// needs to trigger a toast right now (app.tsx's global httpException
// listener, for a 429) is registered before React ever renders. This lets
// that outside code reach in once the provider has actually mounted,
// instead of duplicating locale/state logic outside React just for one
// call site.
let externalShowToast: ((key: TranslationKey) => void) | null = null;

export function showGlobalToast(key: TranslationKey): void {
    externalShowToast?.(key);
}

let nextToastId = 0;

export function ToastProvider({ children }: PropsWithChildren) {
    const { t } = useTranslation();
    const [toasts, setToasts] = useState<Toast[]>([]);

    const showToast = useCallback((key: TranslationKey) => {
        const id = nextToastId++;
        setToasts((current) => [...current, { id, key }]);

        setTimeout(() => {
            setToasts((current) => current.filter((toast) => toast.id !== id));
        }, 4000);
    }, []);

    useEffect(() => {
        externalShowToast = showToast;

        return () => {
            externalShowToast = null;
        };
    }, [showToast]);

    return (
        <ToastContext.Provider value={{ showToast }}>
            {children}
            <div className="pointer-events-none fixed inset-x-0 bottom-6 z-50 flex flex-col items-center gap-2 px-4">
                {toasts.map((toast) => (
                    <p
                        key={toast.id}
                        role="status"
                        className="border-ink bg-accent text-accent-ink shadow-hard pointer-events-auto border-2 px-4 py-2 text-sm font-semibold"
                    >
                        {t(toast.key)}
                    </p>
                ))}
            </div>
        </ToastContext.Provider>
    );
}

export function useToast(): ToastContextValue {
    const context = useContext(ToastContext);

    if (!context) {
        throw new Error('useToast() must be used within a ToastProvider');
    }

    return context;
}
