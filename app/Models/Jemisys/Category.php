<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

/**
 * Baca drpd cermin tempatan `jemisys_category_mirror` (DB lalai) - BUKAN terus live 'jemisys'
 * SQL Server - supaya relationship InventoryPiece->category() kekal pd SAMBUNGAN SAMA (elak
 * ralat cross-connection bila lajur relation di-searchable()/sortable() dlm Filament, cth.
 * "Base table or view not found: TblCategory"). Disegerak via App\Jobs\SyncJemisysMirrors.
 */
class Category extends Model
{
    protected $table = 'jemisys_category_mirror';

    protected $primaryKey = 'CategoryCode';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public function pieces()
    {
        return $this->hasMany(InventoryPiece::class, 'CategoryCode', 'CategoryCode');
    }
}
