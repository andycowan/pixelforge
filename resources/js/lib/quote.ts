/** Pure preview helpers; server pricing remains authoritative. */
import type {
    QuoteField,
    QuoteFieldErrors,
    QuoteFormInput,
    QuotePreview,
    QuoteQualityOption,
    QuoteRateCategory,
    QuoteRequest,
    QuoteSizeOption,
    QuoteValidation,
} from '@/types/quote';

export const PROMPT_MAX_CODE_POINTS = 4000;

export const MAX_REFERENCE_IMAGES = 4;

export const QUOTE_DEBOUNCE_MS = 1000;

export const QUOTE_REQUEST_TIMEOUT_MS = 10_000;

export const quoteSizeOptions: readonly QuoteSizeOption[] = [
    { value: '1024x1024', label: 'Square', dimensions: '1024 × 1024' },
    { value: '1536x1024', label: 'Landscape', dimensions: '1536 × 1024' },
    { value: '1024x1536', label: 'Portrait', dimensions: '1024 × 1536' },
];

export const quoteQualityOptions: readonly QuoteQualityOption[] = [
    { value: 'low', label: 'Low', description: 'Lowest output cost.' },
    {
        value: 'medium',
        label: 'Medium',
        description: 'Balanced default for most prompts.',
    },
    {
        value: 'high',
        label: 'High',
        description: 'Most detailed output, highest output cost.',
    },
];

export const quoteTokenCategories: readonly {
    key: QuoteRateCategory;
    label: string;
}[] = [
    { key: 'input_text_tokens', label: 'Input text tokens' },
    { key: 'input_image_tokens', label: 'Input image tokens' },
    { key: 'output_tokens', label: 'Output tokens' },
    { key: 'reasoning_tokens', label: 'Reasoning tokens' },
];

const MONEY_PATTERN = /^(?:0|[1-9]\d*)(?:\.\d+)?$/;

/**
 * Counts Unicode code points like the server, not UTF-16 units or grapheme
 * clusters. A combined emoji can contain several code points.
 */
export function countCodePoints(value: string): number {
    return Array.from(value).length;
}

/** Splits the reference textarea into trimmed, non-empty lines, in order. */
export function parseReferenceUrls(value: string): string[] {
    return value
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '');
}

/** Only absolute http(s) URLs may be quoted as reference images. */
export function isHttpUrl(value: string): boolean {
    if (!/^https?:\/\//i.test(value)) {
        return false;
    }

    try {
        const parsed = new URL(value);

        return (
            (parsed.protocol === 'http:' || parsed.protocol === 'https:') &&
            parsed.hostname !== ''
        );
    } catch {
        return false;
    }
}

/**
 * Light client validation for quickly obvious problems. The server remains
 * authoritative: anything it rejects comes back as field errors on the quote
 * response and replaces these messages.
 */
export function validateQuoteInput(input: QuoteFormInput): QuoteValidation {
    const promptBlank = input.prompt.trim() === '';
    const promptLength = countCodePoints(input.prompt);
    const referenceUrls = parseReferenceUrls(input.referenceUrls);
    const fieldErrors: QuoteFieldErrors = {};

    if (promptLength > PROMPT_MAX_CODE_POINTS) {
        fieldErrors.prompt = `Prompts can be at most ${PROMPT_MAX_CODE_POINTS} characters (this one is ${promptLength}).`;
    }

    if (referenceUrls.length > MAX_REFERENCE_IMAGES) {
        fieldErrors.reference_images = `Use at most ${MAX_REFERENCE_IMAGES} reference image URLs (you entered ${referenceUrls.length}).`;
    } else {
        const invalidIndex = referenceUrls.findIndex((url) => !isHttpUrl(url));

        if (invalidIndex !== -1) {
            fieldErrors.reference_images = `Reference URL ${invalidIndex + 1} must be a full http:// or https:// URL.`;
        }
    }

    const request: QuoteRequest | null =
        promptBlank || Object.keys(fieldErrors).length > 0
            ? null
            : {
                  prompt: input.prompt,
                  size: input.size,
                  quality: input.quality,
                  reference_images: referenceUrls,
              };

    return {
        promptBlank,
        promptLength,
        referenceCount: referenceUrls.length,
        fieldErrors,
        request,
    };
}

/**
 * Trims insignificant trailing zeros from an exact amount string without
 * changing its value or rounding it. At least two decimal places are kept so
 * money never looks like a token count.
 */
export function formatUsdAmount(value: string): string {
    if (!MONEY_PATTERN.test(value)) {
        return value;
    }

    const [whole, fraction] = value.split('.');

    if (fraction === undefined) {
        return `${whole}.00`;
    }

    let trimmed = fraction.replace(/0+$/, '');

    if (trimmed.length < 2) {
        trimmed = `${trimmed}00`.slice(0, 2);
    }

    return `${whole}.${trimmed}`;
}

/** Token counts are not money; locale grouping is safe here. */
export function formatTokenCount(value: number): string {
    return value.toLocaleString('en-US');
}

/** Short, human label for the currency shown beside every amount. */
export function formatCurrency(value: string): string {
    return value.trim().toUpperCase();
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isTokenMap(value: unknown): boolean {
    if (!isRecord(value)) {
        return false;
    }

    return quoteTokenCategories.every(({ key }) => {
        const count = value[key];

        return (
            typeof count === 'number' &&
            Number.isSafeInteger(count) &&
            count >= 0
        );
    });
}

function isRateMap(value: unknown): boolean {
    if (!isRecord(value)) {
        return false;
    }

    return quoteTokenCategories.every(({ key }) => {
        const rate = value[key];

        return typeof rate === 'string' && MONEY_PATTERN.test(rate);
    });
}

/**
 * Structural guard so a malformed or envelope-shaped payload becomes a visible
 * "unexpected response" failure instead of a half-empty price card.
 */
export function isQuotePreview(value: unknown): value is QuotePreview {
    if (!isRecord(value)) {
        return false;
    }

    if (value.currency !== 'usd') {
        return false;
    }

    if (
        typeof value.markup_percent !== 'number' ||
        !Number.isFinite(value.markup_percent) ||
        value.markup_percent < 0
    ) {
        return false;
    }

    const moneyKeys = [
        'estimated_cost_usd',
        'maximum_cost_usd',
        'provider_estimated_cost_usd',
        'provider_maximum_cost_usd',
    ] as const;

    for (const key of moneyKeys) {
        const amount = value[key];

        if (typeof amount !== 'string' || !MONEY_PATTERN.test(amount)) {
            return false;
        }
    }

    return (
        isTokenMap(value.token_estimate) &&
        isTokenMap(value.token_maximum) &&
        isRateMap(value.rates)
    );
}

/** Returns seconds from a `Retry-After` header when it is a plain delay. */
export function readRetryAfter(value: string | null): number | null {
    if (value === null) {
        return null;
    }

    const trimmed = value.trim();

    if (!/^\d+$/.test(trimmed)) {
        return null;
    }

    const seconds = Number.parseInt(trimmed, 10);

    return Number.isSafeInteger(seconds) && seconds > 0 ? seconds : null;
}

/**
 * Reads the CSRF token from the `XSRF-TOKEN` cookie Laravel sets for the web
 * middleware group. `X-XSRF-TOKEN` expects the decoded cookie payload.
 */
export function readXsrfToken(cookieHeader: string): string | null {
    for (const part of cookieHeader.split(';')) {
        const entry = part.trim();

        if (!entry.startsWith('XSRF-TOKEN=')) {
            continue;
        }

        const raw = entry.slice('XSRF-TOKEN='.length);

        try {
            return decodeURIComponent(raw);
        } catch {
            return null;
        }
    }

    return null;
}

function toMessages(value: unknown): string[] {
    if (typeof value === 'string') {
        return value.trim() === '' ? [] : [value.trim()];
    }

    if (Array.isArray(value)) {
        return value
            .filter((entry): entry is string => typeof entry === 'string')
            .map((entry) => entry.trim())
            .filter((entry) => entry !== '');
    }

    return [];
}

function parseErrorKey(
    key: string,
): { field: QuoteField; prefix: string } | null {
    if (
        key === 'prompt' ||
        key === 'size' ||
        key === 'quality' ||
        key === 'reference_images'
    ) {
        return { field: key, prefix: '' };
    }

    const match = /^reference_images\.(\d+)$/.exec(key);

    if (match !== null) {
        const position = Number.parseInt(match[1], 10);

        return {
            field: 'reference_images',
            prefix: `Reference URL ${position + 1}: `,
        };
    }

    return null;
}

function readMessage(payload: unknown): string | null {
    if (isRecord(payload) && typeof payload.message === 'string') {
        const message = payload.message.trim();

        return message === '' ? null : message;
    }

    return null;
}

/**
 * Turns a Laravel 422 body into friendly field messages. Indexed reference
 * errors (`reference_images.0`) are labelled with their visible position;
 * anything unrecognised is surfaced as a general message instead of dropped.
 */
export function flattenValidationErrors(payload: unknown): {
    fieldErrors: QuoteFieldErrors;
    generalMessages: string[];
} {
    const fieldErrors: QuoteFieldErrors = {};
    const generalMessages: string[] = [];
    const errors =
        isRecord(payload) && isRecord(payload.errors) ? payload.errors : null;

    if (errors === null) {
        const message = readMessage(payload);

        return {
            fieldErrors,
            generalMessages: message === null ? [] : [message],
        };
    }

    for (const [key, value] of Object.entries(errors)) {
        const messages = toMessages(value);

        if (messages.length === 0) {
            continue;
        }

        const parsed = parseErrorKey(key);

        if (parsed === null) {
            generalMessages.push(messages.join(' '));
            continue;
        }

        const text = `${parsed.prefix}${messages.join(' ')}`;

        fieldErrors[parsed.field] = fieldErrors[parsed.field]
            ? `${fieldErrors[parsed.field]} ${text}`
            : text;
    }

    return { fieldErrors, generalMessages };
}

/** Safe general fallback text from an error response body. */
export function readErrorMessage(payload: unknown): string | null {
    return readMessage(payload);
}
