<script setup lang="ts">
import { AlertCircle, Check, CheckCheck, Clock } from '@lucide/vue';
import type { Message } from '@/types';

defineProps<{ status: Message['status'] }>();
</script>

<template>
    <Clock
        v-if="status === 'pending'"
        class="size-3.5 opacity-70"
        aria-label="Sending"
    />
    <Check
        v-else-if="status === 'sent'"
        class="size-3.5 opacity-70"
        aria-label="Sent"
    />
    <CheckCheck
        v-else-if="status === 'delivered'"
        class="size-3.5 opacity-70"
        aria-label="Delivered"
    />
    <CheckCheck
        v-else-if="status === 'read'"
        class="size-3.5 text-sky-500"
        aria-label="Read"
    />
    <AlertCircle
        v-else-if="status === 'failed'"
        class="size-3.5 text-red-500"
        aria-label="Failed"
    />
</template>
