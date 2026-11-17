/**
 * Types for the real generation workflow: the submission form and the owned
 * history page.
 *
 * Money always arrives as an exact decimal string. It is displayed, never
 * parsed into a JavaScript number or used for arithmetic.
 */
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

/** Exact POST body accepted by the generation store endpoint. */
export type GenerationRequestPayload = {
    prompt: string;
    size: GenerationSize;
    quality: GenerationQuality;
    reference_images: string[];
    submission_token: string;
};

/**
 * One row of the model-owned history projection. It deliberately contains no
 * provider identifiers, key fingerprints, raw errors, usage or quote evidence.
 *
 * `actual_cost_usd` is `null` while settlement is unknown and an exact amount
 * once it is known; `submission_message` is a safe human-readable note.
 */
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

/** The `LengthAwarePaginator` shape Inertia serialises for the history page. */
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

/** Truthful local-submission versus provider wording for one record. */
export type GenerationState = {
    label: string;
    detail: string;
    tone: GenerationStateTone;
};

export type ActualCostState =
    | { kind: 'pending'; label: 'Pending'; detail: string }
    | { kind: 'not_charged'; label: 'Not charged'; detail: string }
    | { kind: 'settled'; label: 'Charged'; detail: string };
