<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Label } from '@/components/ui/label';
import { SelectNative } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';

interface ClaimSummary {
    id: number;
    claim_number: string;
    claimant_name: string;
    claim_month: string;
    status: 'Draft' | 'Submitted' | 'Approved' | 'Rejected';
    total_amount: number;
    created_by_name: string | null;
}

interface Claimant {
    id: number;
    name: string;
}

const props = defineProps<{
    claims: ClaimSummary[];
    claimants: Claimant[];
    currentMonth: string;
}>();

const STATUS_BADGE_CLASS: Record<string, string> = {
    Draft: 'border-transparent bg-muted text-muted-foreground',
    Submitted: 'border-transparent bg-warning text-warning-foreground',
    Approved: 'border-transparent bg-emerald-600 text-white',
    Rejected: 'border-transparent bg-destructive text-white',
};

function formatMonth(dateStr: string): string {
    return new Date(dateStr).toLocaleDateString('ms-MY', { month: 'long', year: 'numeric' });
}

function money(amount: number): string {
    return `RM ${amount.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// --- Cipta claim baharu (pilih claimant + bulan, bukan self-service - rujuk restructure:
// staf Finance key-in bagi pihak claimant, cth. Aqilah cipta claim bulanan utk CEO En Haniff). ---
const createForm = useForm({
    user_id: '',
    claim_month: `${props.currentMonth.slice(0, 7)}-01`,
});

function createClaim() {
    createForm.post('/claims', {
        preserveScroll: true,
    });
}
</script>

<template>

    <Head title="Expense Claims" />

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-8">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Expense Claims</h1>
            <p class="text-sm text-muted-foreground">Cipta & urus claim bulanan bagi pihak claimant (cth. CEO).</p>
        </div>

        <!-- Cipta claim baharu -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Cipta / Sambung Claim</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <Label for="user_id">Claim untuk</Label>
                        <SelectNative id="user_id" v-model="createForm.user_id">
                            <option value="" disabled>Pilih claimant...</option>
                            <option v-for="c in claimants" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </SelectNative>
                        <p v-if="createForm.errors.user_id" class="mt-1 text-xs text-destructive">{{ createForm.errors.user_id }}</p>
                    </div>
                    <div>
                        <Label for="claim_month">Bulan</Label>
                        <DatePicker id="claim_month" v-model="createForm.claim_month" month-only />
                        <p v-if="createForm.errors.claim_month" class="mt-1 text-xs text-destructive">{{ createForm.errors.claim_month }}</p>
                    </div>
                </div>
                <Button type="button" class="mt-4 gap-1.5" :disabled="createForm.processing || !createForm.user_id" @click="createClaim">
                    <Spinner v-if="createForm.processing" class="size-4" />
                    <Plus v-else class="size-4" />Cipta / Sambung Claim
                </Button>
            </CardContent>
        </Card>

        <!-- Senarai claim -->
        <Card>
            <CardHeader>
                <CardTitle class="text-base">Senarai Claim ({{ claims.length }})</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <p v-if="claims.length === 0" class="text-sm text-muted-foreground">Belum ada claim dicipta.</p>
                <Link
                    v-for="claim in claims"
                    :key="claim.id"
                    :href="`/claims/${claim.id}`"
                    class="flex items-center justify-between gap-3 rounded-md border p-3 transition-colors hover:bg-muted/50"
                >
                    <div class="flex flex-col gap-0.5">
                        <span class="font-medium">{{ claim.claimant_name }} &middot; {{ formatMonth(claim.claim_month) }}</span>
                        <p class="text-xs text-muted-foreground">
                            {{ claim.claim_number }}
                            <span v-if="claim.created_by_name"> &middot; dicipta oleh {{ claim.created_by_name }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="font-medium tabular-nums">{{ money(claim.total_amount) }}</span>
                        <Badge :class="STATUS_BADGE_CLASS[claim.status]">{{ claim.status }}</Badge>
                    </div>
                </Link>
            </CardContent>
        </Card>
    </div>
</template>
