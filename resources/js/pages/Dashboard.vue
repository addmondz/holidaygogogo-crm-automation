<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    Inbox,
    MessageCircleReply,
    MessagesSquare,
    Send,
    UserPlus,
    UserRound,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';

type Props = {
    stats: {
        open: number;
        unassigned: number;
        mine: number;
        needs_reply: number;
        new_leads_today: number;
        messages_in_today: number;
        messages_out_today: number;
    };
    workload: {
        id: number;
        name: string;
        is_available: boolean;
        open_count: number;
        needs_reply_count: number;
    }[];
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const page = usePage();
const firstName = computed(() => page.props.auth.user.name.split(' ')[0]);

const cards = computed(() => [
    {
        label: 'Waiting for a reply',
        value: props.stats.needs_reply,
        icon: MessageCircleReply,
        tone: 'text-amber-600 dark:text-amber-400',
    },
    {
        label: 'My open chats',
        value: props.stats.mine,
        icon: UserRound,
        tone: 'text-blue-600 dark:text-blue-400',
    },
    {
        label: 'Unassigned chats',
        value: props.stats.unassigned,
        icon: Inbox,
        tone: 'text-rose-600 dark:text-rose-400',
    },
    {
        label: 'Open chats',
        value: props.stats.open,
        icon: MessagesSquare,
        tone: 'text-foreground',
    },
    {
        label: 'New leads today',
        value: props.stats.new_leads_today,
        icon: UserPlus,
        tone: 'text-green-600 dark:text-green-400',
    },
    {
        label: 'Messages today',
        value: `${props.stats.messages_in_today} in · ${props.stats.messages_out_today} out`,
        icon: Send,
        tone: 'text-foreground',
    },
]);
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="`Hi ${firstName} 👋`"
            description="Here's what is happening in your inbox today."
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="card in cards"
                :key="card.label"
                class="flex items-center gap-4 rounded-xl border bg-card p-5"
            >
                <div
                    class="flex size-11 items-center justify-center rounded-lg bg-muted"
                    :class="card.tone"
                >
                    <component :is="card.icon" class="size-5" />
                </div>
                <div>
                    <p class="text-sm text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ card.value }}
                    </p>
                </div>
            </div>
        </div>

        <section v-if="workload.length" class="rounded-xl border bg-card">
            <header class="flex items-center gap-2 border-b px-5 py-4">
                <UsersRound class="size-4 text-muted-foreground" />
                <h2 class="font-medium">Agent workload</h2>
            </header>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-muted-foreground">
                        <tr class="border-b">
                            <th class="px-5 py-2 font-medium">Agent</th>
                            <th class="px-5 py-2 font-medium">Open chats</th>
                            <th class="px-5 py-2 font-medium">
                                Waiting for reply
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="agent in workload"
                            :key="agent.id"
                            class="border-b last:border-0"
                        >
                            <td class="px-5 py-3">
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-2 rounded-full"
                                        :class="
                                            agent.is_available
                                                ? 'bg-green-500'
                                                : 'bg-neutral-400'
                                        "
                                    />
                                    {{ agent.name }}
                                </span>
                            </td>
                            <td class="px-5 py-3 tabular-nums">
                                {{ agent.open_count }}
                            </td>
                            <td class="px-5 py-3 tabular-nums">
                                <span
                                    :class="
                                        agent.needs_reply_count > 0
                                            ? 'font-medium text-amber-600 dark:text-amber-400'
                                            : ''
                                    "
                                >
                                    {{ agent.needs_reply_count }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
