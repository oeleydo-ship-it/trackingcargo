/**
 * Client-side mirror of App\Services\Shipments\TrackingNumberFormatter, used to
 * preview a pattern before the server allocates a real number. Keep the token
 * list and padding rules in step with the PHP side.
 */

export const TRACKING_TOKENS = ['{company}', '{branch}', '{sequence}', '{year}', '{yy}', '{month}', '{day}'] as const;

export const DEFAULT_TRACKING_FORMAT = '{company}-{branch}-{sequence}';

interface RenderOptions {
    format: string;
    companyCode: string;
    branchPrefix: string;
    padding: number;
    /** Omit to render the sequence slot as `#` placeholders. */
    sequence?: number;
    now?: Date;
}

const pad = (value: string, length: number) => value.padStart(length, '0');

export function renderTrackingNumber({ format, companyCode, branchPrefix, padding, sequence, now = new Date() }: RenderOptions): string {
    const width = Math.max(1, Math.min(12, padding));

    const replacements: Record<string, string> = {
        '{company}': companyCode,
        '{branch}': branchPrefix,
        '{sequence}': sequence === undefined ? '#'.repeat(width) : pad(String(sequence), width),
        '{year}': String(now.getFullYear()),
        '{yy}': String(now.getFullYear()).slice(-2),
        '{month}': pad(String(now.getMonth() + 1), 2),
        '{day}': pad(String(now.getDate()), 2),
    };

    return format.replace(/\{company\}|\{branch\}|\{sequence\}|\{year\}|\{yy\}|\{month\}|\{day\}/g, (token) => replacements[token] ?? token);
}

/**
 * Client-side mirror of TrackingNumberFormatter::invalidFormatReason(). Returns
 * a message explaining why a pattern is unusable, or null when it is fine.
 *
 * The mistake worth catching early: an unrecognised `{...}` group is not a
 * token, so it lands in the tracking number literally and breaks the public
 * tracking URL — writing the company code as `{SGFS}` instead of `{company}`.
 */
interface FormatRule {
    branch_id: number | null;
    mode: string | null;
    format: string;
    padding: number;
}

/**
 * Client-side mirror of App\Services\Shipments\TrackingNumberRules: the most
 * specific rule wins — branch and mode, then branch, then mode, then a
 * catch-all rule, then the company default.
 */
export function resolveTrackingFormat(
    rules: FormatRule[],
    branchId: number | null,
    mode: string | null,
    fallback: { format: string; padding: number },
): { format: string; padding: number } {
    const specificity = (rule: FormatRule) => (rule.branch_id !== null ? 2 : 0) + (rule.mode !== null ? 1 : 0);

    const match = rules
        .filter((rule) => (rule.branch_id === null || rule.branch_id === branchId) && (rule.mode === null || rule.mode === mode))
        .sort((a, b) => specificity(b) - specificity(a))[0];

    return match ? { format: match.format, padding: match.padding } : fallback;
}

export function trackingFormatProblem(format: string): string | null {
    const unknown = [...new Set(format.match(/\{[^{}]*\}/g) ?? [])]
        .filter((token) => !TRACKING_TOKENS.includes(token as (typeof TRACKING_TOKENS)[number]));

    if (unknown.length > 0) {
        return `Unknown token ${unknown.join(', ')}. Use the token buttons, or drop the braces to write the text literally.`;
    }

    const literals = TRACKING_TOKENS.reduce((remaining, token) => remaining.split(token).join(''), format);

    if (!/^[A-Z0-9\-_.]*$/.test(literals)) {
        return 'Text around the tokens may only use A-Z, 0-9, dashes, underscores and dots.';
    }

    if (!format.includes('{sequence}')) {
        return 'The format must include the {sequence} token.';
    }

    if (!/^[A-Z0-9{]/.test(format)) {
        return 'The format must start with a letter, a digit or a token.';
    }

    return null;
}
