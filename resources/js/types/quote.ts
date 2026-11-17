/**
 * Types for the read-only quote preview on the workspace.
 *
 * The shapes mirror the flat JSON contract of `POST /generations/quote`
 * Money is always an exact decimal string; it must never be
 * parsed into a JavaScript number.
 */

export type GenerationSize = '1024x1024' | '1536x1024' | '1024x1536';

export type GenerationQuality = 'low' | 'medium' | 'high';

export type QuoteField = 'prompt' | 'size' | 'quality' | 'reference_images';

/** Friendly, per-field messages shown beneath the matching control. */
export type QuoteFieldErrors = Partial<Record<QuoteField, string>>;

/** The form owns free-text input; references stay one-per-line while typing. */
export type QuoteFormInput = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    referenceUrls: string;
};

/** Exact request body accepted by the quote endpoint. */
export type QuoteRequest = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
};

export type QuoteRateCategory =
    | 'input_text_tokens'
    | 'input_image_tokens'
    | 'output_tokens'
    | 'reasoning_tokens';

export type QuoteTokens = Record<QuoteRateCategory, number>;

export type QuoteRates = Record<QuoteRateCategory, string>;

/** Flat quote response; there is no `data` envelope. */
export type QuotePreview = {
    affordable: boolean;
    currency: string;
    markup_percent: number;
    estimated_cost_usd: string;
    maximum_cost_usd: string;
    provider_estimated_cost_usd: string;
    provider_maximum_cost_usd: string;
    token_estimate: QuoteTokens;
    token_maximum: QuoteTokens;
    rates: QuoteRates;
};

export type QuoteFailureKind =
    | 'validation'
    | 'session'
    | 'throttled'
    | 'unavailable'
    | 'network'
    | 'unknown';

export type QuoteFailure = {
    kind: QuoteFailureKind;
    message: string;
    retryable: boolean;
};

export type QuoteStatus = 'idle' | 'invalid' | 'pending' | 'ready' | 'error';

export type QuoteSizeOption = {
    value: GenerationSize;
    label: string;
    dimensions: string;
};

export type QuoteQualityOption = {
    value: GenerationQuality;
    label: string;
    description: string;
};

export type QuoteValidation = {
    promptBlank: boolean;
    promptLength: number;
    referenceCount: number;
    fieldErrors: QuoteFieldErrors;
    /** `null` when the client should not send a quote request. */
    request: QuoteRequest | null;
};
