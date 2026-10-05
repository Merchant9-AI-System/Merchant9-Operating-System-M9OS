<?php

namespace App\Http\Controllers;

use App\Models\BranchDemandRequestLine;
use App\Models\RestockListItem;
use App\Support\BackOfficeActionsAdvisor;
use App\Support\RestockListBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Permukaan Inertia+Vue "Back Office Actions" - leader BO senaraikan line permintaan cawangan,
 * lihat cadangan Rearrange/Restock (rujuk BackOfficeActionsAdvisor) & kemaskini
 * fulfillment_status BANYAK line sekali gus (Simpan). Staf cawangan nampak status baharu
 * terus dlm senarai permintaan mereka (fulfillment_status medan SAMA yg dipakai RequestList.vue).
 * Tetapkan "Rearrange" AUTO cipta StockTransfer drpd donor yg dicadangkan (peraturan sama spt
 * Module D), semuanya dlm SATU transaksi - kalau mana2 line gagal disahkan, TIADA yg disimpan.
 *
 * Akses: manager (leader BO), ceo & super_admin - ketiga2 boleh SEMUA tindakan dlm halaman ni
 * (keputusan eksplisit, tiada beza kebenaran antara role). Sama corak semakan role dgn
 * ExpenseClaimController (bukan Shield permission) & item navigasi AdminPanelProvider.
 */
class BackOfficeActionsController extends Controller
{
    /** @var array<int, string> */
    public const ROLES = ['manager', 'ceo', 'super_admin'];

    public function index(BackOfficeActionsAdvisor $advisor, RestockListBuilder $restock): Response
    {
        $this->authorizeBackOffice();

        $canSeeSuppliers = $this->canSeeSuppliers();

        return Inertia::render('BackOfficeActions/Index', [
            // DITANGGUH - kira stok/jualan merentas cermin jemisys_inventory_mirror, jadi shell
            // halaman (tajuk, butang) terpapar dulu (rujuk <Deferred> di Index.vue).
            'board' => Inertia::defer(fn () => $advisor->board()),
            // Senarai Restock tersimpan (RestockListItem) - bahagian "Best Supplier to Meet" hanya super_admin.
            'restock' => Inertia::defer(fn () => $restock->build($canSeeSuppliers)),
            'canSeeSuppliers' => $canSeeSuppliers,
            'fulfillmentOptions' => collect(BranchDemandRequestLine::FULFILLMENT_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    /**
     * Calon kod design utk line TANPA kod (web/upload): padan nickname/Description inventori dgn
     * nama line, atau carian ?q= yg ditaip BO. BO yg tentukan kod - hasil ni cuma cadangan.
     */
    public function candidates(Request $request, BranchDemandRequestLine $line, BackOfficeActionsAdvisor $advisor): JsonResponse
    {
        $this->authorizeBackOffice();

        $term = trim((string) $request->query('q', '')) ?: trim((string) $line->item_desc);

        $page = max(1, (int) $request->query('page', 1));

        return response()->json($advisor->designCandidates($term, $page));
    }

    /** Pratonton cadangan Rearrange/Restock bila BO pilih satu kod design utk line-line tanpa kod. */
    public function preview(Request $request, BackOfficeActionsAdvisor $advisor): JsonResponse
    {
        $this->authorizeBackOffice();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['integer', 'exists:branch_demand_request_lines,id'],
        ]);

        $targets = BranchDemandRequestLine::with('request')->whereIn('id', $data['lines'])->get();

        return response()->json($advisor->previewFor($targets, $data['code']));
    }

    public function save(Request $request, BackOfficeActionsAdvisor $advisor): RedirectResponse
    {
        $this->authorizeBackOffice();

        $data = $request->validate([
            'changes' => ['required_without:restock_items', 'nullable', 'array', 'max:500'],
            'restock_new' => ['nullable', 'array', 'max:500'],
            'restock_new.*.internal_code' => ['required', 'string', 'max:50'],
            'restock_new.*.qty' => ['required', 'integer', 'min:0', 'max:100000'],
            'restock_items' => ['nullable', 'array', 'max:500'],
            'restock_items.*.id' => ['required', 'integer', 'distinct', 'exists:restock_list_items,id'],
            'restock_items.*.qty' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'restock_items.*.remove' => ['nullable', 'boolean'],
            'restock_items.*.ordered' => ['nullable', 'boolean'],
            'changes.*.line_id' => ['required', 'integer', 'distinct', 'exists:branch_demand_request_lines,id'],
            'changes.*.fulfillment_status' => ['required', 'string', Rule::in(array_keys(BranchDemandRequestLine::FULFILLMENT_LABELS))],
            'changes.*.from_store' => ['nullable', 'string', 'max:20'],
            'changes.*.qty' => ['nullable', 'integer', 'min:1'],
            'changes.*.moves' => ['nullable', 'array', 'max:10'],
            'changes.*.moves.*.from_store' => ['required', 'string', 'max:20'],
            'changes.*.moves.*.qty' => ['required', 'integer', 'min:1'],
            'changes.*.internal_code' => ['nullable', 'string', 'max:50'],
        ]);

        $changes = collect($data['changes'] ?? [])->keyBy('line_id');
        $restockEdits = collect($data['restock_items'] ?? []);
        $restockNew = collect($data['restock_new'] ?? [])->mapWithKeys(fn (array $r) => [trim($r['internal_code']) => (int) $r['qty']]);
        $actor = (string) Auth::user()->name;

        [$updated, $transfers, $restockChanged, $ordered] = DB::transaction(function () use ($changes, $restockEdits, $restockNew, $advisor, $actor) {
            $lines = BranchDemandRequestLine::with('request')
                ->whereIn('id', $changes->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $updated = 0;
            $transfers = 0;
            $restockLines = [];

            foreach ($changes as $lineId => $change) {
                $line = $lines[$lineId];
                $status = $change['fulfillment_status'];
                $code = trim((string) ($change['internal_code'] ?? ''));
                $codeAssigned = false;

                // Kod design dipilih BO utk line web/upload yg belum ada kod - disimpan DULU supaya
                // Rearrange di bawah nampak kod tsb.
                if ($code !== '' && trim((string) $line->internal_code) !== $code) {
                    $advisor->assignDesignCode($line, $code);
                    $codeAssigned = true;
                }

                if ($line->fulfillment_status === $status) {
                    $updated += $codeAssigned ? 1 : 0;

                    continue;
                }

                if ($status === BranchDemandRequestLine::FULFILLMENT_REARRANGE) {
                    // Satu line boleh ditarik drpd beberapa donor (moves) - tiap pindahan disahkan
                    // semula berurutan (stok donor & baki line dikira semula selepas transfer
                    // sebelumnya dicipta dlm transaksi yg sama).
                    $moves = filled($change['moves'] ?? null)
                        ? $change['moves']
                        : [['from_store' => $change['from_store'] ?? null, 'qty' => $change['qty'] ?? null]];

                    foreach ($moves as $move) {
                        $plan = $advisor->rearrangePlan($line, $move['from_store'] ?? null, isset($move['qty']) ? (int) $move['qty'] : null);
                        $advisor->createRearrangeTransfer($line, $plan, $actor);
                        $transfers++;
                    }
                }

                $line->update(['fulfillment_status' => $status]);
                $updated++;

                if ($status === BranchDemandRequestLine::FULFILLMENT_ORDER && filled($line->internal_code)) {
                    $restockLines[] = $line;
                }
            }

            // Line yg masuk Restock -> design masuk Senarai Restock (kuantiti lalai = cadangan paras 3
            // setiap cawangan), dikira SELEPAS semua status line dikemaskini.
            $restockChanged = $this->syncRestockItems($restockLines, $advisor, $actor);

            // Kuantiti yg leader BO tetapkan utk design yg BARU masuk senarai (sebelum Simpan) -
            // menggantikan cadangan lalai.
            foreach ($restockNew as $code => $qty) {
                RestockListItem::query()
                    ->where('internal_code', $code)
                    ->where('status', RestockListItem::STATUS_DRAFT)
                    ->where('qty_to_order', '!=', $qty)
                    ->update(['qty_to_order' => $qty, 'updated_by' => $actor]);
            }

            $ordered = 0;

            // Suntingan kuantiti / buang / tanda Dah Order drpd bahagian Senarai Restock.
            foreach ($restockEdits as $edit) {
                $item = RestockListItem::find($edit['id']);

                if (! $item) {
                    continue;
                }

                if (! empty($edit['remove'])) {
                    $item->delete();
                    $restockChanged++;

                    continue;
                }

                // Leader BO tick = order sudah dibuat dgn supplier: item keluar dr senarai draf & SEMUA
                // line cawangan yg masih "Order" utk design ni jadi "Dah Order" (cawangan nampak).
                if (! empty($edit['ordered'])) {
                    $this->markRestockItemOrdered($item, isset($edit['qty']) ? (int) $edit['qty'] : null, $actor);
                    $ordered++;
                    $restockChanged++;

                    continue;
                }

                if (isset($edit['qty']) && (int) $edit['qty'] !== (int) $item->qty_to_order) {
                    $item->update(['qty_to_order' => (int) $edit['qty'], 'updated_by' => $actor]);
                    $restockChanged++;
                }
            }

            return [$updated, $transfers, $restockChanged, $ordered];
        });

        $message = match (true) {
            $updated === 0 && $restockChanged === 0 => 'Tiada perubahan untuk disimpan.',
            default => implode(' ', array_filter([
                $updated > 0 ? "{$updated} item dikemaskini".($transfers > 0 ? ", {$transfers} transfer Rearrange dicipta." : '.') : null,
                $restockChanged > 0 ? "{$restockChanged} item Senarai Restock dikemaskini".($ordered > 0 ? ", {$ordered} ditanda Dah Order." : '.') : null,
            ])),
        };

        return back()->with('success', $message);
    }

    /**
     * Pastikan setiap design yg line-nya baru masuk "Order" ada dlm Senarai Restock (draf). Item sedia
     * ada: cadangan dikemaskini, & kuantiti turut mengikut cadangan baharu HANYA kalau leader BO belum
     * ubah (kuantiti masih sama dgn cadangan lama).
     *
     * @param  array<int, BranchDemandRequestLine>  $lines
     * @return int bilangan item Senarai Restock yg dicipta/dikemaskini
     */
    protected function syncRestockItems(array $lines, BackOfficeActionsAdvisor $advisor, string $actor): int
    {
        $changed = 0;

        foreach (collect($lines)->groupBy(fn (BranchDemandRequestLine $l) => trim((string) $l->internal_code)) as $code => $group) {
            $suggested = $advisor->suggestedRestockQty((string) $code);
            $item = RestockListItem::query()
                ->where('internal_code', $code)
                ->where('status', RestockListItem::STATUS_DRAFT)
                ->first();

            if (! $item) {
                RestockListItem::create([
                    'internal_code' => $code,
                    'item_desc' => $group->first()->item_desc,
                    'category_name' => $group->first()->category_name,
                    'qty_to_order' => $suggested,
                    'suggested_qty' => $suggested,
                    'created_by' => $actor,
                ]);
            } else {
                $item->update([
                    'qty_to_order' => (int) $item->qty_to_order === (int) $item->suggested_qty ? $suggested : $item->qty_to_order,
                    'suggested_qty' => $suggested,
                    'updated_by' => $actor,
                ]);
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * Tandakan item Senarai Restock sudah diorder: status item -> ordered, kuantiti akhir disimpan, dan
     * line cawangan yg masih berstatus Order utk kod tsb -> Dah Order (satu per satu supaya log
     * aktiviti line direkodkan).
     */
    protected function markRestockItemOrdered(RestockListItem $item, ?int $qty, string $actor): void
    {
        $item->update([
            'status' => RestockListItem::STATUS_ORDERED,
            'qty_to_order' => $qty ?? $item->qty_to_order,
            'ordered_at' => now(),
            'updated_by' => $actor,
        ]);

        BranchDemandRequestLine::query()
            ->where('internal_code', $item->internal_code)
            ->where('fulfillment_status', BranchDemandRequestLine::FULFILLMENT_ORDER)
            ->whereNull('done_at')
            ->get()
            ->each(fn (BranchDemandRequestLine $line) => $line->update(['fulfillment_status' => BranchDemandRequestLine::FULFILLMENT_DAH_ORDER]));
    }

    /** Eksport Senarai Restock tersimpan ke Excel (.xlsx) - ikut kumpulan: category | branch | supplier. */
    public function restockExport(Request $request, RestockListBuilder $restock): StreamedResponse
    {
        $this->authorizeBackOffice();

        $withSuppliers = $this->canSeeSuppliers();
        $group = $this->exportGroup($request, $withSuppliers);
        $data = $restock->build($withSuppliers);
        $sections = $restock->sections($data['items'], $group);

        $filename = 'Senarai-Restock-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($data, $sections, $withSuppliers, $group) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $bold = (new Style)->setFontBold();

            $writer->getCurrentSheet()->setName('Senarai Restock');
            $writer->addRow(Row::fromValues(['Senarai Restock - '.now()->format('d/m/Y H:i').' - ikut '.RestockListBuilder::GROUP_LABELS[$group]], $bold));
            $header = ['Kod Design', 'Keterangan', 'Nickname', 'Kategori', 'Permintaan Cawangan (saiz / berat / qty)', 'Jumlah Diminta', 'Stok Semasa', 'Jualan 7 Hari', 'Jualan 30 Hari', 'Kuantiti Order'];
            if ($withSuppliers) {
                $header[] = 'Kod Supplier Teratas';
            }

            foreach ($sections as $section) {
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(Row::fromValues([$section['title']], $bold));
                $writer->addRow(Row::fromValues($header, $bold));

                foreach ($section['items'] as $item) {
                    $values = [
                        $item['internal_code'],
                        $item['description'],
                        $item['nickname'],
                        $item['category_name'],
                        collect($item['requests'])->map(fn (array $r) => RestockListBuilder::describeRequest($r))->implode("\n"),
                        $item['requested_total'],
                        collect($item['stock'])->map(fn (array $s) => "{$s['store_code']} {$s['stock']}")->implode(', '),
                        $item['sold_7d'],
                        $item['sold_30d'],
                        $item['qty_to_order'],
                    ];
                    if ($withSuppliers) {
                        $values[] = collect($item['suppliers'])->map(fn (array $v) => "{$v['vendor_code']} ({$v['sold']} terjual)")->implode("\n");
                    }
                    $writer->addRow(Row::fromValues($values));
                }
            }

            if ($withSuppliers) {
                // Satu jadual: kod supplier, kemudian kod design di lajur seterusnya.
                $writer->addNewSheetAndMakeItCurrent()->setName('Best Supplier to Meet');
                $writer->addRow(Row::fromValues(['Kod Supplier', 'Kod Design'], $bold));

                foreach ($data['suppliers'] as $v) {
                    $writer->addRow(Row::fromValues([$v['vendor_code'], implode(', ', $v['codes'])]));
                }
            }

            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Paparan cetak Senarai Restock (Cetak / Simpan sebagai PDF melalui pelayar). */
    public function restockPrint(Request $request, RestockListBuilder $restock): View
    {
        $this->authorizeBackOffice();

        $withSuppliers = $this->canSeeSuppliers();
        $group = $this->exportGroup($request, $withSuppliers);
        $data = $restock->build($withSuppliers);

        return view('back-office-actions.restock-print', [
            'sections' => $restock->sections($data['items'], $group),
            'suppliers' => $data['suppliers'],
            'withSuppliers' => $withSuppliers,
            'groupLabel' => RestockListBuilder::GROUP_LABELS[$group],
            'generatedBy' => (string) Auth::user()->name,
        ]);
    }

    protected function exportGroup(Request $request, bool $withSuppliers): string
    {
        $group = (string) $request->query('group', RestockListBuilder::GROUP_CATEGORY);

        if (! array_key_exists($group, RestockListBuilder::GROUP_LABELS) || ($group === RestockListBuilder::GROUP_SUPPLIER && ! $withSuppliers)) {
            return RestockListBuilder::GROUP_CATEGORY;
        }

        return $group;
    }

    /** "Best Supplier to Meet" - super_admin sahaja buat masa ni (keputusan eksplisit pengguna). */
    protected function canSeeSuppliers(): bool
    {
        return (bool) Auth::user()?->hasRole('super_admin');
    }

    protected function authorizeBackOffice(): void
    {
        abort_unless(Auth::user()?->hasRole(self::ROLES), 403);
    }
}
