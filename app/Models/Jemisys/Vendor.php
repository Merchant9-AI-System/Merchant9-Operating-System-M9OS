<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $connection = 'jemisys';
    protected $table = 'TblVendor';
    protected $primaryKey = 'VendorCode';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public function pieces()
    {
        return $this->hasMany(InventoryPiece::class, 'VendorCode', 'VendorCode');
    }
}
