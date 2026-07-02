<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $connection = 'jemisys';
    protected $table = 'TblStore';
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
