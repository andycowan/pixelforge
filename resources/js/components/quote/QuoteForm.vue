<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    MAX_REFERENCE_IMAGES,
    PROMPT_MAX_CODE_POINTS,
    countCodePoints,
    formatSignedUsd,
    quoteQualityOptions,
    quoteSizeOptions,
} from '@/lib/quote';
import { index as credits } from '@/routes/credits';
import type { QuoteFieldErrors, QuoteFormInput } from '@/types/quote';

const model = defineModel<QuoteFormInput>({ required: true });

const props = defineProps<{
    errors: QuoteFieldErrors;
    /** Server field messages, which are shown even before a field is blurred. */
    serverFieldErrors: QuoteFieldErrors;
    /** The server re-quotes and validates again, so this is only an affordance. */
    canGenerate: boolean;
    submitting: boolean;
    /** Why Generate is unavailable right now; null when it is available. */
    generateHint: string | null;
    /** Form-level failure from the last generation request. */
    generateError: string | null;
    availableUsd: string | null;
    needsCredits: boolean;
}>();

const emit = defineEmits<{
    generate: [];
}>();

const prompt = computed({
    get: () => model.value.prompt,
    set: (value: string) => {
        updateInput({ prompt: value });
    },
});

const referenceUrls = computed({
    get: () => model.value.referenceUrls,
    set: (value: string) => {
        updateInput({ referenceUrls: value });
    },
});

function updateInput(patch: Partial<QuoteFormInput>): void {
    model.value = { ...model.value, ...patch };
}

const qualityHelp = computed(
    () =>
        quoteQualityOptions.find(
            (option) => option.value === model.value.quality,
        )?.description ?? '',
);

function selectSize(value: QuoteFormInput['size']): void {
    updateInput({ size: value });
}

function updateQuality(value: unknown): void {
    const match = quoteQualityOptions.find((option) => option.value === value);

    if (match !== undefined) {
        updateInput({ quality: match.value });
    }
}

const promptLength = computed(() => countCodePoints(model.value.prompt));
const promptCountClass = computed(() =>
    promptLength.value > PROMPT_MAX_CODE_POINTS
        ? 'text-destructive'
        : 'text-muted-foreground',
);

// Reference URLs are validated on blur so a half-typed URL is not shouted at.
const referencesTouched = ref(false);
const referenceError = computed(() =>
    referencesTouched.value ||
    props.serverFieldErrors.reference_images !== undefined
        ? props.errors.reference_images
        : undefined,
);

const formAnnouncement = computed(() =>
    [
        props.errors.prompt,
        props.errors.size,
        props.errors.quality,
        referenceError.value,
    ]
        .filter(Boolean)
        .join(' '),
);
</script>

<template>
    <Card class="w-full">
        <CardHeader>
            <CardTitle>Describe an image</CardTitle>
            <CardDescription>
                Choose a prompt, output size and quality. Prices come from the
                server, which re-quotes this request again before anything is
                saved or generated.
            </CardDescription>
        </CardHeader>

        <CardContent class="flex flex-col gap-6">
            <p class="sr-only" aria-live="polite" aria-atomic="true">
                {{ formAnnouncement }}
            </p>

            <div class="grid gap-2">
                <div class="flex items-baseline justify-between gap-2">
                    <Label for="quote-prompt">Prompt</Label>
                    <span
                        id="quote-prompt-count"
                        class="text-xs tabular-nums"
                        :class="promptCountClass"
                    >
                        {{ promptLength }} / {{ PROMPT_MAX_CODE_POINTS }}
                    </span>
                </div>
                <textarea
                    id="quote-prompt"
                    v-model="prompt"
                    name="prompt"
                    rows="6"
                    :disabled="submitting"
                    class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                    :aria-invalid="Boolean(errors.prompt)"
                    aria-describedby="quote-prompt-help quote-prompt-error"
                    placeholder="A brass diving helmet on a mossy rock, morning light, shallow depth of field"
                ></textarea>
                <p id="quote-prompt-help" class="text-xs text-muted-foreground">
                    Describe subject, style and lighting. Up to
                    {{ PROMPT_MAX_CODE_POINTS }} characters; some combined emoji
                    count as more than one.
                </p>
                <InputError
                    id="quote-prompt-error"
                    :message="errors.prompt ?? undefined"
                />
            </div>

            <fieldset
                class="grid gap-2"
                :aria-invalid="Boolean(errors.size)"
                aria-describedby="quote-size-error"
            >
                <legend class="text-sm leading-none font-medium">Size</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label
                        v-for="option in quoteSizeOptions"
                        :key="option.value"
                        class="flex cursor-pointer flex-col gap-0.5 rounded-md border border-input px-3 py-2 text-sm shadow-xs transition-colors select-none hover:bg-accent/50 has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                    >
                        <input
                            type="radio"
                            name="quote-size"
                            :value="option.value"
                            :checked="model.size === option.value"
                            class="sr-only"
                            :disabled="submitting"
                            @change="selectSize(option.value)"
                        />
                        <span class="font-medium">{{ option.label }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ option.dimensions }}
                        </span>
                    </label>
                </div>
                <InputError
                    id="quote-size-error"
                    :message="errors.size ?? undefined"
                />
            </fieldset>

            <div class="grid gap-2">
                <Label id="quote-quality-label" for="quote-quality"
                    >Quality</Label
                >
                <Select
                    :model-value="model.quality"
                    :disabled="submitting"
                    @update:model-value="updateQuality"
                >
                    <SelectTrigger
                        id="quote-quality"
                        class="w-full"
                        aria-labelledby="quote-quality-label"
                        :aria-invalid="Boolean(errors.quality)"
                        aria-describedby="quote-quality-help quote-quality-error"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in quoteQualityOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p
                    id="quote-quality-help"
                    class="text-xs text-muted-foreground"
                >
                    {{ qualityHelp }}
                </p>
                <InputError
                    id="quote-quality-error"
                    :message="errors.quality ?? undefined"
                />
            </div>

            <div class="grid gap-2">
                <Label for="quote-references">
                    Reference image URLs
                    <span class="font-normal text-muted-foreground">
                        (optional)
                    </span>
                </Label>
                <textarea
                    id="quote-references"
                    v-model="referenceUrls"
                    name="reference_images"
                    rows="3"
                    :disabled="submitting"
                    spellcheck="false"
                    autocomplete="off"
                    class="flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                    :aria-invalid="Boolean(referenceError)"
                    aria-describedby="quote-references-help quote-references-error"
                    placeholder="https://example.com/reference.jpg"
                    @blur="referencesTouched = true"
                ></textarea>
                <p
                    id="quote-references-help"
                    class="text-xs text-muted-foreground"
                >
                    One http(s) URL per line, up to {{ MAX_REFERENCE_IMAGES }}.
                    Order and duplicates are kept because they change the
                    provider's token usage.
                </p>
                <InputError
                    id="quote-references-error"
                    :message="referenceError"
                />
            </div>
        </CardContent>

        <CardFooter class="flex-col items-start gap-2">
            <p
                v-if="availableUsd !== null"
                class="text-sm text-muted-foreground"
            >
                Available:
                <span class="font-medium text-foreground tabular-nums"
                    >{{ formatSignedUsd(availableUsd) }} USD</span
                >
            </p>
            <Button
                type="button"
                :disabled="!canGenerate || submitting"
                :aria-busy="submitting"
                aria-describedby="quote-generate-help"
                @click="emit('generate')"
            >
                <Spinner v-if="submitting" class="size-4" />
                {{ submitting ? 'Sending request…' : 'Generate' }}
            </Button>
            <p id="quote-generate-help" class="text-xs text-muted-foreground">
                <template v-if="generateHint !== null"
                    >{{ generateHint }}
                </template>
                Generating holds the maximum price from your balance until it
                finishes. The hold is released and only the actual cost is
                charged.
            </p>
            <p
                v-if="generateError !== null"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ generateError }}
            </p>
            <Link
                v-if="needsCredits"
                :href="credits()"
                class="text-sm font-medium underline underline-offset-4"
                >Buy credits</Link
            >
        </CardFooter>
    </Card>
</template>
