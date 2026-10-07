import { Label, Select } from '@/components/form-controls';
import { useTranslation } from '@/lib/i18n/context';
import { minutesToTime, type Selection } from './time';

type Props = {
    value: Selection;
    startTimeOptions: number[];
    durationOptions: number[];
    onStartTimeChange: (raw: string) => void;
    onDurationChange: (raw: string) => void;
};

// Landing on an exact 15-minute mark by clicking the bar is awkward no matter
// how big it's drawn (on a phone a step is only a few pixels wide), and a
// native time input is just as fiddly in its own way. A plain list of valid
// times to pick from is the easiest option on any device. These feed the same
// value the clicks on the bar use, so either one works.
export function TimelineSelects({
    value,
    startTimeOptions,
    durationOptions,
    onStartTimeChange,
    onDurationChange,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            {/* min-w-0 on each grid item + overflow-x-hidden around each
            native control: iOS Safari's own time/select chrome can ignore
            the width its CSS grid cell assigned it, otherwise overlapping
            into the next column instead of shrinking to fit — the same
            WebKit quirk the date field above had. */}
            <div className="mb-3 grid grid-cols-2 gap-3">
                <div className="flex min-w-0 flex-col gap-1.5">
                    <Label htmlFor="timeline_start_time">
                        {t('reservations.startTime')}
                    </Label>
                    <div className="w-full overflow-x-hidden">
                        <Select
                            id="timeline_start_time"
                            value={value.start ?? ''}
                            onChange={(e) => onStartTimeChange(e.target.value)}
                        >
                            {value.start === null && (
                                <option value="">
                                    {t('reservations.startTimePlaceholder')}
                                </option>
                            )}
                            {startTimeOptions.map((minutes) => (
                                <option key={minutes} value={minutes}>
                                    {minutesToTime(minutes)}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
                <div className="flex min-w-0 flex-col gap-1.5">
                    <Label htmlFor="timeline_duration">
                        {t('reservations.duration')}
                    </Label>
                    <div className="w-full overflow-x-hidden">
                        <Select
                            id="timeline_duration"
                            disabled={value.start === null}
                            value={
                                value.start !== null && value.end !== null
                                    ? value.end - value.start
                                    : ''
                            }
                            onChange={(e) => onDurationChange(e.target.value)}
                        >
                            {(value.start === null || value.end === null) && (
                                <option value="">
                                    {t('reservations.durationPlaceholder')}
                                </option>
                            )}
                            {durationOptions.map((minutes) => (
                                <option key={minutes} value={minutes}>
                                    {t('reservations.durationOption', {
                                        minutes,
                                    })}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>
            </div>
        </>
    );
}
