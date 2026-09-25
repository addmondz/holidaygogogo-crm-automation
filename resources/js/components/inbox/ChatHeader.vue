<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    ChevronDown,
    PanelRight,
    RotateCcw,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import ConversationController from '@/actions/App/Http/Controllers/Inbox/ConversationController';
import ContactAvatar from '@/components/inbox/ContactAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatPhone } from '@/lib/format';
import type { AgentOption, ConversationSummary } from '@/types';

const props = defineProps<{
    conversation: ConversationSummary;
    agents: AgentOption[];
    backUrl: string;
}>();

defineEmits<{ toggleDetails: [] }>();

const page = usePage();
const me = computed(() => page.props.auth.user);

function assign(userId: number | null): void {
    router.patch(
        ConversationController.assign(props.conversation.id).url,
        { user_id: userId },
        { preserveScroll: true, preserveState: true },
    );
}

function setStatus(status: 'open' | 'closed'): void {
    router.patch(
        ConversationController.status(props.conversation.id).url,
        { status },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <div class="flex items-center gap-2 border-b bg-background px-3 py-2">
        <Button
            as-child
            variant="ghost"
            size="icon"
            class="shrink-0 lg:hidden"
            aria-label="Back to chats"
        >
            <Link :href="backUrl" preserve-state preserve-scroll>
                <ArrowLeft />
            </Link>
        </Button>

        <ContactAvatar
            :name="conversation.contact.display_name"
            :avatar-url="conversation.contact.avatar_url"
            :channel="conversation.channel.type"
            size="sm"
        />

        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold">
                {{ conversation.contact.display_name }}
            </p>
            <p class="truncate text-xs text-muted-foreground">
                {{
                    formatPhone(conversation.contact.phone) ||
                    conversation.channel.type_label
                }}
                <template v-if="!conversation.window_open">
                    ·
                    <span class="text-amber-700 dark:text-amber-400"
                        >reply window closed</span
                    >
                </template>
            </p>
        </div>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="sm" class="max-w-40 shrink-0">
                    <UserRound />
                    <span class="hidden truncate sm:inline">{{
                        conversation.assignee?.name ?? 'Unassigned'
                    }}</span>
                    <ChevronDown class="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuLabel>Assign chat to</DropdownMenuLabel>
                <DropdownMenuItem
                    v-if="conversation.assignee?.id !== me.id"
                    @select="assign(me.id)"
                >
                    Me ({{ me.name }})
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="agent in agents.filter((a) => a.id !== me.id)"
                    :key="agent.id"
                    @select="assign(agent.id)"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="
                            agent.is_available
                                ? 'bg-green-500'
                                : 'bg-neutral-400'
                        "
                    />
                    {{ agent.name }}
                    <CheckCircle2
                        v-if="conversation.assignee?.id === agent.id"
                        class="ml-auto"
                    />
                </DropdownMenuItem>
                <template v-if="conversation.assignee">
                    <DropdownMenuSeparator />
                    <DropdownMenuItem @select="assign(null)">
                        Unassign
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

        <Button
            v-if="conversation.status === 'open'"
            variant="outline"
            size="sm"
            class="shrink-0"
            title="Close this chat when the enquiry is done. It reopens automatically if the customer writes again."
            @click="setStatus('closed')"
        >
            <CheckCircle2 />
            <span class="hidden sm:inline">Close</span>
        </Button>
        <Button
            v-else
            variant="outline"
            size="sm"
            class="shrink-0"
            @click="setStatus('open')"
        >
            <RotateCcw />
            <span class="hidden sm:inline">Reopen</span>
        </Button>

        <Button
            variant="ghost"
            size="icon"
            class="shrink-0"
            aria-label="Lead details"
            title="Lead details"
            @click="$emit('toggleDetails')"
        >
            <PanelRight />
        </Button>
    </div>
</template>
