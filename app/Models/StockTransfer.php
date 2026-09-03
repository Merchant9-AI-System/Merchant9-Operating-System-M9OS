<?php

namespace App\Models;

use App\Models\Jemisys\InventoryPiece;
use App\Support\RestockAnalysisCalculator;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class StockTransfer extends Model
{
    use LogsActivity;

    public const STATUS_REQUESTED = 'Requested';

    public const STATUS_IN_TRANSIT = 'In Transit';

    public const STATUS_RECEIVED = 'Received';

    public const STATUS_CANCELLED = 'Cancelled';

    /** Alur linear (sepadan pattern advance_order Flask): Requested -> In Transit -> Received. */
    public const STATUS_FLOW = [self::STATUS_REQUESTED, self::STATUS_IN_TRANSIT, self::STATUS_RECEIVED];

    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'in_transit_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StockTransfer $t) {
            if (! $t->transfer_number) {
                $t->transfer_number = static::generateTransferNumber();
            }
            $t->status ??= self::STATUS_REQUESTED;
            $t->requested_at ??= now();
        });

        // created() (bukan dipanggil manual drpd tiap page cadangan spt
        // BranchDemandRequest::notifyReviewers()) - StockTransfer TIADA konsep "tambah line ke
        // rekod sedia ada" (beza drpd BranchDemandRequest), setiap create() SENTIASA transfer
        // baharu yg perlu diberitahu, jadi model event tunggal ni cukup, TIDAK akan "drift" walau
        // ada >1 permukaan cipta (StockRearrangementRecommendation/Rearrange/
        // BranchDemandAllocationSuggestion/StockTransferResource sendiri).
        static::created(function (StockTransfer $t) {
            $t->notifyBranches();
        });
    }

    /**
     * Beritahu staf cawangan ASAL (perlu hantar), DESTINASI (akan terima), & role "manager"
     * (overview - atas permintaan eksplisit) - sepadan pattern BranchDemandRequest::
     * notifyReviewers() tapi cawangan ikut store_code (bukan role) sbb notifikasi tsb PER
     * CAWANGAN, bukan utk HQ/reviewer. trim() kedua-dua belah - sesetengah permukaan cipta
     * (cth. StockRearrangementRecommendation) hantar from_store/to_store MENTAH/padded terus
     * drpd StockRearrangementRecommender::compute() punya StoreCode CHAR, User::store_code pula
     * sentiasa trim()-ed (rujuk UserForm), jadi padanan terus tanpa trim() akan GAGAL senyap.
     */
    public function notifyBranches(): void
    {
        $fromStore = trim((string) $this->from_store);
        $toStore = trim((string) $this->to_store);
        $sizeWeight = $this->sizeWeightLabel();

        $viewUrl = route('filament.admin.resources.stock-transfers.view', ['record' => $this->getKey()]);
        $viewAction = Action::make('gotoPage')->label('Lihat')->url($viewUrl)->button();

        $originStaff = User::where('store_code', $fromStore)->get();

        if ($originStaff->isNotEmpty()) {
            Notification::make()
                ->title("Sila hantar: {$this->transfer_number}")
                ->body("{$this->internal_code} ({$sizeWeight}, {$this->qty} unit) ke {$toStore}, diminta oleh {$this->requested_by}")
                ->info()
                ->actions([$viewAction])
                ->sendToDatabase($originStaff);
        }

        $destinationStaff = User::where('store_code', $toStore)->get();

        if ($destinationStaff->isNotEmpty()) {
            Notification::make()
                ->title("Transfer masuk: {$this->transfer_number}")
                ->body("{$this->internal_code} ({$sizeWeight}, {$this->qty} unit) drpd {$fromStore} - akan tiba tak lama lagi, sila tunggu semakan HQ")
                ->info()
                ->actions([$viewAction])
                ->sendToDatabase($destinationStaff);
        }

        // Manager - overview SETIAP transfer baharu (bukan sekadar cawangan terlibat), atas
        // permintaan eksplisit. Kecualikan manager yg dah terima notifikasi cawangan di atas
        // (cth. manager yg turut ada store_code sepadan) - elak notifikasi berganda utk
        // kejadian SAMA.
        $notifiedIds = $originStaff->pluck('id')->merge($destinationStaff->pluck('id'));
        $managers = User::role(['manager'])->whereNotIn('id', $notifiedIds)->get();

        if ($managers->isNotEmpty()) {
            Notification::make()
                ->title("Transfer dicipta: {$this->transfer_number}")
                ->body("{$this->internal_code} ({$sizeWeight}, {$this->qty} unit): {$fromStore} -> {$toStore}, diminta oleh {$this->requested_by}")
                ->info()
                ->actions([$viewAction])
                ->sendToDatabase($managers);
        }
    }

    /**
     * "Saiz X | Berat Yg" representatif design ni (MAX() merentas SEMUA keping jemisys_inventory_
     * mirror, SAMA pendekatan spt StockRearrangementRecommender::compute()) - StockTransfer
     * sendiri TIADA lajur size/weight (tiada migration ditambah SENGAJA), dikira on-demand di
     * sini supaya SEMUA permukaan cipta (StockRearrangementRecommendation/Rearrange/
     * BranchDemandAllocationSuggestion/StockTransferResource) dapat notifikasi sepadan, bukan
     * hanya yg satu page yg kebetulan ada data tsb sedia ada. trim() InternalCode - lajur CHAR
     * berpadding di jemisys_inventory_mirror.
     */
    protected function sizeWeightLabel(): string
    {
        $meta = InventoryPiece::query()
            ->whereRaw('TRIM(InternalCode) = ?', [trim((string) $this->internal_code)])
            ->selectRaw('MAX(JewelSize) as JewelSize, MAX(GoldWeight) as GoldWeight')
            ->first();

        $size = RestockAnalysisCalculator::sizeLabel($meta?->JewelSize);
        $weight = (float) ($meta?->GoldWeight ?? 0);
        $weightLabel = $weight > 0 ? number_format($weight, 2).'g' : '-';

        return "Saiz {$size} | Berat {$weightLabel}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll();
    }

    /** Null utk transfer "organik" (bukan drpd cadangan Branch Demand). */
    public function demandLine()
    {
        return $this->belongsTo(BranchDemandRequestLine::class, 'branch_demand_request_line_id');
    }

    public static function generateTransferNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;

        return sprintf('TRF-%d-%04d', $year, $count);
    }

    /** Naikkan ke peringkat seterusnya dlm STATUS_FLOW. */
    public function advance(string $actor): void
    {
        $idx = array_search($this->status, self::STATUS_FLOW, true);
        abort_if(
            $idx === false || $idx >= count(self::STATUS_FLOW) - 1,
            422,
            'Transfer ni tak boleh dinaikkan status lagi.'
        );

        $next = self::STATUS_FLOW[$idx + 1];
        $update = ['status' => $next];
        if ($next === self::STATUS_IN_TRANSIT) {
            $update['in_transit_at'] = now();
        } elseif ($next === self::STATUS_RECEIVED) {
            $update['received_by'] = $actor;
            $update['received_at'] = now();
        }
        $this->update($update);
    }

    public function cancel(): void
    {
        abort_if($this->status === self::STATUS_RECEIVED, 422, 'Transfer yang dah diterima tak boleh dibatalkan.');
        $this->update(['status' => self::STATUS_CANCELLED]);
    }
}
