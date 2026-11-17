/**
 * Pure helpers for the real generation workflow.
 *
 * Everything here is presentation or identity logic. No function computes a
 * price, a charge or a total: the server owns money.
 */
import type {
    ActualCostState,
    GenerationHistoryItem,
    GenerationRequestPayload,
    GenerationState,
} from '@/types/generation';
import type { QuoteRequest } from '@/types/quote';

const FIELD_ERROR_KEYS = new Set([
    'prompt',
    'size',
    'quality',
    'reference_images',
]);

/**
 * Identity of the exact submitted options. A submission token is reused only
 * while this key is unchanged, so changing an option always starts a new
 * request while an unchanged retry stays idempotent.
 */
export function generationOptionsKey(request: QuoteRequest): string {
    return JSON.stringify([
        request.prompt,
        request.size,
        request.quality,
        request.reference_images,
    ]);
}

export function buildGenerationPayload(
    request: QuoteRequest,
    submissionToken: string,
): GenerationRequestPayload {
    return {
        prompt: request.prompt,
        size: request.size,
        quality: request.quality,
        reference_images: [...request.reference_images],
        submission_token: submissionToken,
    };
}

/** RFC 4122 version 4 UUID, with a fallback for browsers without randomUUID. */
export function createSubmissionToken(): string {
    const crypto = globalThis.crypto;

    if (typeof crypto?.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (byte) =>
        byte.toString(16).padStart(2, '0'),
    ).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/**
 * Local submission state and provider state are different facts. Describe
 * both without pretending a provider accepted, finished or charged anything
 * that has not been recorded.
 */
export function describeGenerationState(
    item: GenerationHistoryItem,
): GenerationState {
    if (item.submission_status === 'rejected') {
        return {
            label: 'Not accepted',
            detail:
                item.submission_message ??
                'The request was rejected before a generation ran. Nothing was charged.',
            tone: 'error',
        };
    }

    switch (item.provider_status) {
        case 'succeeded':
            return {
                label: 'Completed',
                detail: 'The provider finished this generation and the exact charge has been settled.',
                tone: 'success',
            };
        case 'failed':
            return {
                label: 'Generation failed',
                detail:
                    item.submission_message ??
                    'The provider reported a failure. Nothing was charged.',
                tone: 'error',
            };
        case 'queued':
            return {
                label: 'Queued at provider',
                detail:
                    item.submission_message ??
                    'The provider accepted the request and has not started it yet.',
                tone: 'active',
            };
        case 'processing':
            return {
                label: 'Generating',
                detail:
                    item.submission_message ??
                    'The provider is generating the image.',
                tone: 'active',
            };
        default:
            break;
    }

    if (item.submission_status === 'uncertain') {
        return {
            label: 'Outcome unconfirmed',
            detail:
                item.submission_message ??
                'We have not confirmed whether the provider accepted this request. Nothing is charged until a result is settled.',
            tone: 'pending',
        };
    }

    if (item.submission_status === 'accepted') {
        return {
            label: 'Accepted',
            detail: 'The provider accepted the request; its generation status has not been recorded yet.',
            tone: 'pending',
        };
    }

    return {
        label: 'Waiting to submit',
        detail:
            item.submission_message ??
            'The request is stored and waiting for the background submission worker.',
        tone: 'pending',
    };
}

/**
 * An unknown settled amount stays pending; a known zero is only shown for a
 * failure or rejection, never as if a successful generation cost nothing.
 */
export function describeActualCost(
    item: GenerationHistoryItem,
): ActualCostState {
    if (
        item.submission_status === 'rejected' ||
        item.provider_status === 'failed'
    ) {
        return {
            kind: 'not_charged',
            label: 'Not charged',
            detail: 'This request did not produce a charge.',
        };
    }

    if (item.actual_cost_usd === null) {
        return {
            kind: 'pending',
            label: 'Pending',
            detail: 'The exact amount is known only after the result is settled.',
        };
    }

    return {
        kind: 'settled',
        label: 'Charged',
        detail: 'Settled from the accepted rates and actual token usage.',
    };
}

/**
 * Provider result links expire 24 hours after completion. Once expired we show
 * a placeholder instead of fetching a new URL that would extend the lifetime.
 */
export function isResultImageExpired(
    expiresAt: string | null,
    now: number = Date.now(),
): boolean {
    if (expiresAt === null) {
        return false;
    }

    const parsed = Date.parse(expiresAt);

    return Number.isFinite(parsed) && parsed <= now;
}

const PAGINATION_ENTITIES: Record<string, string> = {
    '&laquo;': '«',
    '&raquo;': '»',
    '&hellip;': '…',
    '&amp;': '&',
};

/** Laravel paginator labels are HTML-escaped prose; render them as text. */
export function decodePaginationLabel(label: string): string {
    return label
        .replace(
            /&(laquo|raquo|hellip|amp);/g,
            (entity) => PAGINATION_ENTITIES[entity] ?? entity,
        )
        .trim();
}

/**
 * Field errors stay with their controls; anything else (for example a
 * conflicting submission token) is a form-level message.
 */
export function readSubmissionError(
    errors: Record<string, unknown>,
): string | null {
    const messages: string[] = [];

    for (const [key, value] of Object.entries(errors)) {
        if (FIELD_ERROR_KEYS.has(key) || /^reference_images\.\d+$/.test(key)) {
            continue;
        }

        if (typeof value === 'string') {
            messages.push(value);
        } else if (Array.isArray(value)) {
            messages.push(...value);
        }
    }

    return messages.length === 0 ? null : messages.join(' ');
}

/** Friendliest available message for a non-validation HTTP failure. */
export function submissionHttpExceptionMessage(status: number): string {
    if (status === 419 || status === 401) {
        return 'Your session expired. Sign in again in another tab; your prompt stays here. Retry unchanged options to reuse the same request.';
    }

    if (status === 429) {
        return 'Too many generation requests. Wait a moment, then retry unchanged options to reuse the same request.';
    }

    if (status === 503) {
        return 'Image generation is currently unavailable and the request was not confirmed. Retry unchanged options to reuse the same request.';
    }

    // An HTTP error can happen after the server committed the request.
    return 'The server could not confirm the request. Check Generations or retry unchanged options before starting another request.';
}
