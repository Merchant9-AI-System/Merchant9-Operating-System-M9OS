<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader } from '@/components/ui/card';

interface Scope {
    description: string;
}

interface Props {
    clientName: string;
    clientId: string;
    userEmail: string;
    userName: string;
    scopes: Scope[];
    authToken: string;
    csrfToken: string;
    approveUrl: string;
    denyUrl: string;
}

defineProps<Props>();

const isApproving = ref(false);
const isDenying = ref(false);

// Skrin ni dibuka Claude.ai (& client OAuth lain) dlm POPUP - lepas submit, Passport redirect
// popup tsb ke redirect_uri client (code=/error=). Tunggu URL popup SENDIRI (bukan cross-origin,
// sentiasa boleh dibaca) tinggalkan /oauth/authorize, baru tutup popup - fallback 5s kalau
// redirect tak dikesan (cth. client bukan popup, atau gagal senyap).
function watchForRedirect() {
    const startedAt = Date.now();

    const interval = setInterval(() => {
        const redirected =
            !window.location.href.includes('/oauth/authorize') ||
            window.location.search.includes('code=') ||
            window.location.search.includes('error=');

        if (redirected || Date.now() - startedAt > 5000) {
            clearInterval(interval);
            window.close();
        }
    }, 100);
}

function onApproveSubmit() {
    isApproving.value = true;
    setTimeout(watchForRedirect, 200);
}

function onDenySubmit() {
    isDenying.value = true;
    setTimeout(() => window.close(), 200);
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center p-4">
        <div class="w-full max-w-md">
            <Card>
                <CardHeader>
                    <div class="mb-4 flex items-center justify-center">
                        <svg class="h-12 w-12 text-primary" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"
                            />
                        </svg>
                    </div>

                    <h3 class="text-center text-2xl leading-none font-semibold tracking-tight">Authorize {{ clientName }}</h3>

                    <p class="text-center text-sm text-muted-foreground">This application will be able to:<br />Use available MCP functionality.</p>
                </CardHeader>

                <CardContent class="space-y-4">
                    <div class="rounded-lg border bg-muted/50 p-4">
                        <p class="mb-2 text-sm text-muted-foreground">Logged in as:</p>
                        <p class="font-medium">{{ userName }}</p>
                    </div>

                    <div v-if="scopes.length > 0" class="space-y-2">
                        <p class="text-sm font-medium">Permissions:</p>

                        <ul class="space-y-2">
                            <li v-for="(scope, index) in scopes" :key="index" class="flex items-start gap-2">
                                <div class="mt-0.5 rounded-full bg-primary/10 p-1">
                                    <div class="h-1.5 w-1.5 rounded-full bg-primary" />
                                </div>
                                <span class="text-sm text-muted-foreground">{{ scope.description }}</span>
                            </li>
                        </ul>
                    </div>
                </CardContent>

                <CardFooter class="gap-3">
                    <form method="POST" :action="denyUrl" class="flex-1" @submit="onDenySubmit">
                        <input type="hidden" name="_token" :value="csrfToken" />
                        <input type="hidden" name="_method" value="DELETE" />
                        <input type="hidden" name="state" value="" />
                        <input type="hidden" name="client_id" :value="clientId" />
                        <input type="hidden" name="auth_token" :value="authToken" />

                        <Button type="submit" variant="outline" class="w-full" :disabled="isApproving || isDenying">
                            <svg v-if="!isDenying" class="mr-1 h-4 w-4" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            <svg v-else class="mr-1 h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                />
                            </svg>
                            {{ isDenying ? 'Cancelling...' : 'Cancel' }}
                        </Button>
                    </form>

                    <form method="POST" :action="approveUrl" class="flex-1" @submit="onApproveSubmit">
                        <input type="hidden" name="_token" :value="csrfToken" />
                        <input type="hidden" name="state" value="" />
                        <input type="hidden" name="client_id" :value="clientId" />
                        <input type="hidden" name="auth_token" :value="authToken" />

                        <Button type="submit" class="w-full" :disabled="isApproving || isDenying">
                            <svg v-if="isApproving" class="mr-1 h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                />
                            </svg>
                            {{ isApproving ? 'Authorizing...' : 'Authorize' }}
                        </Button>
                    </form>
                </CardFooter>
            </Card>
        </div>
    </div>
</template>
