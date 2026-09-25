<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Filter, Inbox, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ContactAvatar from '@/components/inbox/ContactAvatar.vue';
import TagBadge from '@/components/inbox/TagBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    applyFilters,
    conversationUrl,
    type InboxFilters,
} from '@/composables/useInboxFilters';
import { useInitials } from '@/composables/useInitials';
import { listTime } from '@/lib/format';
import type {
    AgentOption,
    ChannelSummary,
    ConversationSummary,
    Tag,
} from '@/types';

const props = defineProps<{
    conversations: ConversationSummary[];
    counts: { all: number; mine: number; unassigned: number };
    filters: InboxFilters;
    hasMore: boolean;
    channels: ChannelSummary[];
    tags: Tag[];
    agents: AgentOption[];
    selectedId: number | null;
}>();

const page = usePage();
const isAdmin = computed(() => page.props.auth.isAdmin);
const { getInitials } = useInitials();

const search = ref(props.filters.q);

watch(
    () => props.filters.q,
    (q) => (search.value = q),
);

const update = (changes: Partial<InboxFilters>) =>
    applyFilters({ ...props.filters, limit: 50, ...changes }, props.selectedId);

const onSearch = useDebounceFn(() => update({ q: search.value.trim() }), 350);

const folders = computed(() => [
    { key: 'all' as const, label: 'All', count: props.counts.all },
    { key: 'mine' as const, label: 'Mine', count: props.counts.mine },
    {
        key: 'unassigned' as const,
        label: 'Unassigned',
        count: props.counts.unassigned,
    },
]);

const activeFilterCount = computed(
    () =>
        [
            props.filters.status === 'closed',
            props.filters.channel,
            props.filters.tag,
            props.filters.agent,
        ].filter(Boolean).length,
);

function selectValue(event: Event): number | null {
    const value = (event.target as HTMLSelectElement).value;

    return value ? Number(value) : null;
}
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="space-y-3 border-b p-3">
            <div class="flex gap-1 rounded-lg bg-muted p-1">
                <button
                    v-for="folder in folders"
                    :key="folder.key"
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium transition"
                    :class="
                        filters.folder === folder.key
                            ? 'bg-background shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="update({ folder: folder.key })"
                >
                    {{ folder.label }}
                    <span
                        class="rounded-full px-1.5 text-[10px] tabular-nums"
                        :class="
                            folder.key === 'unassigned' && folder.count > 0
                                ? 'bg-rose-500 text-white'
                                : 'bg-muted-foreground/15'
                        "
                        >{{ folder.count }}</span
                    >
                </button>
            </div>

            <div class="flex gap-2">
                <div class="relative flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Search name, phone, email"
                        class="h-9 pl-8"
                        @input="onSearch"
                    />
                </div>

                <Popover>
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="relative shrink-0"
                            aria-label="Filters"
                        >
                            <Filter />
                            <span
                                v-if="activeFilterCount"
                                class="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground"
                                >{{ activeFilterCount }}</span
                            >
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" class="w-64 space-y-3">
                        <div class="grid gap-1.5">
                            <Label class="text-xs">Chats</Label>
                            <div class="flex gap-1 rounded-md bg-muted p-1">
                                <button
                                    v-for="status in [
                                        'open',
                                        'closed',
                                    ] as const"
                                    :key="status"
                                    type="button"
                                    class="flex-1 rounded px-2 py-1 text-xs capitalize"
                                    :class="
                                        filters.status === status
                                            ? 'bg-background shadow-sm'
                                            : 'text-muted-foreground'
                                    "
                                    @click="update({ status })"
                                >
                                    {{ status }}
                                </button>
                            </div>
                        </div>
                        <div class="grid gap-1.5">
                            <Label class="text-xs" for="filter-channel"
                                >Channel</Label
                            >
                            <select
                                id="filter-channel"
                                class="h-8 rounded-md border bg-transparent px-2 text-sm"
                                :value="filters.channel ?? ''"
                                @change="
                                    update({ channel: selectValue($event) })
                                "
                            >
                                <option value="">All channels</option>
                                <option
                                    v-for="channel in channels"
                                    :key="channel.id"
                                    :value="channel.id"
                                >
                                    {{ channel.name }} ({{
                                        channel.type_label
                                    }})
                                </option>
                            </select>
                        </div>
                        <div class="grid gap-1.5">
                            <Label class="text-xs" for="filter-tag">Tag</Label>
                            <select
                                id="filter-tag"
                                class="h-8 rounded-md border bg-transparent px-2 text-sm"
                                :value="filters.tag ?? ''"
                                @change="update({ tag: selectValue($event) })"
                            >
                                <option value="">Any tag</option>
                                <option
                                    v-for="tag in tags"
                                    :key="tag.id"
                                    :value="tag.id"
                                >
                                    {{ tag.name }}
                                </option>
                            </select>
                        </div>
                        <div v-if="isAdmin" class="grid gap-1.5">
                            <Label class="text-xs" for="filter-agent"
                                >Assigned to</Label
                            >
                            <select
                                id="filter-agent"
                                class="h-8 rounded-md border bg-transparent px-2 text-sm"
                                :value="filters.agent ?? ''"
                                @change="update({ agent: selectValue($event) })"
                            >
                                <option value="">Anyone</option>
                                <option
                                    v-for="agent in agents"
                                    :key="agent.id"
                                    :value="agent.id"
                                >
                                    {{ agent.name }}
                                </option>
                            </select>
                        </div>
                        <Button
                            v-if="activeFilterCount"
                            variant="ghost"
                            size="sm"
                            class="w-full"
                            @click="
                                update({
                                    status: 'open',
                                    channel: null,
                                    tag: null,
                                    agent: null,
                                })
                            "
                        >
                            <X /> Clear filters
                        </Button>
                    </PopoverContent>
                </Popover>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                v-if="!conversations.length"
                class="flex flex-col items-center gap-2 px-6 py-16 text-center text-sm text-muted-foreground"
            >
                <Inbox class="size-8 opacity-50" />
                <p v-if="filters.q || activeFilterCount">
                    No chats match your search.
                </p>
                <p v-else-if="filters.folder === 'mine'">
                    No chats are assigned to you yet.
                </p>
                <p v-else>
                    No chats yet. New WhatsApp and Messenger messages will
                    appear here.
                </p>
            </div>

            <Link
                v-for="conversation in conversations"
                :key="conversation.id"
                :href="conversationUrl(conversation.id, filters)"
                preserve-scroll
                preserve-state
                class="flex gap-3 border-b px-3 py-3 transition hover:bg-muted/60"
                :class="{
                    'bg-muted': conversation.id === selectedId,
                }"
            >
                <ContactAvatar
                    :name="conversation.contact.display_name"
                    :avatar-url="conversation.contact.avatar_url"
                    :channel="conversation.channel.type"
                />
                <div class="min-w-0 flex-1">
                    <div class="flex items-baseline justify-between gap-2">
                        <span
                            class="truncate text-sm"
                            :class="
                                conversation.unread_count
                                    ? 'font-semibold'
                                    : 'font-medium'
                            "
                            >{{ conversation.contact.display_name }}</span
                        >
                        <span
                            class="shrink-0 text-[11px] text-muted-foreground tabular-nums"
                            :class="{
                                'font-semibold text-green-600 dark:text-green-400':
                                    conversation.unread_count,
                            }"
                            >{{ listTime(conversation.last_message_at) }}</span
                        >
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="truncate text-xs"
                            :class="
                                conversation.unread_count
                                    ? 'text-foreground'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ conversation.last_message_preview || ' ' }}
                        </p>
                        <span
                            v-if="conversation.unread_count"
                            class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-green-500 px-1.5 text-[10px] font-semibold text-white"
                            >{{ conversation.unread_count }}</span
                        >
                    </div>
                    <div
                        v-if="
                            conversation.contact.tags.length ||
                            conversation.assignee
                        "
                        class="mt-1.5 flex items-center gap-1 overflow-hidden"
                    >
                        <TagBadge
                            v-for="tag in conversation.contact.tags.slice(0, 2)"
                            :key="tag.id"
                            :tag="tag"
                        />
                        <span
                            v-if="conversation.contact.tags.length > 2"
                            class="text-[10px] text-muted-foreground"
                            >+{{ conversation.contact.tags.length - 2 }}</span
                        >
                        <span
                            v-if="conversation.assignee"
                            class="ml-auto flex size-5 shrink-0 items-center justify-center rounded-full bg-muted-foreground/15 text-[9px] font-semibold"
                            :title="`Assigned to ${conversation.assignee.name}`"
                            >{{ getInitials(conversation.assignee.name) }}</span
                        >
                    </div>
                </div>
            </Link>

            <div v-if="hasMore" class="p-3">
                <Button
                    variant="outline"
                    size="sm"
                    class="w-full"
                    @click="update({ limit: filters.limit + 50 })"
                    >Load more chats</Button
                >
            </div>
        </div>
    </div>
</template>
