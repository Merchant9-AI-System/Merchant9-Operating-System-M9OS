<script setup lang="ts">
import { config, Head, Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, ClipboardList, LayoutDashboard, LogIn } from '@lucide/vue';
import { Button } from '@/components/ui/button';

interface AuthUser {
    id: number;
    name: string;
    store_code: string | null;
    home_enabled: boolean;
}

const page = usePage<{ auth: { user: AuthUser | null } }>();
</script>

<template>

    <Head title="Hi, Welcome" />

    <div class="flex min-h-screen flex-col bg-zinc-950 items-center justify-center gap-8 px-3">
        <div class="text-center">
            <h1 class="text-4xl font-extrabold text-white">Merchant9 OS</h1>
            <p class="mt-2 text-sm text-muted-foreground">Sistem Operasi Merchant9</p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row">
            <!-- <Link href="/branch-demand">
                <Button size="lg" variant="outline" class="w-full">
                    <ClipboardList class="size-4" /> Permintaan Stok Cawangan
                </Button>
            </Link> -->

            <a v-if="page.props.auth.user?.home_enabled" href="/home">
                <Button size="lg" class="w-full bg-amber-600 text-zinc-900 hover:bg-amber-700">
                    <CalendarDays class="size-4" /> Ringkasan Semalam
                </Button>
            </a>
            <a v-if="page.props.auth.user" href="/admin">
                <Button size="lg" variant="outline" class="w-full">
                    <LayoutDashboard class="size-4" /> Dashboard
                </Button>
            </a>
            <a v-else href="/admin/login">
                <Button size="lg" class="w-full bg-amber-600 text-zinc-900 hover:bg-amber-700">
                    <LogIn class="size-4" /> Sign in
                </Button>
            </a>
        </div>
    </div>
</template>
