import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// Teach tailwind-merge about the custom hard shadows in app.css, so e.g.
// `shadow-none` passed in a className actually replaces a button's default
// `shadow-hard-accent` instead of both ending up in the class list.
const twMerge = extendTailwindMerge({
    extend: {
        classGroups: {
            shadow: [
                {
                    shadow: [
                        'hard',
                        'hard-lg',
                        'hard-accent',
                        'hard-accent-lg',
                        'hard-paper',
                    ],
                },
            ],
        },
    },
});

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
