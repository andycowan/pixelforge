import type { GenerationQuality, GenerationSize } from './quote';

export type GenerationSubmissionStatus =
    | 'pending'
    | 'uncertain'
    | 'accepted'
    | 'rejected';

export type ProviderGenerationStatus =
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type GenerationRequestPayload = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
    submission_token: string;
};

export type GenerationHistoryItem = {
    id: string;
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
    submission_status: GenerationSubmissionStatus;
    provider_status: ProviderGenerationStatus | null;
    estimated_cost_usd: string;
    maximum_cost_usd: string;
    actual_cost_usd: string | null;
    submission_message: string | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    created_at: string | null;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type GenerationPaginator = {
    data: GenerationHistoryItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

export type GenerationStateTone = 'pending' | 'active' | 'success' | 'error';

export type GenerationState = {
    label: string;
    detail: string;
    tone: GenerationStateTone;
};

export type ActualCostState =
    | { kind: 'pending'; label: 'Pending'; detail: string }
    | { kind: 'not_charged'; label: 'Not charged'; detail: string }
    | { kind: 'settled'; label: 'Charged'; detail: string };
