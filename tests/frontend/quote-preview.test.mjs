// Frontend logic tests for the quote preview.
//
// Run with Node's built-in runner; no test framework or extra dependency is
// installed in this project:
//
//     node --test tests/frontend/quote-preview.test.mjs
//
// These cover the pure, framework-free parts of the preview: Unicode prompt
// counting, reference-URL parsing/validation, exact money display, CSRF cookie
// reading, retry headers, 422 flattening and the response guard. Component
// rendering and the fetch/abort/debounce races are verified in the browser.
import assert from 'node:assert/strict';
import test from 'node:test';
import {
    PROMPT_MAX_CODE_POINTS,
    countCodePoints,
    flattenValidationErrors,
    formatCurrency,
    formatSignedUsd,
    formatUsdAmount,
    isHttpUrl,
    isQuotePreview,
    parseReferenceUrls,
    readRetryAfter,
    readXsrfToken,
    validateQuoteInput,
} from '../../resources/js/lib/quote.ts';

const QUOTE_RESPONSE = {
    affordable: true,
    currency: 'usd',
    estimated_cost_usd: '0.080000000',
    maximum_cost_usd: '0.090000000',
};

function formInput(overrides = {}) {
    return {
        prompt: 'A brass diving helmet on a mossy rock',
        size: '1024x1024',
        quality: 'medium',
        referenceUrls: '',
        ...overrides,
    };
}

void test('counts prompt characters as Unicode code points', () => {
    assert.equal(countCodePoints(''), 0);
    assert.equal(countCodePoints('hello'), 5);
    assert.equal(countCodePoints('😀'), 1);
    assert.equal(countCodePoints('a😀b'), 3);
    assert.equal(countCodePoints('𝄞'), 1);
    assert.equal(countCodePoints('👩🏾‍💻'), 4);
});

void test('accepts 4000 code points but not 4001', () => {
    const atLimit = '😀'.repeat(PROMPT_MAX_CODE_POINTS);

    // 4000 emoji are 8000 UTF-16 units: a `maxlength="4000"` would cut them.
    assert.equal(atLimit.length, 8000);
    assert.notEqual(
        validateQuoteInput(formInput({ prompt: atLimit })).request,
        null,
    );
});

void test('parses one reference URL per line, preserving order and duplicates', () => {
    assert.deepEqual(
        parseReferenceUrls(
            '\n https://example.com/a.png \n\nhttps://example.com/a.png\r\n',
        ),
        ['https://example.com/a.png', 'https://example.com/a.png'],
    );
    assert.deepEqual(parseReferenceUrls('   \n\t\n'), []);
});

void test('rejects prompts longer than the limit and stops requesting', () => {
    const tooLong = 'a'.repeat(PROMPT_MAX_CODE_POINTS + 1);
    const result = validateQuoteInput(formInput({ prompt: tooLong }));

    assert.equal(result.request, null);
    assert.match(result.fieldErrors.prompt, /4000/);
    assert.match(result.fieldErrors.prompt, /4001/);
});

void test('a blank prompt is idle, not an error, and sends nothing', () => {
    const result = validateQuoteInput(formInput({ prompt: '   \n\t ' }));

    assert.equal(result.promptBlank, true);
    assert.equal(result.request, null);
    assert.deepEqual(result.fieldErrors, {});
});

void test('sends the prompt as typed when it has visible content', () => {
    const padded = '  keep my leading spaces \n';
    const result = validateQuoteInput(formInput({ prompt: padded }));

    assert.equal(result.request.prompt, padded);
});

void test('builds the exact wire payload and preserves reference order/duplicates', () => {
    const referenceUrls = [
        'https://example.com/two.png',
        'http://example.com/one.png',
        'https://example.com/two.png',
    ].join('\n');

    const result = validateQuoteInput(
        formInput({ referenceUrls, size: '1536x1024', quality: 'high' }),
    );

    assert.deepEqual(result.fieldErrors, {});
    assert.equal(result.referenceCount, 3);
    assert.deepEqual(result.request, {
        prompt: 'A brass diving helmet on a mossy rock',
        size: '1536x1024',
        quality: 'high',
        reference_images: [
            'https://example.com/two.png',
            'http://example.com/one.png',
            'https://example.com/two.png',
        ],
    });
    assert.deepEqual(Object.keys(result.request).sort(), [
        'prompt',
        'quality',
        'reference_images',
        'size',
    ]);
});

void test('rejects more than four reference URLs', () => {
    const referenceUrls = [1, 2, 3, 4, 5]
        .map((index) => `https://example.com/${index}.png`)
        .join('\n');
    const result = validateQuoteInput(formInput({ referenceUrls }));

    assert.equal(result.request, null);
    assert.equal(result.referenceCount, 5);
    assert.match(result.fieldErrors.reference_images, /at most 4/);
});

void test('rejects non-http(s) or incomplete reference URLs by position', () => {
    const result = validateQuoteInput(
        formInput({
            referenceUrls:
                'https://example.com/ok.png\nftp://example.com/nope.png',
        }),
    );

    assert.equal(result.request, null);
    assert.match(result.fieldErrors.reference_images, /^Reference URL 2 /);

    for (const candidate of [
        'example.com/a.png',
        'http://',
        'javascript:alert(1)',
        'https://exa mple.com/a.png',
        '',
    ]) {
        assert.equal(isHttpUrl(candidate), false, candidate);
    }

    assert.equal(isHttpUrl('https://example.com/a.png?x=1#y'), true);
    assert.equal(isHttpUrl('HTTP://example.com/a.png'), true);
});

void test('formats money textually without rounding or numeric conversion', () => {
    assert.equal(formatUsdAmount('0.072206250'), '0.07220625');
    assert.equal(formatUsdAmount('0.086275000'), '0.086275');
    assert.equal(formatUsdAmount('0.057765000'), '0.057765');
    assert.equal(formatUsdAmount('5.00'), '5.00');
    assert.equal(formatUsdAmount('12.50'), '12.50');
    assert.equal(formatUsdAmount('0.5'), '0.50');
    assert.equal(formatUsdAmount('7'), '7.00');
    assert.equal(formatUsdAmount('0.000000010'), '0.00000001');
    assert.equal(formatUsdAmount('0.000000000'), '0.00');
    assert.equal(formatUsdAmount('-0.050000000'), '-0.05');
    assert.equal(formatUsdAmount('-5.000000000'), '-5.00');
    assert.equal(
        formatUsdAmount('-9007199254740993.000000001'),
        '-9007199254740993.000000001',
    );
    assert.equal(formatUsdAmount('-0.000000001'), '-0.000000001');
    assert.equal(formatUsdAmount('not-money'), 'not-money');
    assert.equal(formatCurrency('usd'), 'USD');
});

void test('reads the decoded Laravel XSRF cookie', () => {
    const cookie =
        'session=abc; XSRF-TOKEN=eyJ0b2tlbiI6IjElMkYifQ%3D%3D; theme=dark';

    assert.equal(readXsrfToken(cookie), 'eyJ0b2tlbiI6IjElMkYifQ==');
    assert.equal(readXsrfToken('session=abc'), null);
    assert.equal(readXsrfToken('XSRF-TOKEN=%E0%A4%A'), null);
});

void test('parses only numeric Retry-After values', () => {
    assert.equal(readRetryAfter('30'), 30);
    assert.equal(readRetryAfter(' 12 '), 12);
    assert.equal(readRetryAfter('0'), null);
    assert.equal(readRetryAfter('9'.repeat(400)), null);
    assert.equal(readRetryAfter('Wed, 21 Oct 2015 07:28:00 GMT'), null);
    assert.equal(readRetryAfter(null), null);
});

void test('flattens 422 field errors and labels reference positions', () => {
    const { fieldErrors, generalMessages } = flattenValidationErrors({
        message: 'The given data was invalid.',
        errors: {
            prompt: ['The prompt field is required.'],
            size: ['The selected size is invalid.'],
            'reference_images.1': ['Reference image URL must be a valid URL.'],
            'reference_images.2': ['Reference image URL must be a valid URL.'],
            unexpected: ['Something else went wrong.'],
        },
    });

    assert.equal(fieldErrors.prompt, 'The prompt field is required.');
    assert.equal(fieldErrors.size, 'The selected size is invalid.');
    assert.equal(
        fieldErrors.reference_images,
        'Reference URL 2: Reference image URL must be a valid URL. Reference URL 3: Reference image URL must be a valid URL.',
    );
    assert.deepEqual(generalMessages, ['Something else went wrong.']);
});

void test('falls back to the general message when no field errors exist', () => {
    assert.deepEqual(flattenValidationErrors({ message: 'Nope.' }), {
        fieldErrors: {},
        generalMessages: ['Nope.'],
    });
    assert.deepEqual(flattenValidationErrors(null), {
        fieldErrors: {},
        generalMessages: [],
    });
});

void test('accepts the flat contract and rejects envelopes or numeric money', () => {
    assert.equal(isQuotePreview(QUOTE_RESPONSE), true);
    assert.equal(
        isQuotePreview({ ...QUOTE_RESPONSE, affordable: false }),
        true,
    );
    assert.equal(
        isQuotePreview({ ...QUOTE_RESPONSE, affordable: undefined }),
        false,
    );
    assert.equal(
        isQuotePreview({ ...QUOTE_RESPONSE, affordable: 'false' }),
        false,
    );
    assert.equal(
        isQuotePreview({ ...QUOTE_RESPONSE, maximum_cost_usd: '-0.05' }),
        false,
    );
    assert.equal(isQuotePreview({ data: QUOTE_RESPONSE }), false);
    assert.equal(isQuotePreview(null), false);
    assert.equal(
        isQuotePreview({ ...QUOTE_RESPONSE, estimated_cost_usd: 0.08 }),
        false,
    );
    assert.equal(isQuotePreview({ ...QUOTE_RESPONSE, currency: '' }), false);
    assert.equal(isQuotePreview({ ...QUOTE_RESPONSE, currency: 'eur' }), false);
    assert.equal(
        isQuotePreview({ ...QUOTE_RESPONSE, maximum_cost_usd: undefined }),
        false,
    );
    assert.equal(
        isQuotePreview({
            ...QUOTE_RESPONSE,
            maximum_cost_usd: '0.090000000\n',
        }),
        false,
    );
});

void test('signed balances put the minus sign before the dollar sign', () => {
    assert.equal(formatSignedUsd('-0.040000000'), '-$0.04');
    assert.equal(formatSignedUsd('12.500000000'), '$12.50');
    assert.equal(formatSignedUsd('0.000000000'), '$0.00');
});
