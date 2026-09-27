<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FlaskConical } from '@lucide/vue';
import { ref } from 'vue';
import SimulatorController from '@/actions/App/Http/Controllers/Admin/SimulatorController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ChannelSummary } from '@/types';

const props = defineProps<{ channels: ChannelSummary[] }>();

const open = ref(false);
const form = useForm({
    channel_id: props.channels[0]?.id ?? null,
    name: 'Kerry Tan',
    from: '0123456789',
    text: 'Hi! How much is the 7D6N Japan tour in December?',
});

function submit(): void {
    form.submit(SimulatorController.store(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            open.value = false;
            form.text = '';
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline" size="sm" class="w-full">
                <FlaskConical /> Simulate a customer message
            </Button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Simulate a customer message</DialogTitle>
                <DialogDescription>
                    Demo mode is on, so nothing is sent to Meta. Use this to try
                    the inbox and train agents before going live.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="!channels.length"
                class="rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
                Add a channel first under Admin → Channels (any IDs work in demo
                mode).
            </p>

            <form v-else class="grid gap-3" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label for="sim-channel">Channel</Label>
                    <select
                        id="sim-channel"
                        v-model.number="form.channel_id"
                        class="h-9 rounded-md border bg-transparent px-2 text-sm"
                    >
                        <option v-for="c in channels" :key="c.id" :value="c.id">
                            {{ c.name }} ({{ c.type_label }})
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="sim-name">Customer name</Label>
                    <Input id="sim-name" v-model="form.name" />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="sim-from"
                        >Phone number (or any ID for Messenger)</Label
                    >
                    <Input id="sim-from" v-model="form.from" />
                    <InputError :message="form.errors.from" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="sim-text">Message</Label>
                    <Textarea id="sim-text" v-model="form.text" rows="3" />
                    <InputError :message="form.errors.text" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Receive message
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
