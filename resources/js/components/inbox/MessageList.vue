<script setup lang="ts">
import { ArrowDown, Loader2 } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import MessageBubble from '@/components/inbox/MessageBubble.vue';
import { Button } from '@/components/ui/button';
import { dayLabel } from '@/lib/format';
import type { Message } from '@/types';

const props = defineProps<{
    messages: Message[];
    hasOlder: boolean;
    loadingOlder: boolean;
}>();

const emit = defineEmits<{ loadOlder: [] }>();

const scroller = ref<HTMLElement | null>(null);
const showJump = ref(false);

type Row =
    | { kind: 'day'; key: string; label: string }
    | { kind: 'message'; key: string; message: Message; showSender: boolean };

const rows = computed<Row[]>(() => {
    const result: Row[] = [];
    let lastDay = '';
    let previous: Message | null = null;

    for (const message of props.messages) {
        const day = new Date(message.created_at).toDateString();

        if (day !== lastDay) {
            result.push({
                kind: 'day',
                key: `day-${day}`,
                label: dayLabel(message.created_at),
            });
            lastDay = day;
            previous = null;
        }

        const sameSender =
            previous !== null &&
            previous.direction === message.direction &&
            previous.user?.id === message.user?.id;

        result.push({
            kind: 'message',
            key: `m-${message.id}`,
            message,
            showSender: !sameSender,
        });
        previous = message;
    }

    return result;
});

function isNearBottom(): boolean {
    const el = scroller.value;

    return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
}

function scrollToBottom(smooth = false): void {
    const el = scroller.value;

    if (el) {
        el.scrollTo({
            top: el.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto',
        });
    }

    showJump.value = false;
}

function onScroll(): void {
    if (isNearBottom()) {
        showJump.value = false;
    }
}

// Keep the view pinned to the newest message, unless the agent scrolled up.
watch(
    () => props.messages.at(-1)?.id,
    async (newest, previous) => {
        const wasNearBottom = isNearBottom();
        await nextTick();

        if (previous === undefined || wasNearBottom) {
            scrollToBottom(previous !== undefined);
        } else if (newest !== previous) {
            showJump.value = true;
        }
    },
);

// Keep the scroll position when older messages are added above.
watch(
    () => props.messages[0]?.id,
    async (first, previousFirst) => {
        const el = scroller.value;

        if (!el || previousFirst === undefined || first === previousFirst) {
            return;
        }

        const fromBottom = el.scrollHeight - el.scrollTop;
        await nextTick();
        el.scrollTop = el.scrollHeight - fromBottom;
    },
);

onMounted(() => scrollToBottom());

defineExpose({ scrollToBottom });
</script>

<template>
    <div class="relative min-h-0 flex-1">
        <div
            ref="scroller"
            class="h-full space-y-1.5 overflow-y-auto bg-muted/40 px-3 py-4 sm:px-6"
            @scroll.passive="onScroll"
        >
            <div v-if="hasOlder" class="flex justify-center pb-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="loadingOlder"
                    @click="emit('loadOlder')"
                >
                    <Loader2 v-if="loadingOlder" class="animate-spin" />
                    Load earlier messages
                </Button>
            </div>

            <p
                v-if="!messages.length"
                class="py-16 text-center text-sm text-muted-foreground"
            >
                No messages yet.
            </p>

            <template v-for="row in rows" :key="row.key">
                <div
                    v-if="row.kind === 'day'"
                    class="sticky top-0 z-10 flex justify-center py-2"
                >
                    <span
                        class="rounded-full bg-background/90 px-3 py-1 text-[11px] font-medium text-muted-foreground shadow-xs ring-1 ring-border backdrop-blur"
                        >{{ row.label }}</span
                    >
                </div>
                <MessageBubble
                    v-else
                    :message="row.message"
                    :show-sender="row.showSender"
                />
            </template>
        </div>

        <button
            v-if="showJump"
            type="button"
            class="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-1 rounded-full bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground shadow-lg"
            @click="scrollToBottom(true)"
        >
            <ArrowDown class="size-3.5" /> New messages
        </button>
    </div>
</template>
