<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import { useChannelListener } from '@/composables/useRealtime';
import { playNotificationSound, showDesktopNotification } from '@/lib/notify';

type Notification = {
    title: string;
    body: string;
    conversation_id: number | null;
};

const page = usePage();
const { listen } = useChannelListener();

// Personal alerts, e.g. "New chat assigned to you" or a message on one of my chats.
listen<Notification>(
    `App.Models.User.${page.props.auth.user.id}`,
    '.agent.notified',
    (notification) => {
        const open = () =>
            notification.conversation_id &&
            router.visit(
                InboxController.show(notification.conversation_id).url,
            );

        const alreadyOpen =
            notification.conversation_id &&
            window.location.pathname ===
                InboxController.show(notification.conversation_id).url &&
            !document.hidden;

        if (alreadyOpen) {
            return;
        }

        playNotificationSound();
        showDesktopNotification(notification.title, notification.body, open);

        toast.info(notification.title, {
            description: notification.body,
            action: notification.conversation_id
                ? { label: 'Open', onClick: open }
                : undefined,
        });
    },
);
</script>

<template>
    <span class="hidden" />
</template>
