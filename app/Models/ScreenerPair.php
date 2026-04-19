<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenerPair extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'screener_run_id',
        'symbol',
        'pair',
        'price',
        'rvol',
        'score',
        'alligator_tf',
        'bullish_count',
        'confluence',
        'qualified',
        'disqualify_reason',
        'tf_data_json',
        'filters_json',
    ];

    protected $casts = [
        'price' => 'decimal:8',
        'rvol' => 'decimal:4',
        'score' => 'decimal:6',
        'qualified' => 'boolean',
        'tf_data_json' => 'array',
        'filters_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function scopeQualified(Builder $query): void
    {
        $query->where('qualified', true);
    }

    public function screenerRun(): BelongsTo
    {
        return $this->belongsTo(ScreenerRun::class);
    }

    public function pairScans(): HasMany
    {
        return $this->hasMany(PairScan::class)->with('signals');
    }
}
