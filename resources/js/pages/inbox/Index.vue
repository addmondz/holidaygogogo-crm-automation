<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { useDebounceFn, useMediaQuery } from '@vueuse/core';
import { Bell, MessagesSquare } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import MessageController from '@/actions/App/Http/Controllers/Inbox/MessageController';
import ChatHeader from '@/components/inbox/ChatHeader.vue';
import Composer from '@/components/inbox/Composer.vue';
import ContactPanel from '@/components/inbox/ContactPanel.vue';
import ConversationList from '@/components/inbox/ConversationList.vue';
import MessageList from '@/components/inbox/MessageList.vue';
import SimulatorDialog from '@/components/inbox/SimulatorDialog.vue';
import TemplateDialog from '@/components/inbox/TemplateDialog.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import { filterQuery, type InboxFilters } from '@/composables/useInboxFilters';
import { realtimeEnabled, useChannelListener } from '@/composables/useRealtime';
import { api } from '@/lib/api';
import {
    desktopNotificationsSupported,
    playNotificationSound,
    showDesktopNotification,
} from '@/lib/notify';
import type {
    AgentOption,
    ChannelSummary,
    ConversationSummary,
    Message,
    Note,
    Option,
    QuickReply,
    Tag,
    TemplateVariable,
    WhatsappTemplate,
} from '@/types';

type Selected = {
    conversation: ConversationSummary;
    messages: Message[];
    has_older: boolean;
    can_reply: boolean;
    notes: Note[];
    templates: (WhatsappTemplate & { variables: TemplateVariable[] })[];
    other_chats: { id: number; channel: ChannelSummary }[];
};

const props = defineProps<{
    filters: InboxFilters;
    conversations: ConversationSummary[];
    hasMore: boolean;
    counts: { all: number; mine: number; unassigned: number };
    options: {
        channels: ChannelSummary[];
        tags: Tag[];
        agents: AgentOption[];
        statuses: Option[];
    };
    quickReplies: QuickReply[];
    selected: Selected | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inbox', href: InboxController.index() }],
        fullHeight: true,
    },
});

const page = usePage();
const me = computed(() => page.props.auth.user);
const isDesktop = useMediaQuery('(min-width: 1280px)');

const selectedId = computed(() => props.selected?.conversation.id ?? null);
const backUrl = computed(
    () => InboxController.index({ query: filterQuery(props.filters) }).url,
);

// --- Messages of the open chat ------------------------------------------------
// Kept locally so live updates and "load earlier" don't need a full reload.

const messages = ref<Message[]>([]);
const hasOlder = ref(false);
const loadingOlder = ref(false);

function upsertMessages(incoming: Message[]): void {
    const byId = new Map(messages.value.map((m) => [m.id, m]));

    for (const message of incoming) {
        if (message.conversation_id === selectedId.value) {
            byId.set(message.id, message);
        }
    }

    messages.value = [...byId.values()].sort((a, b) => a.id - b.id);
}

watch(
    () => props.selected,
    (selected, previous) => {
        if (!selected) {
            messages.value = [];

            return;
        }

        if (selected.conversation.id !== previous?.conversation.id) {
            messages.value = [...selected.messages];
            hasOlder.value = selected.has_older;
        } else {
            upsertMessages(selected.messages);
        }
    },
    { immediate: true },
);

async function loadOlder(): Promise<void> {
    if (!selectedId.value || loadingOlder.value) {
        return;
    }

    loadingOlder.value = true;

    try {
        const response = await api<{ messages: Message[]; has_older: boolean }>(
            'get',
            MessageController.index(selectedId.value, {
                query: { before: messages.value[0]?.id },
            }).url,
        );
        upsertMessages(response.messages);
        hasOlder.value = response.has_older;
    } finally {
        loadingOlder.value = false;
    }
}

// --- Live updates -------------------------------------------------------------

const refreshList = useDebounceFn(() => {
    router.reload({ only: ['conversations', 'counts', 'hasMore'] });
}, 400);

const refreshSelected = useDebounceFn(() => {
    router.reload({ only: ['selected'] });
}, 400);

const { listen } = useChannelListener();

listen<{ id: number; assigned_user_id: number | null; inbound: boolean }>(
    'inbox',
    '.conversation.changed',
    (event) => {
        refreshList();

        if (event.id === selectedId.value) {
            refreshSelected();
        }

        const relevant =
            event.assigned_user_id === me.value.id ||
            (event.assigned_user_id === null && event.inbound);

        if (
            event.inbound &&
            relevant &&
            (document.hidden || event.id !== selectedId.value)
        ) {
            playNotificationSound();
            showDesktopNotification(
                'New message',
                'A customer sent a new message.',
                () => router.visit(InboxController.show(event.id).url),
            );
        }
    },
);

let conversationStop: (() => void) | null = null;

watch(
    selectedId,
    (id) => {
        conversationStop?.();
        conversationStop = id
            ? listen<Message>(
                  `conversation.${id}`,
                  '.message.saved',
                  (message) => upsertMessages([message]),
              )
            : null;
    },
    { immediate: true },
);

// Without Reverb, refresh every 10 seconds instead.
const polling = !realtimeEnabled();

if (polling) {
    usePoll(10000, {
        only: ['conversations', 'counts', 'hasMore', 'selected'],
    });
}

// --- Desktop notifications ------------------------------------------------------

const notificationPermission = ref(
    desktopNotificationsSupported() ? Notification.permission : 'denied',
);

async function enableNotifications(): Promise<void> {
    notificationPermission.value = await Notification.requestPermission();
    playNotificationSound();
}

// --- Panels -----------------------------------------------------------------------

const showDetails = ref(true);
const detailsOpenMobile = ref(false);
const templateDialogOpen = ref(false);

function toggleDetails(): void {
    if (isDesktop.value) {
        showDetails.value = !showDetails.value;
    } else {
        detailsOpenMobile.value = true;
    }
}

function onSent(message: Message): void {
    upsertMessages([message]);
    refreshList();
    // Sending may have claimed the chat, so refresh the header too.
    refreshSelected();
}

const title = computed(() => {
    const unread = props.conversations.reduce(
        (sum, c) => sum + (c.unread_count ? 1 : 0),
        0,
    );

    return unread ? `(${unread}) Inbox` : 'Inbox';
});
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-0 flex-1 overflow-hidden">
        <!-- Chat list -->
        <aside
            class="w-full shrink-0 flex-col border-r md:w-80 lg:flex xl:w-96"
            :class="selected ? 'hidden' : 'flex'"
        >
            <ConversationList
                :conversations="conversations"
                :counts="counts"
                :filters="filters"
                :has-more="hasMore"
                :channels="options.channels"
                :tags="options.tags"
                :agents="options.agents"
                :selected-id="selectedId"
            />
            <div
                v-if="$page.props.crm.demoMode && $page.props.auth.isAdmin"
                class="border-t p-3"
            >
                <SimulatorDialog :channels="options.channels" />
            </div>
            <div
                v-if="notificationPermission === 'default'"
                class="border-t p-3"
            >
                <Button
                    variant="ghost"
                    size="sm"
                    class="w-full"
                    @click="enableNotifications"
                >
                    <Bell /> Turn on desktop notifications
                </Button>
            </div>
        </aside>

        <!-- Open chat -->
        <section v-if="selected" class="flex min-w-0 flex-1 flex-col">
            <ChatHeader
                :conversation="selected.conversation"
                :agents="options.agents"
                :back-url="backUrl"
                @toggle-details="toggleDetails"
            />
            <MessageList
                :messages="messages"
                :has-older="hasOlder"
                :loading-older="loadingOlder"
                @load-older="loadOlder"
            />
            <Composer
                :conversation="selected.conversation"
                :can-reply="selected.can_reply"
                :quick-replies="quickReplies"
                :has-templates="selected.templates.length > 0"
                @sent="onSent"
                @open-templates="templateDialogOpen = true"
            />
            <TemplateDialog
                v-model:open="templateDialogOpen"
                :conversation="selected.conversation"
                :templates="selected.templates"
                @sent="onSent"
            />
        </section>

        <section
            v-else
            class="hidden flex-1 flex-col items-center justify-center gap-3 p-8 text-center text-muted-foreground lg:flex"
        >
            <MessagesSquare class="size-12 opacity-40" />
            <p class="font-medium text-foreground">
                Select a chat to start replying
            </p>
            <p class="max-w-sm text-sm">
                New WhatsApp and Messenger messages appear on the left
                {{ polling ? 'within a few seconds' : 'instantly' }}.
            </p>
        </section>

        <!-- Lead details -->
        <aside
            v-if="selected && showDetails && isDesktop"
            class="w-80 shrink-0 border-l"
        >
            <ContactPanel
                :contact="selected.conversation.contact"
                :notes="selected.notes"
                :tags="options.tags"
                :statuses="options.statuses"
                :other-chats="selected.other_chats"
                @close="showDetails = false"
            />
        </aside>

        <Sheet v-if="selected && !isDesktop" v-model:open="detailsOpenMobile">
            <SheetContent side="right" class="w-full p-0 sm:max-w-sm">
                <ContactPanel
                    :contact="selected.conversation.contact"
                    :notes="selected.notes"
                    :tags="options.tags"
                    :statuses="options.statuses"
                    :other-chats="selected.other_chats"
                    @close="detailsOpenMobile = false"
                />
            </SheetContent>
        </Sheet>
    </div>
</template>
