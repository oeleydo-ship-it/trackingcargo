/**
 * A package's weight is optional and stored as 0 when not entered — a real
 * weight can never be 0 — so 0 reads as "not entered", not as a weightless
 * package.
 */
export function formatKg(value: string | number | null | undefined): string {
    return Number(value) > 0 ? `${value} kg` : '—';
}
