<script setup lang="ts">
import { FileText, Loader2, MapPin } from '@lucide/vue';
import { computed } from 'vue';
import MessageTicks from '@/components/inbox/MessageTicks.vue';
import { messageTime } from '@/lib/format';
import { formatMessage } from '@/lib/messageText';
import type { Message } from '@/types';

const props = defineProps<{
    message: Message;
    showSender: boolean;
}>();

const outbound = computed(() => props.message.direction === 'outbound');
const media = computed(() => props.message.media);
const mediaUrl = computed(() => media.value?.url ?? null);
const downloadUrl = computed(() =>
    mediaUrl.value ? `${mediaUrl.value}?download=1` : null,
);
const html = computed(() => formatMessage(props.message.body));

const senderLabel = computed(() => {
    if (!outbound.value) {
        return null;
    }

    if (props.message.user) {
        return props.message.user.name;
    }

    if (props.message.meta?.sent_outside_crm) {
        return 'Sent from phone / Page inbox';
    }

    return props.message.meta?.broadcast ? 'Blast' : null;
});
</script>

<template>
    <div v-if="message.direction === 'event'" class="my-2 flex justify-center">
        <span
            class="rounded-full bg-muted px-3 py-1 text-[11px] text-muted-foreground"
        >
            {{ message.body }} · {{ messageTime(message.created_at) }}
        </span>
    </div>

    <div
        v-else
        class="flex"
        :class="outbound ? 'justify-end' : 'justify-start'"
    >
        <div
            class="max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-xs sm:max-w-[70%]"
            :class="
                outbound
                    ? 'rounded-br-md bg-emerald-100 text-emerald-950 dark:bg-emerald-900/60 dark:text-emerald-50'
                    : 'rounded-bl-md bg-background ring-1 ring-border'
            "
        >
            <p
                v-if="showSender && senderLabel"
                class="mb-0.5 text-[11px] font-medium text-emerald-700 dark:text-emerald-300"
            >
                {{ senderLabel }}
            </p>

            <p
                v-if="message.type === 'template'"
                class="mb-1 text-[10px] font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-300"
            >
                Template · {{ message.meta?.template?.name }}
            </p>

            <!-- Media -->
            <template v-if="media">
                <div
                    v-if="!mediaUrl"
                    class="mb-1 flex items-center gap-2 rounded-lg bg-muted/60 px-3 py-4 text-xs text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" />
                    Loading {{ message.type }}…
                </div>
                <a
                    v-else-if="
                        message.type === 'image' || message.type === 'sticker'
                    "
                    :href="mediaUrl"
                    target="_blank"
                    rel="noopener"
                    class="mb-1 block"
                >
                    <img
                        :src="mediaUrl"
                        :alt="media.filename ?? 'Photo'"
                        class="max-h-72 rounded-lg object-cover"
                        :class="
                            message.type === 'sticker' ? 'max-w-32' : 'w-full'
                        "
                        loading="lazy"
                    />
                </a>
                <video
                    v-else-if="message.type === 'video'"
                    :src="mediaUrl"
                    controls
                    preload="metadata"
                    class="mb-1 max-h-72 w-full rounded-lg"
                />
                <audio
                    v-else-if="message.type === 'audio'"
                    :src="mediaUrl"
                    controls
                    preload="metadata"
                    class="mb-1 w-64 max-w-full"
                />
                <a
                    v-else
                    :href="downloadUrl ?? undefined"
                    class="mb-1 flex items-center gap-3 rounded-lg bg-muted/60 p-3 hover:bg-muted"
                >
                    <FileText class="size-8 shrink-0 text-muted-foreground" />
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{
                            media.filename ?? 'Document'
                        }}</span>
                        <span class="text-xs text-muted-foreground"
                            >Download</span
                        >
                    </span>
                </a>
            </template>

            <p
                v-if="message.type === 'location'"
                class="mb-1 flex items-center gap-1 text-xs font-medium"
            >
                <MapPin class="size-3.5" /> Location
            </p>

            <p
                v-if="message.type === 'reaction'"
                class="text-xs text-muted-foreground"
            >
                Reacted {{ message.body }} to a message
            </p>
            <!-- eslint-disable-next-line vue/no-v-html -- escaped in formatMessage() -->
            <div
                v-else-if="html"
                class="break-words whitespace-pre-wrap"
                :class="{
                    'text-muted-foreground italic':
                        message.type === 'unsupported',
                }"
                v-html="html"
            />

            <div
                class="mt-1 flex items-center justify-end gap-1 text-[10px] text-muted-foreground"
            >
                <span>{{ messageTime(message.created_at) }}</span>
                <MessageTicks v-if="outbound" :status="message.status" />
            </div>

            <p
                v-if="message.status === 'failed'"
                class="mt-1 rounded-md bg-red-50 px-2 py-1 text-xs text-red-700 dark:bg-red-950 dark:text-red-300"
            >
                Not delivered: {{ message.error ?? 'unknown error' }}
            </p>
        </div>
    </div>
</template>
