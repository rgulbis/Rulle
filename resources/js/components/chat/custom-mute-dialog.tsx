import { FormEventHandler, useState } from 'react';
import { Button, Label, Select, TextInput } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';

const CUSTOM_MUTE_UNITS = [
    { hoursPer: 1, labelKey: 'chatThread.muteUnitHours' },
    { hoursPer: 24, labelKey: 'chatThread.muteUnitDays' },
    { hoursPer: 168, labelKey: 'chatThread.muteUnitWeeks' },
] as const;

// Matches the server-side max:8760 on the mute endpoints (one year).
const MAX_MUTE_HOURS = 8760;

type Props = {
    userName: string;
    onConfirm: (hours: number, durationLabel: string) => void;
    onClose: () => void;
};

/** Modal that asks how long to mute someone for. Mount it only while open. */
export function CustomMuteDialog({ userName, onConfirm, onClose }: Props) {
    const { t } = useTranslation();
    const [amount, setAmount] = useState('1');
    const [unit, setUnit] = useState<number>(1);

    const hours = Math.floor(Number(amount)) * unit;
    const valid =
        Number.isFinite(hours) && hours >= 1 && hours <= MAX_MUTE_HOURS;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (!valid) {
            return;
        }

        const selected = CUSTOM_MUTE_UNITS.find((u) => u.hoursPer === unit);

        onConfirm(
            hours,
            `${Math.floor(Number(amount))} ${t(selected?.labelKey ?? 'chatThread.muteUnitHours').toLowerCase()}`,
        );
    };

    return (
        <div
            className="bg-ink/50 fixed inset-0 z-50 flex items-center justify-center p-4"
            onClick={onClose}
        >
            <form
                role="dialog"
                aria-modal="true"
                aria-labelledby="custom-mute-title"
                onSubmit={submit}
                onClick={(event) => event.stopPropagation()}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        onClose();
                    }
                }}
                className="border-ink bg-paper shadow-hard-lg flex w-full max-w-sm flex-col gap-4 border-2 p-5"
            >
                <p
                    id="custom-mute-title"
                    className="font-mono text-sm font-semibold tracking-[0.12em] uppercase"
                >
                    {t('chatThread.muteCustomTitle', { name: userName })}
                </p>
                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-1.5">
                        <Label htmlFor="custom-mute-amount">
                            {t('chatThread.muteAmount')}
                        </Label>
                        <TextInput
                            id="custom-mute-amount"
                            type="number"
                            min={1}
                            step={1}
                            autoFocus
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                        />
                    </div>
                    <div className="flex flex-1 flex-col gap-1.5">
                        <Label htmlFor="custom-mute-unit">
                            {t('chatThread.muteUnit')}
                        </Label>
                        <Select
                            id="custom-mute-unit"
                            value={unit}
                            onChange={(e) => setUnit(Number(e.target.value))}
                        >
                            {CUSTOM_MUTE_UNITS.map((u) => (
                                <option key={u.hoursPer} value={u.hoursPer}>
                                    {t(u.labelKey)}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
                {!valid && (
                    <p className="text-danger text-sm">
                        {t('chatThread.muteCustomInvalid')}
                    </p>
                )}
                <div className="flex justify-end gap-3">
                    <Button variant="ghost" onClick={onClose}>
                        {t('chatThread.muteCancel')}
                    </Button>
                    <Button type="submit" disabled={!valid}>
                        {t('chatThread.muteConfirm')}
                    </Button>
                </div>
            </form>
        </div>
    );
}
