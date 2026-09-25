<script setup lang="ts">
import { computed } from 'vue';
import ChannelBadge from '@/components/inbox/ChannelBadge.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import type { ChannelType } from '@/types';

const props = withDefaults(
    defineProps<{
        name: string;
        avatarUrl?: string | null;
        channel?: ChannelType | null;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { avatarUrl: null, channel: null, size: 'md' },
);

const { getInitials } = useInitials();

const palette = [
    'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
    'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    'bg-teal-100 text-teal-700 dark:bg-teal-950 dark:text-teal-300',
];

const color = computed(() => {
    let hash = 0;

    for (const char of props.name) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return palette[hash % palette.length];
});

const sizeClass = computed(
    () =>
        ({
            sm: 'size-8 text-xs',
            md: 'size-10 text-sm',
            lg: 'size-14 text-lg',
        })[props.size],
);
</script>

<template>
    <span class="relative inline-flex shrink-0">
        <Avatar :class="sizeClass">
            <AvatarImage v-if="avatarUrl" :src="avatarUrl" :alt="name" />
            <AvatarFallback :class="color" class="font-medium">
                {{ getInitials(name.replace(/^\+/, '')) || '?' }}
            </AvatarFallback>
        </Avatar>
        <ChannelBadge
            v-if="channel"
            :type="channel"
            class="absolute -right-0.5 -bottom-0.5"
        />
    </span>
</template>
