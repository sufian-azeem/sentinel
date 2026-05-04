<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Signal extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'pair_scan_id',
        'pair',
        'timeframe',
        'strategy',
        'entry_type',
        'reason',
        'entry_price',
        'sl_price',
        'tp1_price',
        'tp2_price',
        'risk_pct',
        'candle_time',
        'candles_ago',
        'screener_score',
        'confluence',
        'conditions_json',
        'chart_data_json',
        'discord_thread_id',
        'status',
    ];

    protected $casts = [
        'entry_price' => 'decimal:8',
        'sl_price' => 'decimal:8',
        'tp1_price' => 'decimal:8',
        'tp2_price' => 'decimal:8',
        'risk_pct' => 'decimal:4',
        'screener_score' => 'decimal:6',
        'conditions_json' => 'array',
        'chart_data_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function getCandleTimeAttribute(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse($value, 'UTC')->setTimezone(config('app.timezone'));
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'tp1_hit']);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', ['active', 'tp1_hit']);
    }

    public function pairScan(): BelongsTo
    {
        return $this->belongsTo(PairScan::class);
    }

    public function outcome(): HasOne
    {
        return $this->hasOne(SignalOutcome::class);
    }

    public function executedTrades(): HasMany
    {
        return $this->hasMany(ExecutedTrade::class);
    }
}
