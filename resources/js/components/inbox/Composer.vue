<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    FileText,
    Paperclip,
    SendHorizontal,
    Sparkles,
    X,
    Zap,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import MessageController from '@/actions/App/Http/Controllers/Inbox/MessageController';
import { Button } from '@/components/ui/button';
import { api, ApiError } from '@/lib/api';
import { timeLeft } from '@/lib/format';
import { fillPlaceholders } from '@/lib/messageText';
import type { ConversationSummary, Message, QuickReply } from '@/types';

const props = defineProps<{
    conversation: ConversationSummary;
    canReply: boolean;
    quickReplies: QuickReply[];
    hasTemplates: boolean;
}>();

const emit = defineEmits<{
    sent: [message: Message];
    openTemplates: [];
}>();

const page = usePage();
const agentName = computed(() => page.props.auth.user.name);

const text = ref('');
const file = ref<File | null>(null);
const quickReply = ref<QuickReply | null>(null);
const sending = ref(false);
const error = ref<string | null>(null);
const textarea = ref<HTMLTextAreaElement | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);

// Quick reply picker: opens when the message starts with "/".
const pickerIndex = ref(0);
const pickerOpen = computed(() => /^\/\S*$/.test(text.value));
const pickerMatches = computed(() => {
    const term = text.value.slice(1).toLowerCase();

    return props.quickReplies
        .filter(
            (reply) =>
                reply.shortcut.includes(term) ||
                reply.title.toLowerCase().includes(term),
        )
        .slice(0, 8);
});

watch(pickerMatches, () => (pickerIndex.value = 0));

// Reset the draft when switching chats.
watch(
    () => props.conversation.id,
    () => {
        text.value = '';
        file.value = null;
        quickReply.value = null;
        error.value = null;
    },
);

function autoGrow(): void {
    const el = textarea.value;

    if (el) {
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 180)}px`;
    }
}

watch(text, () => nextTick(autoGrow));

function useQuickReply(reply: QuickReply): void {
    text.value = fillPlaceholders(
        reply.body,
        props.conversation.contact,
        agentName.value,
    );
    quickReply.value = reply.attachment ? reply : null;
    file.value = null;
    nextTick(() => textarea.value?.focus());
}

function onKeydown(event: KeyboardEvent): void {
    if (pickerOpen.value && pickerMatches.value.length) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            pickerIndex.value =
                (pickerIndex.value + 1) % pickerMatches.value.length;

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            pickerIndex.value =
                (pickerIndex.value - 1 + pickerMatches.value.length) %
                pickerMatches.value.length;

            return;
        }

        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            useQuickReply(pickerMatches.value[pickerIndex.value]);

            return;
        }

        if (event.key === 'Escape') {
            text.value = '';

            return;
        }
    }

    // Enter sends, Shift+Enter adds a new line (on phones, Enter adds a new line).
    const isTouch = window.matchMedia('(pointer: coarse)').matches;

    if (
        event.key === 'Enter' &&
        !event.shiftKey &&
        !isTouch &&
        !event.isComposing
    ) {
        event.preventDefault();
        send();
    }
}

function onFileChosen(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files?.[0];

    if (chosen) {
        file.value = chosen;
        quickReply.value = null;
    }

    (event.target as HTMLInputElement).value = '';
}

function onPaste(event: ClipboardEvent): void {
    const pasted = Array.from(event.clipboardData?.files ?? [])[0];

    if (pasted) {
        event.preventDefault();
        file.value = pasted;
        quickReply.value = null;
    }
}

async function send(): Promise<void> {
    const body = text.value.trim();

    if (sending.value || (!body && !file.value && !quickReply.value)) {
        return;
    }

    sending.value = true;
    error.value = null;

    const data = new FormData();
    data.append('body', body);

    if (file.value) {
        data.append('file', file.value);
    }

    if (quickReply.value) {
        data.append('quick_reply_id', String(quickReply.value.id));
    }

    try {
        const response = await api<{ message: Message }>(
            'post',
            MessageController.store(props.conversation.id).url,
            data,
        );

        emit('sent', response.message);
        text.value = '';
        file.value = null;
        quickReply.value = null;
        nextTick(() => textarea.value?.focus());
    } catch (e) {
        error.value =
            e instanceof ApiError ? e.message : 'Could not send the message.';
    } finally {
        sending.value = false;
    }
}

const windowLabel = computed(() =>
    props.conversation.window_open
        ? `Reply window: ${timeLeft(props.conversation.window_expires_at)}`
        : null,
);
</script>

<template>
    <div class="border-t bg-background p-3">
        <!-- 24-hour window closed -->
        <div
            v-if="!canReply"
            class="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm sm:flex-row sm:items-center dark:border-amber-900 dark:bg-amber-950/50"
        >
            <p class="flex-1 text-amber-900 dark:text-amber-200">
                <template v-if="conversation.channel.type === 'whatsapp'">
                    It's been more than 24 hours since this customer's last
                    message, so WhatsApp only allows an
                    <strong>approved template</strong>. When they reply, you can
                    chat freely again.
                </template>
                <template v-else>
                    Messenger only allows replies within 24 hours of the
                    customer's last message. Wait for them to message the Page
                    again.
                </template>
            </p>
            <Button
                v-if="conversation.channel.type === 'whatsapp'"
                size="sm"
                @click="emit('openTemplates')"
            >
                <Sparkles /> Send template
            </Button>
        </div>

        <template v-else>
            <div class="relative">
                <!-- Quick reply picker -->
                <div
                    v-if="pickerOpen"
                    class="absolute right-0 bottom-full left-0 z-20 mb-2 max-h-72 overflow-y-auto rounded-lg border bg-popover p-1 shadow-lg"
                >
                    <p
                        v-if="!pickerMatches.length"
                        class="px-3 py-2 text-sm text-muted-foreground"
                    >
                        No quick reply matches "{{ text }}". Add some under
                        Quick replies.
                    </p>
                    <button
                        v-for="(reply, index) in pickerMatches"
                        :key="reply.id"
                        type="button"
                        class="flex w-full flex-col items-start gap-0.5 rounded-md px-3 py-2 text-left"
                        :class="
                            index === pickerIndex
                                ? 'bg-accent'
                                : 'hover:bg-accent/60'
                        "
                        @mouseenter="pickerIndex = index"
                        @click="useQuickReply(reply)"
                    >
                        <span
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >/{{ reply.shortcut }}</span
                            >
                            {{ reply.title }}
                            <Paperclip
                                v-if="reply.attachment"
                                class="size-3 text-muted-foreground"
                            />
                        </span>
                        <span
                            class="line-clamp-1 text-xs text-muted-foreground"
                            >{{ reply.body }}</span
                        >
                    </button>
                </div>

                <!-- Attachment preview -->
                <div
                    v-if="file || quickReply"
                    class="mb-2 flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                >
                    <FileText class="size-4 shrink-0 text-muted-foreground" />
                    <span class="min-w-0 flex-1 truncate">{{
                        file?.name ?? quickReply?.attachment?.name
                    }}</span>
                    <button
                        type="button"
                        class="rounded p-0.5 hover:bg-background"
                        aria-label="Remove attachment"
                        @click="
                            file = null;
                            quickReply = null;
                        "
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div
                    class="flex items-end gap-2 rounded-xl border bg-background p-1.5 focus-within:ring-2 focus-within:ring-ring/40"
                >
                    <input
                        ref="fileInput"
                        type="file"
                        class="hidden"
                        @change="onFileChosen"
                    />
                    <Button
                        variant="ghost"
                        size="icon"
                        class="shrink-0"
                        aria-label="Attach a file"
                        title="Attach a photo or file"
                        @click="fileInput?.click()"
                    >
                        <Paperclip />
                    </Button>
                    <textarea
                        ref="textarea"
                        v-model="text"
                        rows="1"
                        class="max-h-44 min-h-9 flex-1 resize-none bg-transparent px-1 py-2 text-sm outline-none placeholder:text-muted-foreground"
                        :placeholder="
                            file || quickReply
                                ? 'Add a caption (optional)'
                                : 'Type a message, or / for quick replies'
                        "
                        @keydown="onKeydown"
                        @paste="onPaste"
                    />
                    <Button
                        v-if="
                            conversation.channel.type === 'whatsapp' &&
                            hasTemplates
                        "
                        variant="ghost"
                        size="icon"
                        class="shrink-0"
                        aria-label="Send a template"
                        title="Send an approved WhatsApp template"
                        @click="emit('openTemplates')"
                    >
                        <Sparkles />
                    </Button>
                    <Button
                        size="icon"
                        class="shrink-0"
                        aria-label="Send"
                        :disabled="
                            sending || (!text.trim() && !file && !quickReply)
                        "
                        @click="send"
                    >
                        <SendHorizontal />
                    </Button>
                </div>
            </div>

            <div
                class="mt-1.5 flex items-center justify-between gap-2 px-1 text-[11px] text-muted-foreground"
            >
                <span class="hidden items-center gap-1 sm:flex">
                    <Zap class="size-3" /> Type
                    <kbd class="rounded bg-muted px-1">/</kbd>
                    for quick replies · Shift+Enter for a new line
                </span>
                <span v-if="windowLabel" class="ml-auto">{{
                    windowLabel
                }}</span>
            </div>
            <p v-if="error" class="mt-1 px-1 text-xs text-red-600">
                {{ error }}
            </p>
        </template>
    </div>
</template>
