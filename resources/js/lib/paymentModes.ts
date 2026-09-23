export interface PaymentModeOption {
    value: string;
    label: string;
    active: boolean;
}

export function paymentModeLabel(value: string | null | undefined, modes: PaymentModeOption[]): string {
    return modes.find((mode) => mode.value === value)?.label ?? value ?? '—';
}
