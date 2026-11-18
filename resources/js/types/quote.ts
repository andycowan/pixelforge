export type GenerationSize = '1024x1024' | '1536x1024' | '1024x1536';

export type GenerationQuality = 'low' | 'medium' | 'high';

export type QuoteField = 'prompt' | 'size' | 'quality' | 'reference_images';

export type QuoteFieldErrors = Partial<Record<QuoteField, string>>;

export type QuoteFormInput = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    referenceUrls: string;
};

export type QuoteRequest = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
};

export type QuotePreview = {
    affordable: boolean;
    currency: string;
    estimated_cost_usd: string;
    maximum_cost_usd: string;
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
    request: QuoteRequest | null;
};
