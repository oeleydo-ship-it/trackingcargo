import type { PublicBoxCount } from '../types';

/** "3 boxes" / "1 box" for the public tracking pages. */
export function boxTotal(count: number): string {
    return `${count} ${count === 1 ? 'box' : 'boxes'}`;
}

/**
 * "2 × Box · Jumbo, 1 × Custom" — the per-kind breakdown. Empty when every box
 * is Custom, since "3 boxes — 3 × Custom" tells the visitor nothing new.
 */
export function boxBreakdown(boxes: PublicBoxCount[]): string {
    if (boxes.every((box) => box.label === 'Custom')) {
        return '';
    }

    return boxes.map((box) => `${box.count} × ${box.label}`).join(', ');
}
