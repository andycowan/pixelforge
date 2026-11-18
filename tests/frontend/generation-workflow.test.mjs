import assert from 'node:assert/strict';
import test from 'node:test';
import {
    buildGenerationPayload,
    createSubmissionToken,
    decodePaginationLabel,
    describeActualCost,
    describeGenerationState,
    generationOptionsKey,
    isResultImageExpired,
    readSubmissionError,
    submissionHttpExceptionMessage,
} from '../../resources/js/lib/generation.ts';

const OPTIONS = {
    prompt: 'A brass diving helmet on a mossy rock',
    size: '1024x1024',
    quality: 'medium',
    reference_images: ['https://example.com/a.png'],
};

function historyItem(overrides = {}) {
    return {
        id: '01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        prompt: OPTIONS.prompt,
        size: '1024x1024',
        quality: 'medium',
        reference_images: [],
        submission_status: 'pending',
        provider_status: null,
        estimated_cost_usd: '0.072206250',
        maximum_cost_usd: '0.086275000',
        actual_cost_usd: null,
        submission_message: null,
        image_url: null,
        image_url_expires_at: null,
        created_at: '2026-11-17T10:00:00+00:00',
        ...overrides,
    };
}

void test('the options key is stable for identical options and changes with any option', () => {
    const base = generationOptionsKey(OPTIONS);

    assert.equal(base, generationOptionsKey({ ...OPTIONS }));
    assert.notEqual(
        base,
        generationOptionsKey({ ...OPTIONS, prompt: `${OPTIONS.prompt}.` }),
    );
    assert.notEqual(
        base,
        generationOptionsKey({ ...OPTIONS, size: '1024x1536' }),
    );
    assert.notEqual(
        base,
        generationOptionsKey({ ...OPTIONS, quality: 'high' }),
    );

    assert.notEqual(
        base,
        generationOptionsKey({
            ...OPTIONS,
            reference_images: [],
        }),
    );
    assert.notEqual(
        generationOptionsKey({
            ...OPTIONS,
            reference_images: [
                'https://example.com/a.png',
                'https://example.com/b.png',
            ],
        }),
        generationOptionsKey({
            ...OPTIONS,
            reference_images: [
                'https://example.com/b.png',
                'https://example.com/a.png',
            ],
        }),
    );
});

void test('the payload carries the token and copies references', () => {
    const payload = buildGenerationPayload(
        OPTIONS,
        '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10',
    );

    assert.deepEqual(payload, {
        ...OPTIONS,
        submission_token: '6f1c2a9e-3b7d-4e0a-9c55-2d8f1b4e7a10',
    });
    assert.notEqual(payload.reference_images, OPTIONS.reference_images);
});

void test('submission tokens are version 4 UUIDs and unique', () => {
    const first = createSubmissionToken();
    const second = createSubmissionToken();

    assert.match(
        first,
        /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
    assert.notEqual(first, second);
});

void test('local submission state never claims an unrecorded provider result', () => {
    assert.equal(
        describeGenerationState(historyItem()).label,
        'Waiting to submit',
    );

    assert.equal(
        describeGenerationState(historyItem({ submission_status: 'uncertain' }))
            .label,
        'Outcome unconfirmed',
    );
    assert.equal(
        describeGenerationState(historyItem({ submission_status: 'accepted' }))
            .label,
        'Accepted',
    );
    assert.equal(
        describeGenerationState(
            historyItem({
                submission_status: 'uncertain',
                provider_status: 'queued',
            }),
        ).label,
        'Queued at provider',
    );
    assert.equal(
        describeGenerationState(
            historyItem({
                submission_status: 'accepted',
                provider_status: 'processing',
            }),
        ).label,
        'Generating',
    );
    assert.deepEqual(
        describeGenerationState(
            historyItem({
                submission_status: 'accepted',
                provider_status: 'succeeded',
            }),
        ),
        {
            label: 'Succeeded',
            detail: 'The provider finished this generation and the exact charge has been settled.',
            tone: 'success',
        },
    );
    assert.equal(
        describeGenerationState(
            historyItem({
                submission_status: 'accepted',
                provider_status: 'failed',
            }),
        ).tone,
        'error',
    );

    const rejected = describeGenerationState(
        historyItem({
            submission_status: 'rejected',
            submission_message: 'The prompt was rejected by the provider.',
        }),
    );

    assert.equal(rejected.label, 'Not accepted');
    assert.equal(rejected.detail, 'The prompt was rejected by the provider.');
});

void test('unknown actual cost is pending while known failures are not charged', () => {
    const pending = describeActualCost(historyItem());

    assert.equal(pending.kind, 'pending');

    const failed = describeActualCost(
        historyItem({
            provider_status: 'failed',
            actual_cost_usd: '0.000000000',
        }),
    );

    assert.equal(failed.kind, 'not_charged');

    const rejected = describeActualCost(
        historyItem({
            submission_status: 'rejected',
            actual_cost_usd: '0.000000000',
        }),
    );

    assert.equal(rejected.kind, 'not_charged');

    const settled = describeActualCost(
        historyItem({
            provider_status: 'succeeded',
            actual_cost_usd: '0.061125000',
        }),
    );

    assert.equal(settled.kind, 'settled');
});

void test('provider result links expire at their recorded time and are never extended', () => {
    const now = Date.parse('2026-11-18T10:00:00Z');

    assert.equal(isResultImageExpired(null, now), false);
    assert.equal(isResultImageExpired('2026-11-18T09:59:59Z', now), true);
    assert.equal(isResultImageExpired('2026-11-18T10:00:00Z', now), true);
    assert.equal(isResultImageExpired('2026-11-19T10:00:00Z', now), false);
    assert.equal(isResultImageExpired('not-a-date', now), false);
});

void test('pagination labels render as readable text', () => {
    assert.equal(decodePaginationLabel('&laquo; Previous'), '« Previous');
    assert.equal(decodePaginationLabel('Next &raquo;'), 'Next »');
    assert.equal(decodePaginationLabel('1'), '1');
    assert.equal(decodePaginationLabel('&amp; more'), '& more');
});

void test('only non-field rejection messages become form-level errors', () => {
    assert.equal(readSubmissionError({}), null);
    assert.equal(readSubmissionError({ prompt: 'Required.' }), null);
    assert.equal(
        readSubmissionError({ 'reference_images.0': 'Bad URL.' }),
        null,
    );
    assert.equal(
        readSubmissionError({
            submission_token: 'This submission was already used.',
        }),
        'This submission was already used.',
    );
    assert.equal(
        readSubmissionError({
            prompt: 'Required.',
            submission_token: 'Conflict.',
        }),
        'Conflict.',
    );
});

void test('generation failures are described without inventing a charge', () => {
    assert.match(submissionHttpExceptionMessage(503), /unavailable/i);
    for (const status of [401, 419, 429, 500, 502, 503]) {
        assert.doesNotMatch(
            submissionHttpExceptionMessage(status),
            /Nothing was created or charged/i,
        );
        assert.match(
            submissionHttpExceptionMessage(status),
            /unchanged options/i,
        );
    }
    assert.match(submissionHttpExceptionMessage(419), /session expired/i);
    assert.match(
        submissionHttpExceptionMessage(429),
        /Too many generation requests/i,
    );
    assert.match(submissionHttpExceptionMessage(500), /could not confirm/i);
});
