<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AgentController from '@/actions/App/Http/Controllers/Admin/AgentController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { relativeTime } from '@/lib/format';
import type { Option } from '@/types';

type Agent = {
    id: number;
    name: string;
    email: string;
    role: 'admin' | 'agent';
    is_active: boolean;
    is_available: boolean;
    open_chats_count: number;
    last_seen_at: string | null;
};

defineProps<{
    agents: Agent[];
    roles: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Agents', href: AgentController.index() }],
    },
});

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const dialogOpen = ref(false);
const editing = ref<Agent | null>(null);
const deleting = ref<Agent | null>(null);

const form = useForm({
    name: '',
    email: '',
    role: 'agent',
    password: '',
    is_active: true,
    is_available: true,
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(agent: Agent) {
    editing.value = agent;
    form.clearErrors();
    form.name = agent.name;
    form.email = agent.email;
    form.role = agent.role;
    form.password = '';
    form.is_active = agent.is_active;
    form.is_available = agent.is_available;
    dialogOpen.value = true;
}

function submit() {
    const action = editing.value
        ? AgentController.update(editing.value.id)
        : AgentController.store();

    form.submit(action, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset();
        },
    });
}

function confirmDelete() {
    if (!deleting.value) {
        return;
    }

    router.delete(AgentController.destroy(deleting.value.id).url, {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
}
</script>

<template>
    <Head title="Agents" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Agents"
                description="Everyone who can log in and reply to leads. Admins can also manage settings and send blasts."
            />
            <Button @click="openCreate"> <Plus /> Add agent </Button>
        </div>

        <div class="overflow-x-auto rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Role</th>
                        <th class="px-4 py-3 font-medium">Open chats</th>
                        <th class="px-4 py-3 font-medium">Auto-assign</th>
                        <th class="px-4 py-3 font-medium">Last active</th>
                        <th class="px-4 py-3" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="agent in agents"
                        :key="agent.id"
                        class="border-b last:border-0"
                        :class="{ 'opacity-60': !agent.is_active }"
                    >
                        <td class="px-4 py-3">
                            <div class="font-medium">
                                {{ agent.name }}
                                <span
                                    v-if="agent.id === currentUserId"
                                    class="text-xs text-muted-foreground"
                                    >(you)</span
                                >
                            </div>
                            <div class="text-muted-foreground">
                                {{ agent.email }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    agent.role === 'admin'
                                        ? 'default'
                                        : 'secondary'
                                "
                            >
                                {{ agent.role === 'admin' ? 'Admin' : 'Agent' }}
                            </Badge>
                            <Badge
                                v-if="!agent.is_active"
                                variant="outline"
                                class="ml-1"
                                >Deactivated</Badge
                            >
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ agent.open_chats_count }}
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1.5 text-muted-foreground"
                            >
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        agent.is_available && agent.is_active
                                            ? 'bg-green-500'
                                            : 'bg-neutral-400'
                                    "
                                />
                                {{
                                    agent.is_available && agent.is_active
                                        ? 'Receiving leads'
                                        : 'Off'
                                }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted-foreground">
                            {{
                                agent.last_seen_at
                                    ? relativeTime(agent.last_seen_at)
                                    : 'Never'
                            }}
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Edit ${agent.name}`"
                                    @click="openEdit(agent)"
                                >
                                    <Pencil />
                                </Button>
                                <Button
                                    v-if="agent.id !== currentUserId"
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive hover:text-destructive"
                                    :aria-label="`Delete ${agent.name}`"
                                    @click="deleting = agent"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? `Edit ${editing.name}` : 'Add agent'
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        editing
                            ? 'Leave the password empty to keep the current one.'
                            : 'Share the email and password with your agent so they can log in.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="agent-name">Name</Label>
                    <Input id="agent-name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="agent-email">Email</Label>
                    <Input
                        id="agent-email"
                        v-model="form.email"
                        type="email"
                        required
                    />
                    <InputError :message="form.errors.email" />
                </div>
                <div class="grid gap-2">
                    <Label>Role</Label>
                    <Select v-model="form.role">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="role in roles"
                                :key="role.value"
                                :value="role.value"
                            >
                                {{ role.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Admins see all chats and can manage agents, tags,
                        channels and blasts.
                    </p>
                    <InputError :message="form.errors.role" />
                </div>
                <div class="grid gap-2">
                    <Label for="agent-password">{{
                        editing ? 'New password (optional)' : 'Password'
                    }}</Label>
                    <Input
                        id="agent-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="new-password"
                        :required="!editing"
                    />
                    <InputError :message="form.errors.password" />
                </div>
                <template v-if="editing">
                    <label class="flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium"
                                >Active</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >Deactivated agents cannot log in.</span
                            >
                        </span>
                        <Switch v-model="form.is_active" />
                    </label>
                    <label class="flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium"
                                >Receives new leads automatically</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >Used when auto-assign is turned on.</span
                            >
                        </span>
                        <Switch v-model="form.is_available" />
                    </label>
                </template>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogOpen = false"
                        >Cancel</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        {{ editing ? 'Save changes' : 'Add agent' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleting !== null"
        @update:open="(open) => !open && (deleting = null)"
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete {{ deleting?.name }}?</DialogTitle>
                <DialogDescription>
                    Their chats become unassigned. Messages they sent are kept.
                    To stop someone logging in but keep their account, edit them
                    and switch off "Active" instead.
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="secondary" @click="deleting = null"
                    >Cancel</Button
                >
                <Button variant="destructive" @click="confirmDelete"
                    >Delete agent</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
