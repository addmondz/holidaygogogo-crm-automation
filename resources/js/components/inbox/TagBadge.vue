<script setup lang="ts">
import { X } from '@lucide/vue';
import { tagColorClasses } from '@/lib/tags';
import type { Tag } from '@/types';

defineProps<{
    tag: Tag;
    removable?: boolean;
}>();

defineEmits<{ remove: [] }>();
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="tagColorClasses[tag.color] ?? tagColorClasses.slate"
    >
        <span class="truncate">{{ tag.name }}</span>
        <button
            v-if="removable"
            type="button"
            class="-mr-0.5 rounded-full opacity-60 hover:opacity-100"
            :aria-label="`Remove tag ${tag.name}`"
            @click.stop="$emit('remove')"
        >
            <X class="size-3" />
        </button>
    </span>
</template>
