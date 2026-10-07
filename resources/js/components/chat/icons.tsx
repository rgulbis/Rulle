type IconProps = { size?: number };

export function MutedIcon({ size = 16 }: IconProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="square"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M15 9V5a3 3 0 0 0-5.6-1.5M9 9v3a3 3 0 0 0 4.8 2.4M12 18v3M8 21h8M3 3l18 18" />
        </svg>
    );
}

export function ReplyIcon({ size = 16 }: IconProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="square"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M9 10L4 15l5 5M4 15h10a6 6 0 0 0 6-6V7" />
        </svg>
    );
}

export function PinIcon({ size = 16 }: IconProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2.2"
            strokeLinecap="square"
            aria-hidden="true"
        >
            <path d="M9 3h6l-1 6 4 4H6l4-4zM12 13v8" />
        </svg>
    );
}

export function MoreIcon({ size = 16 }: IconProps) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <circle cx="5" cy="12" r="2.2" />
            <circle cx="12" cy="12" r="2.2" />
            <circle cx="19" cy="12" r="2.2" />
        </svg>
    );
}
