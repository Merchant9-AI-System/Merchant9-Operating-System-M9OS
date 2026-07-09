<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

/**
 * Baca drpd cermin tempatan `jemisys_vendor_mirror` (DB lalai) - BUKAN terus live 'jemisys'
 * SQL Server - supaya relationship InventoryPiece->vendor() kekal pd SAMBUNGAN SAMA (elak
 * ralat cross-connection bila lajur relation di-searchable()/sortable() dlm Filament, cth.
 * "Base table or view not found: TblVendor"). Disegerak via App\Jobs\SyncJemisysMirrors.
 */
class Vendor extends Model
{
    protected $table = 'jemisys_vendor_mirror';

    protected $primaryKey = 'VendorCode';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function pieces()
    {
        return $this->hasMany(InventoryPiece::class, 'VendorCode', 'VendorCode');
    }
}
