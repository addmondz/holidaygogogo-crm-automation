<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SettingsController from '@/actions/App/Http/Controllers/Admin/SettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type { Option } from '@/types';

const props = defineProps<{
    settings: {
        visibility: string;
        auto_assign: boolean;
        opt_out_keywords: string;
        opt_in_keywords: string;
    };
    visibilityOptions: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Settings', href: SettingsController.edit() }],
    },
});

const form = useForm({ ...props.settings });

function submit() {
    form.submit(SettingsController.update(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Settings" />

    <form
        class="flex max-w-3xl flex-1 flex-col gap-8 p-4 md:p-6"
        @submit.prevent="submit"
    >
        <Heading
            title="Settings"
            description="How chats are shared between agents, and how customers opt out of blasts."
        />

        <section class="space-y-4 rounded-xl border bg-card p-5">
            <Heading
                variant="small"
                title="Which chats can agents see?"
                description="Admins always see every chat."
            />
            <div class="grid gap-2">
                <label
                    v-for="option in visibilityOptions"
                    :key="option.value"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 has-[:checked]:border-primary has-[:checked]:bg-muted/50"
                >
                    <input
                        v-model="form.visibility"
                        type="radio"
                        name="visibility"
                        :value="option.value"
                        class="accent-primary"
                    />
                    <span class="text-sm">{{ option.label }}</span>
                </label>
            </div>
            <InputError :message="form.errors.visibility" />
        </section>

        <section class="space-y-4 rounded-xl border bg-card p-5">
            <label class="flex items-center justify-between gap-6">
                <span>
                    <span class="block font-medium">Auto-assign new leads</span>
                    <span class="text-sm text-muted-foreground">
                        New chats are shared out in turn (round-robin) between
                        agents who are active and marked "available for new
                        leads".
                    </span>
                </span>
                <Switch v-model="form.auto_assign" />
            </label>
        </section>

        <section class="space-y-4 rounded-xl border bg-card p-5">
            <Heading
                variant="small"
                title="Blast opt-out"
                description="When a customer sends one of these words, they are removed from future blasts (and added back with an opt-in word). Separate words with commas."
            />
            <div class="grid gap-2">
                <Label for="opt-out">Opt-out words</Label>
                <Input
                    id="opt-out"
                    v-model="form.opt_out_keywords"
                    placeholder="STOP, UNSUBSCRIBE"
                />
                <InputError :message="form.errors.opt_out_keywords" />
            </div>
            <div class="grid gap-2">
                <Label for="opt-in">Opt-in words</Label>
                <Input
                    id="opt-in"
                    v-model="form.opt_in_keywords"
                    placeholder="START"
                />
                <InputError :message="form.errors.opt_in_keywords" />
            </div>
        </section>

        <div>
            <Button type="submit" :disabled="form.processing"
                >Save settings</Button
            >
        </div>
    </form>
</template>
