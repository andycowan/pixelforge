<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            d="M3 3h11v11H3zM18 3h11v11H18zM3 18h11v11H3zM18 18h5v5h-5zM24 24h5v5h-5z"
        />
    </svg>
</template>
