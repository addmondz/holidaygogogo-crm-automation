<script setup lang="ts">
import { MessageCircle, Phone } from '@lucide/vue';
import type { ChannelType } from '@/types';

withDefaults(
    defineProps<{
        type: ChannelType;
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center justify-center rounded-full text-white ring-2 ring-background"
        :class="[
            type === 'whatsapp'
                ? 'bg-[#25D366]'
                : 'bg-gradient-to-br from-[#0099FF] to-[#A033FF]',
            size === 'sm' ? 'size-4' : 'size-5',
        ]"
        :title="type === 'whatsapp' ? 'WhatsApp' : 'Messenger'"
    >
        <Phone
            v-if="type === 'whatsapp'"
            :class="size === 'sm' ? 'size-2.5' : 'size-3'"
        />
        <MessageCircle v-else :class="size === 'sm' ? 'size-2.5' : 'size-3'" />
    </span>
</template>
