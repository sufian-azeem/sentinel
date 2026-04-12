<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignalOutcome extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'signal_id',
        'status',
        'exit_price',
        'exit_time',
        'tp1_hit_price',
        'tp1_hit_at',
        'tp2_hit_price',
        'tp2_hit_at',
        'sl_hit_price',
        'sl_hit_at',
        'breakeven_moved_at',
        'trailing_tp_json',
        'candles_to_exit',
        'pnl_pct',
        'pnl_usd',
        'pnl_r',
        'notes',
    ];

    protected $casts = [
        'exit_price' => 'decimal:8',
        'tp1_hit_price' => 'decimal:8',
        'tp2_hit_price' => 'decimal:8',
        'sl_hit_price' => 'decimal:8',
        'pnl_pct' => 'decimal:4',
        'pnl_usd' => 'decimal:8',
        'pnl_r' => 'decimal:4',
        'trailing_tp_json' => 'array',
        'exit_time' => 'datetime',
        'tp1_hit_at' => 'datetime',
        'tp2_hit_at' => 'datetime',
        'sl_hit_at' => 'datetime',
        'breakeven_moved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
