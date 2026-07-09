<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

/**
 * Baca drpd cermin tempatan `jemisys_store_mirror` (DB lalai) - BUKAN terus live 'jemisys'
 * SQL Server - supaya relationship InventoryPiece->store() kekal pd SAMBUNGAN SAMA (elak
 * ralat cross-connection bila lajur relation di-searchable()/sortable() dlm Filament).
 * Disegerak via App\Jobs\SyncJemisysMirrors.
 */
class Store extends Model
{
    protected $table = 'jemisys_store_mirror';

    protected $primaryKey = 'StoreCode';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $casts = [
        'IdealGoldWeight916' => 'decimal:2',
    ];

    public function pieces()
    {
        return $this->hasMany(InventoryPiece::class, 'StoreCode', 'StoreCode');
    }
}
