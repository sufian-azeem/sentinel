<?php

use App\Scanner\TfScanResolver;
use Illuminate\Support\Carbon;

// Frozen at 14:10:00 UTC.
// At this moment the CURRENT OPEN candle for each TF:
//   15M current open = 14:00  (14:00–14:14 candle is open)
//   1H  current open = 14:00  (14:00–14:59 candle is open)
//   4H  current open = 12:00  (12:00–15:59 candle is open)
//
// Python stores last_timestamp = open time of df.iloc[-1], which is the current open candle.
// We scan a TF only when last_timestamp < current_candle_open, i.e. the period has advanced.

beforeEach(function () {
    Carbon::setTestNow('2026-04-21 14:10:00');
    $this->resolver = new TfScanResolver;
});

afterEach(fn () => Carbon::setTestNow());

function makeTfData(int $ts15m = 0, int $ts1h = 0, int $ts4h = 0): array
{
    return [
        '15M' => ['alligator' => ['seed' => ['last_timestamp' => $ts15m]]],
        '1H' => ['alligator' => ['seed' => ['last_timestamp' => $ts1h]]],
        '4H' => ['alligator' => ['seed' => ['last_timestamp' => $ts4h]]],
    ];
}

it('returns all TFs when tf_data is empty (first scan)', function () {
    expect((new TfScanResolver)->resolve([]))->toBe(['15M', '1H', '4H']);
});

it('returns all TFs when all last_timestamps are zero', function () {
    expect($this->resolver->resolve(makeTfData(0, 0, 0)))->toBe(['15M', '1H', '4H']);
});

it('skips all TFs when all are up to date', function () {
    // "Up to date" = last_ts equals the current open candle's timestamp (what Python stores in df.iloc[-1])
    $ts15m = Carbon::parse('2026-04-21 14:00:00')->timestamp * 1000; // current 15M open
    $ts1h = Carbon::parse('2026-04-21 14:00:00')->timestamp * 1000;  // current 1H open
    $ts4h = Carbon::parse('2026-04-21 12:00:00')->timestamp * 1000;  // current 4H open

    expect($this->resolver->resolve(makeTfData($ts15m, $ts1h, $ts4h)))->toBe([]);
});

it('returns only 15M when 15M is stale but 1H and 4H are current', function () {
    // 15M last saw the 13:45 candle; current open is 14:00 → period advanced → scan
    $ts15m = Carbon::parse('2026-04-21 13:45:00')->timestamp * 1000;
    $ts1h = Carbon::parse('2026-04-21 14:00:00')->timestamp * 1000; // current
    $ts4h = Carbon::parse('2026-04-21 12:00:00')->timestamp * 1000; // current

    expect($this->resolver->resolve(makeTfData($ts15m, $ts1h, $ts4h)))->toBe(['15M']);
});

it('includes 1H when 1H is stale', function () {
    // 1H last saw the 13:00 candle; current 1H open is 14:00 → period advanced → scan
    $ts15m = Carbon::parse('2026-04-21 14:00:00')->timestamp * 1000; // current
    $ts1h = Carbon::parse('2026-04-21 13:00:00')->timestamp * 1000;
    $ts4h = Carbon::parse('2026-04-21 12:00:00')->timestamp * 1000; // current

    expect($this->resolver->resolve(makeTfData($ts15m, $ts1h, $ts4h)))->toBe(['1H']);
});

it('includes 4H when 4H is stale', function () {
    // 4H last saw the 08:00 candle; current 4H open is 12:00 → period advanced → scan
    $ts15m = Carbon::parse('2026-04-21 14:00:00')->timestamp * 1000; // current
    $ts1h = Carbon::parse('2026-04-21 14:00:00')->timestamp * 1000;  // current
    $ts4h = Carbon::parse('2026-04-21 08:00:00')->timestamp * 1000;

    expect($this->resolver->resolve(makeTfData($ts15m, $ts1h, $ts4h)))->toBe(['4H']);
});

it('returns 15M and 1H when both are stale but 4H is current', function () {
    $ts15m = Carbon::parse('2026-04-21 13:45:00')->timestamp * 1000;
    $ts1h = Carbon::parse('2026-04-21 13:00:00')->timestamp * 1000;
    $ts4h = Carbon::parse('2026-04-21 12:00:00')->timestamp * 1000; // current

    expect($this->resolver->resolve(makeTfData($ts15m, $ts1h, $ts4h)))->toBe(['15M', '1H']);
});
