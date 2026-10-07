export type ChatUser = {
    id: number;
    name: string;
    role: string;
};

export type ChatMessage = {
    id: number;
    body: string;
    created_at: string;
    pinned: boolean;
    user: ChatUser;
    reply_to: { id: number; body: string; user: { name: string } } | null;
};

export type SlowMode = {
    remaining_seconds: number;
    cooldown_seconds: number;
};

export type MutedUser = {
    id: number;
    name: string;
    chat_muted_until: string;
};

export type Moderation = {
    deleteUrl: (messageId: number) => string;
    muteUrl: (userId: number) => string;
    unmuteUrl: (userId: number) => string;
    pinUrl: (messageId: number) => string;
};
