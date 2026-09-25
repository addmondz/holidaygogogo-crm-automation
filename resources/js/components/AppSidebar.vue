<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    Contact,
    Inbox,
    LayoutGrid,
    PlugZap,
    SlidersHorizontal,
    Tags,
    Users,
    Zap,
} from '@lucide/vue';
import { computed } from 'vue';
import AgentController from '@/actions/App/Http/Controllers/Admin/AgentController';
import ChannelController from '@/actions/App/Http/Controllers/Admin/ChannelController';
import SettingsController from '@/actions/App/Http/Controllers/Admin/SettingsController';
import TagController from '@/actions/App/Http/Controllers/Admin/TagController';
import ContactController from '@/actions/App/Http/Controllers/Contacts/ContactController';
import InboxController from '@/actions/App/Http/Controllers/Inbox/InboxController';
import QuickReplyController from '@/actions/App/Http/Controllers/QuickReplyController';
import AppLogo from '@/components/AppLogo.vue';
import AvailabilityToggle from '@/components/AvailabilityToggle.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => page.props.auth.isAdmin);

const mainNavItems = computed<NavItem[]>(() => [
    { title: 'Inbox', href: InboxController.index(), icon: Inbox },
    { title: 'Contacts', href: ContactController.index(), icon: Contact },
    { title: 'Quick replies', href: QuickReplyController.index(), icon: Zap },
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
]);

const adminNavItems = computed<NavItem[]>(() => [
    { title: 'Agents', href: AgentController.index(), icon: Users },
    { title: 'Tags', href: TagController.index(), icon: Tags },
    { title: 'Channels', href: ChannelController.index(), icon: PlugZap },
    {
        title: 'Settings',
        href: SettingsController.edit(),
        icon: SlidersHorizontal,
    },
]);

const footerNavItems = computed<NavItem[]>(() =>
    isAdmin.value
        ? [{ title: 'Queue monitor', href: '/horizon', icon: Activity }]
        : [],
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="InboxController.index()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
            <NavMain v-if="isAdmin" :items="adminNavItems" label="Admin" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter v-if="footerNavItems.length" :items="footerNavItems" />
            <AvailabilityToggle />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
