import { SVGProps } from 'react';

// Square-capped stroke icons, drawn to match the boxy look. All decorative:
// whatever they sit next to carries the actual label.
function Icon({
    size = 20,
    strokeWidth = 2.2,
    children,
    ...props
}: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={strokeWidth}
            strokeLinecap="square"
            aria-hidden="true"
            {...props}
        >
            {children}
        </svg>
    );
}

export function ArrowRightIcon(
    props: SVGProps<SVGSVGElement> & { size?: number },
) {
    return (
        <Icon {...props}>
            <path d="M5 12h14M13 6l6 6-6 6" />
        </Icon>
    );
}

export function CheckIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon strokeWidth={2.6} {...props}>
            <path d="M4 12l5 5L20 6" />
        </Icon>
    );
}

export function CrossIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon strokeWidth={2.6} {...props}>
            <path d="M6 6l12 12M18 6L6 18" />
        </Icon>
    );
}

export function MenuIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon {...props}>
            <path d="M4 7h16M4 12h16M4 17h16" />
        </Icon>
    );
}

export function EntryIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon {...props}>
            <path d="M3 12h13M11 6l6 6-6 6M21 4v16" />
        </Icon>
    );
}

export function ExitIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon {...props}>
            <path d="M8 12h13M17 6l4 6-4 6M3 4v16" />
        </Icon>
    );
}

export function QrIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon strokeWidth={2} {...props}>
            <path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM19 19h2v2h-2zM6 6h1v1H6zM17 6h1v1h-1zM6 17h1v1H6z" />
        </Icon>
    );
}

export function MailIcon(props: SVGProps<SVGSVGElement> & { size?: number }) {
    return (
        <Icon strokeWidth={2} {...props}>
            <path d="M3 5h18v14H3z" />
            <path d="M3 6l9 7 9-7" />
        </Icon>
    );
}
