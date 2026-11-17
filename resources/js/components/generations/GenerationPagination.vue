<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { decodePaginationLabel } from '@/lib/generation';
import type { GenerationPaginator } from '@/types/generation';

const props = defineProps<{
    paginator: GenerationPaginator;
}>();

const summary = computed(() => {
    const { from, to, total } = props.paginator;

    if (from === null || to === null) {
        return `${total} ${total === 1 ? 'generation' : 'generations'}`;
    }

    return `Showing ${from}–${to} of ${total}`;
});

const pageLinks = computed(() =>
    props.paginator.links.map((link) => ({
        ...link,
        text: decodePaginationLabel(link.label),
    })),
);
</script>

<template>
    <nav
        v-if="paginator.last_page > 1"
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        aria-label="Generation history pages"
    >
        <p class="text-sm text-muted-foreground">{{ summary }}</p>
        <ul class="flex flex-wrap items-center gap-1">
            <li v-for="(link, position) in pageLinks" :key="position">
                <Link
                    v-if="link.url !== null"
                    :href="link.url"
                    preserve-scroll
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-accent"
                    :class="
                        link.active
                            ? 'border-primary bg-primary/5 font-medium'
                            : 'border-input'
                    "
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ link.text }}
                </Link>
                <span
                    v-else
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border border-input px-2 text-sm text-muted-foreground opacity-50"
                    aria-disabled="true"
                >
                    {{ link.text }}
                </span>
            </li>
        </ul>
    </nav>
</template>
