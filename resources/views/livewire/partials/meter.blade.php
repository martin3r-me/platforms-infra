{{-- Usage meter: $value (0–100 or null), $warn, $crit thresholds. Bar + number, colour by threshold. --}}
@php
    $v = $value === null ? null : max(0, min(100, (float) $value));
    $tone = $v === null ? 'var(--nx-line)' : ($v >= $crit ? 'var(--nx-danger)' : ($v >= $warn ? 'var(--nx-warning)' : 'var(--nx-success)'));
@endphp
<div class="flex items-center justify-end gap-2" title="{{ $v === null ? 'keine Daten' : round($v) . ' %' }}">
    <div class="h-1.5 w-16 overflow-hidden rounded-full bg-[color:var(--nx-line)]" aria-hidden="true">
        @if($v !== null)
            <div class="h-full rounded-full" style="width: {{ $v }}%; background-color: {{ $tone }};"></div>
        @endif
    </div>
    <span @class(['w-10 text-right tabular-nums', 'text-[color:var(--nx-faint)]' => $v === null, 'font-medium text-[color:var(--nx-danger)]' => $v !== null && $v >= $crit])>
        {{ $v === null ? '—' : round($v) . ' %' }}
    </span>
</div>
