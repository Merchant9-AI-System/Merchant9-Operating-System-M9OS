<?php

namespace App\Models\Jemisys;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $connection = 'jemisys';
    protected $table = 'TblCategory';
    protected $primaryKey = 'CategoryCode';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public function pieces()
    {
        return $this->hasMany(InventoryPiece::class, 'CategoryCode', 'CategoryCode');
    }
}
