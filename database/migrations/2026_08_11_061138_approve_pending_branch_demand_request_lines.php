<?php

use App\Models\BranchDemandRequestLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill data - line SEDIA ADA sblm perubahan "auto-approve semasa dicipta" (rujuk
 * BranchDemandRequestLine::booted()) kekal STATUS_PENDING selama-lamanya (langkah semakan
 * manual HQ "Semak Permintaan" tak pernah dipakai dlm penggunaan sebenar), menghalang Progress
 * (fulfillment_status) & Module D (BranchDemandAllocationRecommender) berfungsi. Satu kali
 * sahaja - down() sengaja no-op (tak boleh dipercayai kembalikan qty_approved asal selepas ni).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('branch_demand_request_lines')
            ->where('line_status', BranchDemandRequestLine::STATUS_PENDING)
            ->update([
                'line_status' => BranchDemandRequestLine::STATUS_APPROVED,
                'qty_approved' => DB::raw('qty_requested'),
            ]);
    }

    public function down(): void
    {
        // Sengaja no-op - backfill satu-hala, tiada cara dipercayai kenal pasti balik baris yg
        // disentuh migration ni drpd baris yg genuinely diluluskan HQ secara manual sebelum ni.
    }
};
