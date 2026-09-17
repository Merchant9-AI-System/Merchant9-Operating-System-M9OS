<?php

namespace App\Http\Controllers;

use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLine;
use App\Models\ExpenseClaimLineCharge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Permukaan Inertia+Vue staf FINANCE urus Expense Claim BAGI PIHAK claimant (cth. staf Finance
 * Aqilah cipta/kemaskini claim bulanan bagi pihak CEO En Haniff) - ikut corak split BranchDemand
 * dari segi permukaan (Inertia=kemasukan, Filament=semakan/kelulusan), TAPI claimant (`user_id`
 * pd ExpenseClaim) BUKAN semestinya org yg key-in (`created_by_id`) - dua peranan berasingan
 * (rujuk App\Models\ExpenseClaim dokblok). Akses permukaan ni diskop role('finance') sahaja
 * (rujuk authorizeFinanceStaff()) - BUKAN lagi ownership Auth::id() macam versi asal (staf
 * Finance urus claim SESIAPA SAHAJA, bukan claim diri sendiri). Kelulusan (Lulus/Tolak) KEKAL
 * di Filament, tapi kini role('head_finance') SAHAJA - claimant/CEO sendiri TIADA akses terus
 * ke sistem ni (rujuk keputusan restructure).
 */
class ExpenseClaimController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeFinanceStaff();

        $claims = ExpenseClaim::with('createdBy')
            ->orderByDesc('claim_month')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn(ExpenseClaim $c) => [
                'id' => $c->id,
                'claim_number' => $c->claim_number,
                'claimant_name' => $c->claimant_name,
                'claim_month' => $c->claim_month->toDateString(),
                'status' => $c->status,
                'total_amount' => (float) $c->total_amount,
                'created_by_name' => $c->createdBy?->name,
            ])->values();

        return Inertia::render('ExpenseClaims/Index', [
            'claims' => $claims,
            // Senarai claimant utk dropdown "Cipta Claim Baharu" - mana-mana user aktif (rujuk
            // keputusan restructure, bukan diskop kpd role tertentu - fleksibel utk masa depan).
            'claimants' => User::orderBy('name')->get(['id', 'name'])->values(),
            'currentMonth' => now()->startOfMonth()->toDateString(),
        ]);
    }

    /** Cipta/sambung claim (satu SAHAJA per claimant+bulan, rujuk ExpenseClaim dokblok) - staf
     * Finance pilih claimant + bulan, terus ke halaman edit utk tambah item. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeFinanceStaff();

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'claim_month' => ['required', 'date'],
        ]);

        $claimant = User::findOrFail($data['user_id']);
        $monthStart = Carbon::parse($data['claim_month'])->startOfMonth()->toDateString();

        $claim = ExpenseClaim::firstOrCreate(
            ['user_id' => $claimant->id, 'claim_month' => $monthStart],
            ['claimant_name' => $claimant->name, 'created_by_id' => Auth::id()]
        );

        return redirect()->route('expense-claims.edit', $claim)
            ->with('success', "Claim {$claim->claim_number} sedia - tambah item di bawah.");
    }

    public function edit(ExpenseClaim $claim): Response
    {
        $this->authorizeFinanceStaff();

        return Inertia::render('ExpenseClaims/Edit', [
            'claim' => $this->serializeClaim($claim),
            // Cadangan tags-input (rujuk Edit.vue) - senarai lalai + kategori yg PERNAH dicipta
            // (mana-mana claim, bukan diskop 1 claimant - dikongsi seluruh pasukan Finance).
            // `category` free text (bukan enum tertutup).
            'categories' => collect(ExpenseClaimLine::DEFAULT_CATEGORIES)
                ->merge(ExpenseClaimLine::distinct()->pluck('category'))
                ->filter()
                ->unique()
                ->values(),
        ]);
    }

    public function updateDetails(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorizeFinanceStaff();
        $this->authorizeEditable($claim);

        $data = $request->validate([
            'designation' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $claim->update($data);

        return back()->with('success', 'Maklumat claim dikemaskini.');
    }

    // dd($request->all());
    // return abort(500);
    public function storeLine(Request $request, ExpenseClaim $claim): RedirectResponse
    {
        $this->authorizeFinanceStaff();
        $this->authorizeEditable($claim);

        $data = $this->validateLine($request);
        $charges = $data['charges'] ?? [];
        unset($data['charges']);

        $line = $claim->lines()->create($data);

        if ($charges) {
            $line->charges()->createMany($charges);
        }

        return back()->with('success', 'Item ditambah.');
    }

    public function updateLine(Request $request, ExpenseClaimLine $line): RedirectResponse
    {
        $this->authorizeFinanceStaff();
        $this->authorizeEditable($line->claim);

        $data = $this->validateLine($request, $line);
        $charges = $data['charges'] ?? [];
        unset($data['charges']);

        $line->update($data);
        // Ganti SEPENUHNYA set caj lama dgn yg baharu (bukan diff/patch individu) - borang
        // hantar SENARAI PENUH setiap kali (rujuk Edit.vue lineForm.charges), lebih mudah &
        // konsisten drpd cuba padan baris lama vs baharu ikut id.
        $line->charges()->delete();
        if ($charges) {
            $line->charges()->createMany($charges);
        }

        return back()->with('success', 'Item dikemaskini.');
    }

    public function destroyLine(ExpenseClaimLine $line): RedirectResponse
    {
        $this->authorizeFinanceStaff();
        $this->authorizeEditable($line->claim);

        $line->delete();

        return back()->with('success', 'Item dipadam.');
    }

    public function uploadReceipt(Request $request): JsonResponse
    {
        $this->authorizeFinanceStaff();

        $data = $request->validate([
            'receipt' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $path = $data['receipt']->store('expense-claim-receipts', 'public');

        return response()->json([
            'receipt_url' => Storage::disk('public')->url($path),
        ]);
    }

    public function submit(ExpenseClaim $claim): RedirectResponse
    {
        $this->authorizeFinanceStaff();

        $claim->submit();

        return redirect()->route('expense-claims.index')
            ->with('success', "Claim {$claim->claim_number} dihantar utk kelulusan.");
    }

    /** Akses permukaan ni diskop role('finance') - staf yg key-in claim bagi pihak claimant
     * (rujuk dokblok kelas). super_admin kekal override, ikut corak sedia ada app ni. */
    protected function authorizeFinanceStaff(): void
    {
        abort_unless(Auth::user()?->hasRole(['finance', 'super_admin', 'head_finance']), 403, 'Akses terhad kpd staf Finance sahaja.');
    }

    protected function authorizeEditable(ExpenseClaim $claim): void
    {
        abort_unless(
            in_array($claim->status, ExpenseClaim::EDITABLE_STATUSES, true),
            422,
            'Claim ni tak boleh diedit drpd status semasa.'
        );
    }

    /**
     * Item biasa (petrol/toll/dll.) - 1 medan "amount" ditaip terus. Item vendor (Google/
     * Facebook/TikTok Ads, dll. - toggle "Ada Invois Vendor?" di borang) TAMBAHAN perlukan
     * vendor/no. invois + SENARAI BEBAS caj (description+amount setiap baris - cth. "Amount",
     * "Service Tax (8%)", "Turkey Regulatory Operating Cost", ikut padanan invois sebenar,
     * BUKAN 2 medan tetap), "amount" (jumlah utk approval/reimbursement) dikira SERVER-SIDE
     * (jumlah semua caj) - BUKAN nilai client hantar terus, supaya Head of Finance nampak
     * breakdown yg konsisten (padan invois sebenar).
     */
    protected function validateLine(Request $request, ?ExpenseClaimLine $line = null): array
    {
        $data = $request->validate([
            'expense_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            // Free text (tags-input, rujuk Edit.vue) - staf boleh cipta kategori baharu
            // sendiri, BUKAN enum tertutup lagi (rujuk ExpenseClaimLine::DEFAULT_CATEGORIES,
            // sekadar cadangan awal).
            'category' => ['required', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'receipt_url' => ['nullable', 'string', 'max:500'],
            'is_vendor_invoice' => ['nullable', 'boolean'],
            'vendor' => ['required_if:is_vendor_invoice,1', 'nullable', 'string', 'max:255'],
            // Unik GLOBAL (bukan per-vendor) - elak staf key-in invois yg sama 2x (cth. tersilap
            // tambah item sama utk claim lain). `ignore($line?->id)` - line ni sendiri takkan
            // clash dgn dirinya bila kemaskini (updateLine).
            'invoice_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('expense_claim_lines', 'invoice_number')->ignore($line?->id),
            ],
            'amount' => ['required_if:is_vendor_invoice,0', 'nullable', 'numeric', 'min:0.01'],
            // `required_if` SAHAJA (bukan tambah `min:1`) - Laravel punya `required` dah
            // anggap array KOSONG `[]` sbg "tiada nilai" (gagal wajib), `min:1` berasingan
            // pula terpakai WALAUPUN medan tak "required" (cth. line kategori biasa, charges
            // sentiasa dihantar sbg `[]` drpd Edit.vue) - punca bug line biasa (bukan invois
            // vendor) gagal senyap sebelum ni, "charges" tersalah dikira wajib jugak.
            'charges' => ['required_if:is_vendor_invoice,1', 'nullable', 'array'],
            'charges.*.description' => ['required_with:charges', 'string', 'max:255'],
            'charges.*.amount' => ['required_with:charges', 'numeric', 'min:0'],
            // WHT (Potongan Cukai Pegangan) % - opsyenal, pilihan drpd ToggleGroup borang (8/10),
            // relevan utk line invois vendor sahaja. % itu sendiri DISIMPAN terus (lajur `wht`
            // di expense_claim_lines) supaya bila line invois vendor dibuka semula utk edit,
            // toggle WHT boleh pre-select balik nilai asal (rujuk Edit.vue lineForm.wht) -
            // BERASINGAN drpd `wht_amount` (jumlah terkira) di bawah.
            'wht' => ['nullable', Rule::in(['8', '10'])],
        ]);

        $isVendorInvoice = (bool) ($data['is_vendor_invoice'] ?? false);

        if ($isVendorInvoice) {
            $data['amount'] = round(collect($data['charges'])->sum(fn(array $c) => (float) $c['amount']), 2);

            // WHT dikira drpd baris caj description="Amount" SAHAJA (jumlah prinsipal invois
            // sblm cukai/caj lain - rujuk contoh invois Google Ads), BUKAN drpd jumlah
            // keseluruhan invois (`amount` di atas) - caj lain (cth. "Tax", caj admin) tak
            // tertakluk potongan WHT. Dikira SERVER-SIDE (bukan client) - sebab sama macam
            // "amount" di atas (docblock kaedah ni).
            $baseCharge = collect($data['charges'])->firstWhere('description', 'Amount');
            $data['wht_amount'] = ($data['wht'] ?? null) && $baseCharge
                ? round(((float) $baseCharge['amount']) * ((float) $data['wht'] / 100), 2)
                : null;
        } else {
            $data['vendor'] = null;
            $data['invoice_number'] = null;
            $data['charges'] = [];
            $data['wht'] = null;
            $data['wht_amount'] = null;
        }

        $data['is_vendor_invoice'] = $isVendorInvoice;

        return $data;
    }

    protected function serializeClaim(ExpenseClaim $claim): array
    {
        $claim->load('lines.charges', 'createdBy', 'user.roles');

        return [
            'id' => $claim->id,
            'claim_number' => $claim->claim_number,
            'claimant_name' => $claim->claimant_name,
            'designation' => $claim->designation,
            // Cadangan auto-isi bila "Jawatan" kosong (rujuk ExpenseClaim::claimantRoleLabel())
            // - staf Finance tetap boleh timpa dgn jawatan sebenar drpd Edit.vue.
            'claimant_role_label' => $claim->claimantRoleLabel(),
            'created_by_name' => $claim->createdBy?->name,
            'claim_month' => $claim->claim_month->toDateString(),
            'status' => $claim->status,
            'total_amount' => (float) $claim->total_amount,
            'rejection_reason' => $claim->rejection_reason,
            'notes' => $claim->notes,
            'lines' => $claim->lines->map(fn(ExpenseClaimLine $l) => [
                'id' => $l->id,
                'expense_date' => $l->expense_date->toDateString(),
                'description' => $l->description,
                'category' => $l->category,
                'amount' => (float) $l->amount,
                // % WHT yg dipilih (utk pre-select balik ToggleGroup bila edit semula line
                // invois vendor sedia ada - rujuk Edit.vue lineForm.wht) + jumlah potongan
                // terkira (dikira drpd caj "Amount" x % - rujuk validateLine()).
                'wht' => $l->wht,
                'wht_amount' => $l->wht_amount !== null ? (float) $l->wht_amount : null,
                'remarks' => $l->remarks,
                'receipt_url' => $l->receipt_url,
                'is_vendor_invoice' => $l->is_vendor_invoice,
                'vendor' => $l->vendor,
                'invoice_number' => $l->invoice_number,
                'charges' => $l->charges->map(fn(ExpenseClaimLineCharge $c) => [
                    'id' => $c->id,
                    'description' => $c->description,
                    'amount' => (float) $c->amount,
                ])->values(),
            ])->values(),
        ];
    }
}
