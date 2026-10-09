import { Link } from '@inertiajs/react';
import {
    ButtonHTMLAttributes,
    ComponentProps,
    InputHTMLAttributes,
    LabelHTMLAttributes,
    ReactNode,
    SelectHTMLAttributes,
    useState,
} from 'react';
import { useTranslation } from '@/lib/i18n/context';
import { cn } from '@/lib/utils';

export function Label({
    className = '',
    ...props
}: LabelHTMLAttributes<HTMLLabelElement>) {
    return (
        <label
            {...props}
            className={`text-ink font-mono text-xs font-semibold tracking-[0.12em] uppercase ${className}`}
        />
    );
}

// Boxy field that "lifts" onto a yellow hard shadow when focused - the
// same pressed/raised language the buttons use.
const inputClasses =
    'w-full rounded-none border-2 border-ink bg-paper px-4 py-3 text-base text-ink shorter:py-2 outline-none transition placeholder:text-faint focus:-translate-x-0.5 focus:-translate-y-0.5 focus:shadow-hard-accent disabled:opacity-50 aria-invalid:border-danger';

export function TextInput({
    className = '',
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return <input {...props} className={cn(inputClasses, className)} />;
}

export function Select({
    className = '',
    children,
    ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
    return (
        <div className="relative">
            {/* A native <select> keeps its own OS chrome in Safari no matter
            what border/background is set on it (unlike Chromium, which
            mostly defers to custom styles) - appearance-none drops that
            chrome everywhere so the box actually looks like our other
            inputs, and this draws the dropdown arrow back in by hand. */}
            <select
                {...props}
                className={cn(inputClasses, 'appearance-none pr-11', className)}
            >
                {children}
            </select>
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                className={`pointer-events-none absolute top-1/2 right-4 size-4 -translate-y-1/2 ${props.disabled ? 'opacity-50' : ''}`}
                fill="none"
                stroke="currentColor"
                strokeWidth="2.5"
                strokeLinecap="square"
            >
                <path d="M6 9l6 6 6-6" />
            </svg>
        </div>
    );
}

export function PasswordInput({
    className = '',
    ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
    const { t } = useTranslation();
    const [visible, setVisible] = useState(false);

    return (
        <div className="relative">
            <input
                {...props}
                type={visible ? 'text' : 'password'}
                className={`${inputClasses} pr-14 ${className}`}
            />
            <button
                type="button"
                onClick={() => setVisible((v) => !v)}
                aria-label={
                    visible ? t('form.hidePassword') : t('form.showPassword')
                }
                aria-pressed={visible}
                className="text-ink absolute top-1/2 right-1 flex size-11 -translate-y-1/2 items-center justify-center"
            >
                {visible ? <EyeOffIcon /> : <EyeIcon />}
            </button>
        </div>
    );
}

export function Checkbox({
    label,
    className = '',
    ...props
}: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> & { label: ReactNode }) {
    return (
        <label
            className={`flex min-h-11 cursor-pointer items-center gap-3 text-base ${className}`}
        >
            <input {...props} type="checkbox" className="accent-ink size-5" />
            {label}
        </label>
    );
}

export function InputError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="text-danger text-sm font-medium">{message}</p>;
}

type Tone = 'success' | 'info' | 'warning' | 'error';

const toneClasses: Record<Tone, string> = {
    success: 'bg-ok-fill text-[#0e0e10]',
    info: 'bg-paper text-ink',
    warning: 'bg-accent text-accent-ink',
    error: 'bg-danger-fill text-white',
};

export function StatusMessage({
    status,
    tone = 'success',
    className = '',
}: {
    status?: string | null;
    tone?: Tone;
    className?: string;
}) {
    if (!status) {
        return null;
    }

    return (
        <p
            role={tone === 'error' ? 'alert' : 'status'}
            className={`border-ink shadow-hard mb-4 border-2 px-4 py-3 text-base font-medium ${toneClasses[tone]} ${className}`}
        >
            {status}
        </p>
    );
}

type Variant = 'primary' | 'accent' | 'secondary' | 'ghost' | 'danger';

// Every solid button sits on a hard shadow and presses flat when clicked.
const buttonBase =
    'inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-none border-2 px-5 py-3 text-base font-semibold whitespace-nowrap transition hover:-translate-x-0.5 hover:-translate-y-0.5 active:translate-x-1 active:translate-y-1 active:shadow-none disabled:pointer-events-none disabled:opacity-50';

const variantClasses: Record<Variant, string> = {
    primary: 'border-ink bg-ink text-ground shadow-hard-accent',
    accent: 'border-ink bg-accent text-accent-ink shadow-hard',
    secondary: 'border-ink bg-paper text-ink shadow-hard',
    ghost: 'border-transparent bg-transparent text-ink underline underline-offset-4 shadow-none hover:translate-x-0 hover:translate-y-0 active:translate-x-0 active:translate-y-0',
    danger: 'border-danger bg-transparent text-danger shadow-none hover:translate-x-0 hover:translate-y-0 active:translate-x-0 active:translate-y-0',
};

export function buttonClasses(variant: Variant = 'primary') {
    return `${buttonBase} ${variantClasses[variant]}`;
}

export function Button({
    variant = 'primary',
    className = '',
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant }) {
    return (
        <button
            type="button"
            {...props}
            className={cn(buttonClasses(variant), className)}
        />
    );
}

// Kept for the existing pages that import it: the main call-to-action.
export function PrimaryButton(props: ButtonHTMLAttributes<HTMLButtonElement>) {
    return <Button variant="primary" {...props} />;
}

export function LinkButton({
    variant = 'primary',
    className = '',
    ...props
}: ComponentProps<typeof Link> & { variant?: Variant }) {
    return (
        <Link {...props} className={cn(buttonClasses(variant), className)} />
    );
}

export function AuthLink(props: ComponentProps<typeof Link>) {
    return (
        <Link
            {...props}
            className={`text-ink hover:decoration-accent font-semibold underline decoration-2 underline-offset-4 ${props.className ?? ''}`}
        />
    );
}

/** Small monospace label above a heading or a number. */
export function Eyebrow({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            className={`font-mono text-xs font-semibold tracking-[0.12em] uppercase ${className}`}
        >
            {children}
        </p>
    );
}

export function PageHeader({
    title,
    description,
    eyebrow,
    children,
}: {
    title: ReactNode;
    description?: ReactNode;
    eyebrow?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div className="flex max-w-2xl flex-col gap-3">
                {eyebrow && <Eyebrow className="text-muted">{eyebrow}</Eyebrow>}
                <h1 className="font-display text-ink text-5xl leading-none font-black uppercase sm:text-7xl">
                    {title}
                </h1>
                {description && (
                    <p className="text-muted text-lg">{description}</p>
                )}
            </div>
            {children}
        </div>
    );
}

export function Card({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={`border-ink bg-paper border-2 p-6 ${className}`}>
            {children}
        </div>
    );
}

function EyeIcon() {
    return (
        <svg
            width="22"
            height="22"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="square"
            aria-hidden="true"
        >
            <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" />
            <path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z" />
        </svg>
    );
}

function EyeOffIcon() {
    return (
        <svg
            width="22"
            height="22"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="square"
            aria-hidden="true"
        >
            <path d="M2 12s4-7 10-7c2 0 3.8.8 5.3 1.8M22 12s-4 7-10 7c-2 0-3.8-.8-5.3-1.8" />
            <path d="M3 3l18 18" />
        </svg>
    );
}
