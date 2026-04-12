<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalScan extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'screener_run_id',
        'screener_result_id',
        'pair',
        'timeframe',
        'exchange',
        'strategy',
        'candles_fetched',
        'status',
        'conditions_json',
        'error_message',
    ];

    protected $casts = [
        'conditions_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function screenerRun(): BelongsTo
    {
        return $this->belongsTo(ScreenerRun::class);
    }

    public function screenerResult(): BelongsTo
    {
        return $this->belongsTo(ScreenerResult::class);
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }
}
