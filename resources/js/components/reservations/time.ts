export type Selection = {
    start: number | null;
    end: number | null;
};

export function timeToMinutes(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);
    return hours * 60 + minutes;
}

export function minutesToTime(minutes: number): string {
    const hours = Math.floor(minutes / 60)
        .toString()
        .padStart(2, '0');
    const mins = (minutes % 60).toString().padStart(2, '0');
    return `${hours}:${mins}`;
}

/** Minutes since midnight of `dateTime`, or null if it falls on another day than `date` (YYYY-MM-DD). */
export function minutesOnDate(date: string, dateTime: string): number | null {
    const d = new Date(dateTime);
    const local = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    if (local !== date) {
        return null;
    }

    return d.getHours() * 60 + d.getMinutes();
}
