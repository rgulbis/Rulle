import { router } from '@inertiajs/react';
import {
    FormEventHandler,
    KeyboardEventHandler,
    RefObject,
    useState,
} from 'react';
import { ArrowRightIcon, CrossIcon } from '@/components/icons';
import { useTranslation } from '@/lib/i18n/context';
import type { SlowModeState } from './hooks';
import type { ChatMessage } from './types';

const MAX_MESSAGE_LENGTH = 500;

type Props = {
    title: string;
    postUrl: string;
    /** The conversation is over: shown, but nothing more can be posted. */
    readOnly: boolean;
    muted: boolean;
    mutedUntil: string | null;
    isStaff: boolean;
    slow: SlowModeState;
    replyingTo: ChatMessage | null;
    onCancelReply: () => void;
    textareaRef: RefObject<HTMLTextAreaElement | null>;
};

/** The slow-mode banner, the message box (or why there isn't one) and its hints. */
export function Composer({
    title,
    postUrl,
    readOnly,
    muted,
    mutedUntil,
    isStaff,
    slow,
    replyingTo,
    onCancelReply,
    textareaRef,
}: Props) {
    const { t, intlLocale } = useTranslation();
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // The composer grows with what's typed, up to a few lines.
    const resize = () => {
        const el = textareaRef.current;

        if (el) {
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
        }
    };

    const send = () => {
        if (!body.trim() || slow.cooldownRemaining > 0) {
            return;
        }

        setSending(true);

        router.post(
            postUrl,
            { body, reply_to_message_id: replyingTo?.id ?? null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBody('');
                    setError(null);
                    onCancelReply();
                    requestAnimationFrame(() => {
                        resize();
                        textareaRef.current?.focus();
                    });
                    slow.afterSend();
                },
                onError: (errors) => {
                    // Slow mode: count the server's wait down live (the
                    // send button and the note below both follow it) rather
                    // than showing a fixed number that goes out of date.
                    const wait = Number(errors.slow_mode_wait);

                    if (wait > 0) {
                        slow.startCooldown(wait);
                        setError(null);

                        return;
                    }

                    setError(errors.body ?? t('chatThread.sendFailed'));
                },
                onFinish: () => setSending(false),
            },
        );
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send();
    };

    const handleKeyDown: KeyboardEventHandler<HTMLTextAreaElement> = (e) => {
        // Enter sends, Shift+Enter inserts a newline - the usual chat
        // convention, since this is a multi-line composer.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();

            if (!sending) {
                send();
            }
        }
    };

    return (
        <div className="px-4 pt-1 pb-4 lg:px-6">
            {slow.active && (
                <p className="border-ink bg-accent text-accent-ink border-2 border-b-0 px-4 py-1.5 text-sm font-medium">
                    {isStaff
                        ? t('chatThread.slowModeStaff')
                        : t('chatThread.slowModeCustomer', {
                              seconds: slow.cooldownSeconds,
                          })}
                </p>
            )}

            {readOnly ? (
                <p className="border-ink text-muted border-2 px-4 py-3 text-base font-medium">
                    {t('chatThread.readOnly')}
                </p>
            ) : muted ? (
                <p className="border-danger text-danger border-2 px-4 py-3 text-base font-medium">
                    {mutedUntil
                        ? t('chatThread.mutedUntil', {
                              date: new Date(mutedUntil).toLocaleString(
                                  intlLocale,
                              ),
                          })
                        : t('chatThread.muted')}
                </p>
            ) : (
                <>
                    {replyingTo && (
                        <div className="border-ink bg-paper flex items-center gap-2 border-2 border-b-0 px-3 py-2">
                            <div className="min-w-0 flex-1">
                                <p className="text-muted font-mono text-[11px] font-semibold tracking-[0.08em] uppercase">
                                    {t('chatThread.replyingTo', {
                                        name: replyingTo.user.name,
                                    })}
                                </p>
                                <p className="truncate text-sm">
                                    {replyingTo.body}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={onCancelReply}
                                aria-label={t('chatThread.cancelReply')}
                                className="flex size-8 shrink-0 items-center justify-center"
                            >
                                <CrossIcon size={16} />
                            </button>
                        </div>
                    )}
                    <form
                        onSubmit={submit}
                        className="border-ink bg-paper focus-within:shadow-hard-accent flex items-end gap-2 border-2 p-1.5 transition"
                    >
                        <textarea
                            ref={textareaRef}
                            value={body}
                            onChange={(e) => {
                                setBody(e.target.value);
                                resize();
                            }}
                            onKeyDown={handleKeyDown}
                            placeholder={t('chatThread.messageTo', {
                                channel: title,
                            })}
                            aria-label={t('chatThread.messageTo', {
                                channel: title,
                            })}
                            rows={1}
                            maxLength={MAX_MESSAGE_LENGTH}
                            className="placeholder:text-faint max-h-40 min-h-11 flex-1 resize-none bg-transparent px-3 py-2.5 text-base outline-none"
                        />
                        <button
                            type="submit"
                            disabled={
                                sending ||
                                !body.trim() ||
                                slow.cooldownRemaining > 0
                            }
                            aria-label={t('chatThread.send')}
                            className="bg-ink text-ground flex min-h-11 min-w-11 shrink-0 items-center justify-center gap-2 px-3 font-semibold transition disabled:opacity-40"
                        >
                            {slow.cooldownRemaining > 0 ? (
                                t('chatThread.wait', {
                                    seconds: slow.cooldownRemaining,
                                })
                            ) : (
                                <ArrowRightIcon />
                            )}
                        </button>
                    </form>
                </>
            )}

            {slow.cooldownRemaining > 0 ? (
                <p
                    aria-live="polite"
                    className="text-danger mt-1.5 text-sm font-medium"
                >
                    {t('chatThread.slowModeWait', {
                        seconds: slow.cooldownRemaining,
                    })}
                </p>
            ) : error ? (
                <p
                    role="alert"
                    className="text-danger mt-1.5 text-sm font-medium"
                >
                    {error}
                </p>
            ) : (
                <p className="text-faint mt-1.5 hidden font-mono text-[11px] sm:block">
                    {t('chatThread.sendHint')}
                </p>
            )}
        </div>
    );
}
