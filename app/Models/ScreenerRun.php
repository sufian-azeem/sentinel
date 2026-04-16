<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScreenerRun extends Model
{
    public const int EXPIRY_HOURS = 24;

    public $timestamps = false;

    protected $fillable = [
        'data_source',
        'total_scanned',
        'total_matched',
        'filters_json',
        'status',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'filters_json' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function scopeCompleted($query): void
    {
        $query->where('status', 'completed');
    }

    public function screenerResults(): HasMany
    {
        return $this->hasMany(ScreenerResult::class);
    }

    public function signalScans(): HasMany
    {
        return $this->hasMany(SignalScan::class);
    }
}
