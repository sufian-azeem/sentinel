<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FavoritePair extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'pair';

    protected $keyType = 'string';

    protected $fillable = ['pair'];
}
