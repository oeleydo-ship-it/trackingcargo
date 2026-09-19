/** The ways a customer can pay for a shipment; values match App\Enums\PaymentMethod. */
export const paymentModes = [
    { value: 'cash', label: 'Cash' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'card', label: 'Card' },
    { value: 'cod', label: 'Cash on delivery' },
] as const;

export type PaymentMode = (typeof paymentModes)[number]['value'];

export function paymentModeLabel(value: string | null | undefined): string {
    return paymentModes.find((mode) => mode.value === value)?.label ?? '—';
}
