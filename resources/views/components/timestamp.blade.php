@props(['value', 'format' => 'M d g:i A'])

@php
    use Carbon\Carbon;

    $tz  = config('app.timezone', 'UTC');
    $ts  = null;

    if ($value instanceof Carbon) {
        $ts = $value->copy()->setTimezone('UTC')->setTimezone($tz);
    } elseif (is_string($value) && $value !== '') {
        $ts = Carbon::parse($value, 'UTC')->setTimezone($tz);
    } elseif ($value instanceof \DateTimeInterface) {
        $ts = Carbon::instance($value)->setTimezone('UTC')->setTimezone($tz);
    }
@endphp

@if($ts)
    {{ $ts->format($format) }}
@else
    —
@endif
