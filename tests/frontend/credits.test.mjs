import assert from 'node:assert/strict';
import test from 'node:test';
import { creditEntryPresentation } from '../../resources/js/lib/credits.ts';
import { readSubmissionError } from '../../resources/js/lib/generation.ts';

void test('credit activity labels distinguish reservations from actual charges', () => {
    assert.deepEqual(creditEntryPresentation, {
        purchase: { label: 'Purchase', sign: '+' },
        hold: { label: 'Hold for generation', sign: '−' },
        release: { label: 'Hold released', sign: '+' },
        debit: { label: 'Charge for generation', sign: '−' },
    });
});

void test('credit rejection is surfaced as a form-level submission error', () => {
    const message =
        'Not enough credit: this request can cost up to $0.45 and your available balance is $0.20. Buy credits to continue. Nothing was created or charged.';
    assert.equal(
        readSubmissionError({ credits: message, prompt: 'Field error' }),
        message,
    );
    assert.equal(readSubmissionError({ credits: [message] }), message);
});
