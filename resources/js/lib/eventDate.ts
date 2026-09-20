/**
 * The calendar day of a tracking event, without the time.
 *
 * Public tracking shows the day only. It is read from the date the server
 * recorded (the first ten characters of the ISO timestamp) instead of being
 * converted to the visitor's timezone, so a visitor west of the company never
 * sees the previous day.
 */
export function formatEventDate(occurredAt: string): string {
    const [year, month, day] = occurredAt.slice(0, 10).split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString();
}
