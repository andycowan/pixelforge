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
                label: 'Succeeded',
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

export function decodePaginationLabel(label: string): string {
    return label
        .replace(
            /&(laquo|raquo|hellip|amp);/g,
            (entity) => PAGINATION_ENTITIES[entity] ?? entity,
        )
        .trim();
}

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

    return 'The server could not confirm the request. Check Generations or retry unchanged options before starting another request.';
}
