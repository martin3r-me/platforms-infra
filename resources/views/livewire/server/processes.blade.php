{{-- Server · Prozesse. Erwartet $m (last_report). --}}
@php
    $proc = $m['processes'] ?? null;
    $containers = collect($m['containers'] ?? []);
    $fmtAge = fn ($s) => $s >= 86400 ? floor($s / 86400) . ' d' : ($s >= 3600 ? floor($s / 3600) . ' h' : max(1, floor($s / 60)) . ' min');
@endphp

@if(!$proc)
    <x-nx-card>
        <div class="text-sm text-[color:var(--nx-muted)]">Noch keine Prozessdaten — sie kommen mit der nächsten Prüfung.</div>
    </x-nx-card>
@else
    <x-nx-stat-grid :cols="4">
        <x-nx-stat label="Prozesse" icon="heroicon-o-queue-list" :value="$proc['total'] ?? '—'" />
        <x-nx-stat label="Zombies" icon="heroicon-o-bug-ant" :value="$proc['zombies'] ?? 0"
                   :accent="($proc['zombies'] ?? 0) > 0 ? 'var(--nx-warning)' : null" />
        <x-nx-stat label="Kernel" icon="heroicon-o-cpu-chip" :value="$m['kernel'] ?? '—'" />
        <x-nx-stat label="Gestartet" icon="heroicon-o-power"
                   :value="!empty($m['booted_at']) ? \Illuminate\Support\Carbon::parse($m['booted_at'])->format('d.m.Y') : '—'"
                   :hint="!empty($m['booted_at']) ? \Illuminate\Support\Carbon::parse($m['booted_at'])->diffForHumans() : null" />
    </x-nx-stat-grid>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @foreach(['top_cpu' => ['Meiste CPU', 'cpu'], 'top_mem' => ['Meister Arbeitsspeicher', 'mem']] as $key => [$title, $sortField])
            <x-nx-section icon="heroicon-o-chart-bar" :title="$title" description="Momentaufnahme der letzten Prüfung">
                <x-nx-card flush>
                    <div class="overflow-x-auto">
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Prozess</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Benutzer</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">CPU</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">RAM</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Läuft</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @forelse($proc[$key] ?? [] as $p)
                                    <x-nx-table-row>
                                        <x-nx-table-cell class="font-mono text-xs">{{ $p['command'] }} <span class="text-[color:var(--nx-faint)]">#{{ $p['pid'] }}</span></x-nx-table-cell>
                                        <x-nx-table-cell class="text-xs text-[color:var(--nx-muted)]">{{ $p['user'] }}</x-nx-table-cell>
                                        <x-nx-table-cell align="right" @class(['tabular-nums text-xs', 'font-medium' => $sortField === 'cpu'])>{{ number_format($p['cpu'], 1, ',', '') }} %</x-nx-table-cell>
                                        <x-nx-table-cell align="right" @class(['tabular-nums text-xs', 'font-medium' => $sortField === 'mem'])>{{ number_format($p['mem'], 1, ',', '') }} %</x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums text-xs text-[color:var(--nx-muted)]">{{ $fmtAge($p['seconds']) }}</x-nx-table-cell>
                                    </x-nx-table-row>
                                @empty
                                    <x-nx-table-row><x-nx-table-cell colspan="5" class="text-sm text-[color:var(--nx-muted)]">Keine Daten.</x-nx-table-cell></x-nx-table-row>
                                @endforelse
                            </x-nx-table-body>
                        </x-nx-table>
                    </div>
                </x-nx-card>
            </x-nx-section>
        @endforeach
    </div>

    @if($containers->isNotEmpty())
        <x-nx-section icon="heroicon-o-cube" title="Container" :hint="$containers->count()">
            <x-nx-card flush class="divide-y divide-[color:var(--nx-line)]">
                @foreach($containers as $c)
                    @php
                        $st = strtolower($c['status']);
                        $variant = str_contains($st, 'unhealthy') || str_starts_with($st, 'restarting') || (str_starts_with($st, 'exited') && !str_starts_with($st, 'exited (0)')) ? 'danger'
                            : (str_starts_with($st, 'up') ? 'success' : 'neutral');
                    @endphp
                    <div class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                        <span class="font-mono text-xs">{{ $c['name'] }}</span>
                        <x-nx-badge :variant="$variant" dot>{{ $c['status'] }}</x-nx-badge>
                    </div>
                @endforeach
            </x-nx-card>
        </x-nx-section>
    @endif
@endif
