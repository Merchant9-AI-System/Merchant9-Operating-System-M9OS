<?php

namespace App\Http\Controllers;

use App\Models\BranchDemandRequestLine;
use App\Models\RestockListItem;
use App\Support\BackOfficeActionsAdvisor;
use App\Support\RawInertiaProp;
use App\Support\RestockAllocator;
use App\Support\RestockListBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;
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

        return Inertia::render('BackOfficeActions/Index', $this->boardProps($advisor, $restock));
    }

    /**
     * Prop array halaman papan BO - dikongsi antara index() (lawatan penuh) & save() (render
     * terus selepas Simpan, TANPA redirect, supaya partial reload "only" BO yg Simpan cuma
     * Senarai Restock sahaja berkesan dlm SATU round-trip - rujuk save() & nota <Deferred>
     * di Index.vue. Laravel Inertia tetap hormat defer/partial semantics sama ada ni index()
     * ATAU save() yg panggil (defer tetap ditangguh pd lawatan penuh, cuma dikira semula bila
     * nama prop ada dlm permintaan "only").
     *
     * @return array<string, mixed>
     */
    protected function boardProps(BackOfficeActionsAdvisor $advisor, RestockListBuilder $restock): array
    {
        return [
            // DITANGGUH - kira stok/jualan merentas cermin jemisys_inventory_mirror, jadi shell
            // halaman (tajuk, butang) terpapar dulu (rujuk <Deferred> di Index.vue).
            'board' => Inertia::defer(function () use ($advisor) {
                return new RawInertiaProp($advisor->board());
            }, ), // 'high'),
            // Senarai Restock tersimpan (RestockListItem). Supplier TIDAK dimuat di sini - super_admin tekan "Load supplier"
            // (restockSuppliers()) kerana query supplier ke cermin berat & tempohnya boleh dipilih.
            'restock' => Inertia::defer(function () use ($restock) {
                return $restock->build(false);
            }, ), // 'low'),

            'canSeeSuppliers' => $this->canSeeSuppliers(),
            'fulfillmentOptions' => collect(BranchDemandRequestLine::FULFILLMENT_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ];
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

    public function save(Request $request, BackOfficeActionsAdvisor $advisor, RestockListBuilder $restock): Response|RedirectResponse
    {
        $this->authorizeBackOffice();

        $data = $request->validate([
            'changes' => ['required_without:restock_items', 'nullable', 'array', 'max:500'],
            'restock_new' => ['nullable', 'array', 'max:500'],
            'restock_new.*.internal_code' => ['required', 'string', 'max:50'],
            'restock_new.*.qty' => ['required', 'integer', 'min:0', 'max:100000'],
            'restock_new.*.target_stock' => ['nullable', 'integer', 'min:0', 'max:50'],
            'restock_new.*.branch_targets' => ['nullable', 'array', 'max:100'],
            'restock_new.*.branch_targets.*' => ['integer', 'min:0', 'max:50'],
            'restock_items' => ['nullable', 'array', 'max:500'],
            'restock_items.*.id' => ['required', 'integer', 'distinct', 'exists:restock_list_items,id'],
            'restock_items.*.qty' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'restock_items.*.target_stock' => ['nullable', 'integer', 'min:0', 'max:50'],
            'restock_items.*.branch_targets' => ['nullable', 'array', 'max:100'],
            'restock_items.*.branch_targets.*' => ['integer', 'min:0', 'max:50'],
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
        $restockNew = collect($data['restock_new'] ?? [])->mapWithKeys(fn (array $r) => [trim($r['internal_code']) => $r]);
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

            // Kuantiti & paras yg leader BO tetapkan utk design yg BARU masuk senarai (sebelum Simpan) -
            // menggantikan cadangan lalai.
            foreach ($restockNew as $code => $new) {
                $item = RestockListItem::query()
                    ->where('internal_code', $code)
                    ->where('status', RestockListItem::STATUS_DRAFT)
                    ->first();

                if ($item) {
                    $this->applyRestockPlan($item, $advisor, $actor, (int) $new['qty'], $new['target_stock'] ?? null, $new['branch_targets'] ?? null);
                }
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

                $planEdited = array_key_exists('target_stock', $edit) || array_key_exists('branch_targets', $edit);

                if (isset($edit['qty']) || $planEdited) {
                    $restockChanged += $this->applyRestockPlan(
                        $item,
                        $advisor,
                        $actor,
                        isset($edit['qty']) ? (int) $edit['qty'] : null,
                        $edit['target_stock'] ?? null,
                        $edit['branch_targets'] ?? null,
                    ) ? 1 : 0;
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

        session()->flash('success', $message);

        // Permintaan "only" (rujuk save() di Index.vue - Simpan Senarai Restock sahaja minta
        // only:['restock','flash']) kekal SATU request/response SAHAJA - back()->with() redirect
        // hilangkan header X-Inertia-Partial-Data drpd permintaan asal (browser x hantar header
        // custom semula pd redirect), jadi "only" tsb tak berkesan merentas redirect & board()
        // (berat, ~5-7s) tetap dikira semula macam biasa. Render Inertia TERUS (bukan redirect)
        // hormat "only" dlm response yg SAMA - board() terus dilangkau. Lawatan PENUH (tiada
        // permintaan partial - index() biasa, atau save() dari client lama/ujian) kekal corak
        // PRG (back()) spt sebelum ini - tiada perubahan tingkah laku.
        if ($request->header(Header::PARTIAL_COMPONENT) === 'BackOfficeActions/Index'
            && filled($request->header(Header::PARTIAL_ONLY))) {
            return Inertia::render('BackOfficeActions/Index', $this->boardProps($advisor, $restock));
        }

        return back();
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
            $item = RestockListItem::query()
                ->where('internal_code', $code)
                ->where('status', RestockListItem::STATUS_DRAFT)
                ->first();

            $target = $item ? (int) $item->target_stock : null;
            $branchTargets = $item ? (array) ($item->branch_targets ?? []) : [];
            $plan = $advisor->restockPlanFor((string) $code, $target, $branchTargets);
            $suggested = $plan['suggested_qty'];

            if (! $item) {
                RestockListItem::create([
                    'internal_code' => $code,
                    'item_desc' => $group->first()->item_desc,
                    'category_name' => $group->first()->category_name,
                    'qty_to_order' => $suggested,
                    'suggested_qty' => $suggested,
                    'allocation' => RestockAllocator::allocate($plan['branches'], $suggested),
                    'created_by' => $actor,
                ]);
            } else {
                $qty = (int) $item->qty_to_order === (int) $item->suggested_qty ? $suggested : (int) $item->qty_to_order;

                $item->update([
                    'qty_to_order' => $qty,
                    'suggested_qty' => $suggested,
                    'allocation' => RestockAllocator::allocate($plan['branches'], $qty),
                    'updated_by' => $actor,
                ]);
            }

            $changed++;
        }

        return $changed;
    }

    /**
     * Simpan kuantiti order + paras (lalai & pengecualian cawangan) satu item Senarai Restock, kira semula
     * cadangan & agihan setiap cawangan drpd data semasa. Qty tak diberi = kekalkan (atau ikut cadangan baharu
     * kalau selama ni sama dgn cadangan lama).
     *
     * @param  array<string, int>|null  $branchTargets  null = kekalkan
     * @return bool ada perubahan disimpan
     */
    protected function applyRestockPlan(RestockListItem $item, BackOfficeActionsAdvisor $advisor, string $actor, ?int $qty, ?int $target, ?array $branchTargets): bool
    {
        $target ??= (int) $item->target_stock;
        $branchTargets = $this->cleanBranchTargets($branchTargets ?? (array) ($item->branch_targets ?? []), $target, $advisor);

        $plan = $advisor->restockPlanFor((string) $item->internal_code, $target, $branchTargets);
        $wasAuto = (int) $item->qty_to_order === (int) $item->suggested_qty;
        $qty ??= $wasAuto ? $plan['suggested_qty'] : (int) $item->qty_to_order;

        $item->fill([
            'target_stock' => $target,
            'branch_targets' => $branchTargets === [] ? null : $branchTargets,
            'suggested_qty' => $plan['suggested_qty'],
            'qty_to_order' => $qty,
            'allocation' => RestockAllocator::allocate($plan['branches'], $qty),
        ]);

        if (! $item->isDirty()) {
            return false;
        }

        $item->updated_by = $actor;
        $item->save();

        return true;
    }

    /**
     * Buang kunci bukan cawangan jualan & nilai yg sama dgn paras lalai (bukan pengecualian).
     *
     * @param  array<string, mixed>  $branchTargets
     * @return array<string, int>
     */
    protected function cleanBranchTargets(array $branchTargets, int $target, BackOfficeActionsAdvisor $advisor): array
    {
        $valid = array_flip($advisor->branchCodes());

        return collect($branchTargets)
            ->filter(fn ($value, $store) => isset($valid[(string) $store]) && (int) $value !== $target)
            ->map(fn ($value) => (int) $value)
            ->all();
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
        $supplierDays = RestockListBuilder::clampSupplierDays($request->query('days'));
        $data = $restock->build($withSuppliers, $supplierDays);
        $sections = $restock->sections($data['items'], $group);

        $filename = 'Senarai-Restock-'.now()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($data, $sections, $withSuppliers, $group, $supplierDays) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $bold = (new Style)->setFontBold();

            $writer->getCurrentSheet()->setName('Senarai Restock');
            $writer->addRow(Row::fromValues(['Senarai Restock - '.now()->format('d/m/Y H:i').' - ikut '.RestockListBuilder::GROUP_LABELS[$group]], $bold));
            $header = ['Kod Design', 'Keterangan', 'Nickname', 'Kategori', 'Permintaan Cawangan (saiz / berat / qty)', 'Jumlah Diminta', $group === RestockListBuilder::GROUP_BRANCH ? 'Stok Cawangan Ini → Selepas Order' : 'Stok Semasa → Selepas Order', 'Paras', 'Request Belum Dipenuhi', 'Jualan Terkini', 'Cawangan Jualan Terkini', 'Hari Lepas', 'Qty Terjual (30 Hari)', 'Kuantiti Order'];
            if ($group === RestockListBuilder::GROUP_BRANCH) {
                $header[] = 'Cawangan Ini Dapat';
            }
            if ($withSuppliers) {
                $header[] = "Kod Supplier Teratas (terjual {$supplierDays} hari)";
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
                        $group === RestockListBuilder::GROUP_BRANCH ? RestockListBuilder::describeSectionStock($item) : RestockListBuilder::describeStock($item),
                        RestockListBuilder::describeParas($item),
                        RestockListBuilder::describeShort($item),
                        isset($item['last_sale']) ? Carbon::parse($item['last_sale']['date'])->format('d/m/Y') : 'Belum pernah terjual',
                        $item['last_sale']['store'] ?? '',
                        $item['last_sale']['days_ago'] ?? '',
                        $item['last_sale']['sold_30d'] ?? 0,
                        $item['qty_to_order'],
                    ];
                    if ($group === RestockListBuilder::GROUP_BRANCH) {
                        $values[] = (int) ($item['section_alloc'] ?? 0);
                    }
                    if ($withSuppliers) {
                        $values[] = collect($item['suppliers'])->map(fn (array $v) => "{$v['vendor_code']} ({$v['sold']} terjual / {$supplierDays} hari)")->implode("\n");
                    }
                    $writer->addRow(Row::fromValues($values));
                }
            }

            if ($withSuppliers) {
                // Satu jadual: kod supplier, kemudian kod design di lajur seterusnya.
                $writer->addNewSheetAndMakeItCurrent()->setName('Best Supplier to Meet');
                $writer->addRow(Row::fromValues(['Kod Supplier', "Terjual ({$supplierDays} hari)", 'Kod Design'], $bold));

                foreach ($data['suppliers'] as $v) {
                    $writer->addRow(Row::fromValues([$v['vendor_code'], $v['sold'], implode(', ', $v['codes'])]));
                }
            }

            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Supplier teratas setiap design dlm Senarai Restock + "Best Supplier to Meet", ikut tempoh jualan ?days= (1-730,
     * lalai 30). super_admin sahaja (kod supplier tak didedahkan kpd role lain).
     */
    public function restockSuppliers(Request $request, RestockListBuilder $restock): JsonResponse
    {
        $this->authorizeBackOffice();
        abort_unless($this->canSeeSuppliers(), 403);

        $days = $request->query('days');
        $max = RestockListBuilder::MAX_SUPPLIER_DAYS;

        // Pengesahan eksplisit (bukan $request->validate()): ValidationException tersasar ke redirect HTML di sini,
        // sedangkan klien fetch() jangka JSON 422.
        if ($days !== null && $days !== '' && (! ctype_digit((string) $days) || (int) $days < 1 || (int) $days > $max)) {
            $message = "Tempoh jualan mesti antara 1 hingga {$max} hari.";

            return response()->json(['message' => $message, 'errors' => ['days' => [$message]]], 422);
        }

        // Design belum disimpan (baris baharu di halaman): ?extra[KOD]=qty - supplier ikut kod, tak perlu disimpan dulu.
        $unsaved = collect((array) $request->query('extra', []))
            ->filter(fn ($qty, $code) => is_scalar($qty) && is_string($code) && $code !== '' && mb_strlen($code) <= 50)
            ->map(fn ($qty) => max(0, min(100000, (int) $qty)))
            ->take(200)
            ->all();

        return response()->json($restock->supplierData($days === null || $days === '' ? RestockListBuilder::DEFAULT_SUPPLIER_DAYS : (int) $days, $unsaved));
    }

    /**
     * Jualan terkini utk senarai kod design (?codes[]=) - dipakai utk baris Restock yg BELUM disimpan di halaman
     * (yg tersimpan sudah ada `last_sale` dlm data Senarai Restock). Maksimum 200 kod.
     */
    public function restockLatestSales(Request $request, RestockListBuilder $restock): JsonResponse
    {
        $this->authorizeBackOffice();

        $codes = collect((array) $request->query('codes', []))
            ->filter(fn ($code) => is_string($code) && $code !== '' && mb_strlen($code) <= 50)
            ->take(200)
            ->values()
            ->all();

        return response()->json(['sales' => (object) $restock->latestSales($codes)]);
    }

    /** Paparan cetak Senarai Restock (Cetak / Simpan sebagai PDF melalui pelayar). */
    public function restockPrint(Request $request, RestockListBuilder $restock): View
    {
        $this->authorizeBackOffice();

        $withSuppliers = $this->canSeeSuppliers();
        $group = $this->exportGroup($request, $withSuppliers);
        $supplierDays = RestockListBuilder::clampSupplierDays($request->query('days'));
        $data = $restock->build($withSuppliers, $supplierDays);

        return view('back-office-actions.restock-print', [
            'sections' => $restock->sections($data['items'], $group),
            'suppliers' => $data['suppliers'],
            'withSuppliers' => $withSuppliers,
            'supplierDays' => $supplierDays,
            'group' => $group,
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
        return (bool) Auth::user()?->hasRole(['super_admin', 'ceo', 'manager']);
    }

    protected function authorizeBackOffice(): void
    {
        abort_unless(Auth::user()?->hasRole(self::ROLES), 403);
    }
}
