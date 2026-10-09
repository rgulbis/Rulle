import type { ChatUser } from './types';

// Boxy initials instead of photos - every rider gets a steady colour picked
// from their id; staff always get the black-and-yellow one.
const AVATAR_COLOURS = [
    'bg-[#ffd400] text-[#16161a]',
    'bg-[#2f6fed] text-white',
    'bg-[#1f8a4c] text-white',
    'bg-[#d7261e] text-white',
    'bg-[#7c4dcc] text-white',
    'bg-[#e0701f] text-[#16161a]',
];

export function Avatar({
    user,
    size = 'md',
}: {
    user: ChatUser;
    size?: 'sm' | 'md';
}) {
    const initials = user.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
    const colour =
        user.role === 'user'
            ? AVATAR_COLOURS[user.id % AVATAR_COLOURS.length]
            : 'bg-[#16161a] text-[#ffd400] ring-2 ring-inset ring-[#ffd400]';

    return (
        <span
            aria-hidden="true"
            className={`font-display flex shrink-0 items-center justify-center font-black ${colour} ${
                size === 'sm' ? 'size-8 text-base' : 'size-10 text-xl'
            }`}
        >
            {initials || '?'}
        </span>
    );
}
