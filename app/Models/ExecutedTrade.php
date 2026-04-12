<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutedTrade extends Model
{
    protected $fillable = [
        'signal_id',
        'exchange',
        'exchange_order_id',
        'pair',
        'side',
        'order_type',
        'leverage',
        'quantity',
        'notional_usd',
        'entry_price',
        'entry_filled_at',
        'entry_fill_status',
        'entry_fill_qty',
        'entry_fee',
        'sl_price',
        'sl_order_id',
        'tp1_price',
        'tp1_order_id',
        'tp2_price',
        'tp2_order_id',
        'breakeven_moved_at',
        'trailing_tp_json',
        'exit_price',
        'exit_filled_at',
        'exit_fee',
        'total_fees_usd',
        'pnl_pct',
        'pnl_usd',
        'pnl_r',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'notional_usd' => 'decimal:8',
        'entry_price' => 'decimal:8',
        'entry_fill_qty' => 'decimal:8',
        'entry_fee' => 'decimal:8',
        'sl_price' => 'decimal:8',
        'tp1_price' => 'decimal:8',
        'tp2_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'exit_fee' => 'decimal:8',
        'total_fees_usd' => 'decimal:8',
        'pnl_pct' => 'decimal:4',
        'pnl_usd' => 'decimal:8',
        'pnl_r' => 'decimal:4',
        'trailing_tp_json' => 'array',
        'entry_filled_at' => 'datetime',
        'exit_filled_at' => 'datetime',
        'breakeven_moved_at' => 'datetime',
    ];

    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }
}
