<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cermin tempatan TblInventory (jemisys) - disegerak via SyncJemisysMirrors (butang
 * manual di JemisysConnectionStatus), bukan live query terus ke SQL Server. Lihat migration
 * jemisys_inventory_mirror utk sebab (SQL Server tiada index sesuai & job jadual jemisys
 * gantikan seluruh DB, jadi index custom kita akan hilang - cermin ni kita kawal sepenuhnya).
 */
class InventoryMirror extends Model
{
    protected $table = 'jemisys_inventory_mirror';

    protected $guarded = [];

    public $timestamps = false;
}
