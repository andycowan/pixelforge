<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Heading from '@/components/Heading.vue';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Workspace',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Workspace" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Heading
            title="Workspace"
            description="Your Pixelforge generations will appear here."
        />

        <Card class="w-full max-w-2xl">
            <CardContent
                class="flex flex-col items-center gap-3 py-12 text-center"
            >
                <AppLogoIcon
                    class="size-8 fill-current text-muted-foreground"
                />
                <h2 class="text-lg font-semibold">No generations yet</h2>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Image generation is not available yet. When it ships, your
                    prompts, estimates, results and history will live in this
                    workspace.
                </p>
            </CardContent>
        </Card>
    </div>
</template>
