{{-- Server · Sicherheit. Erwartet $m (last_report), $security (failed_daily, logins). --}}
@php
    $sec = $m['security'] ?? null;
    $ports = collect($sec['ports'] ?? []);
    $ssh = $sec['ssh'] ?? [];
    $per = $sec['perimeter'] ?? null;
    $publicPorts = $ports->where('exposure', 'public');
    $exposureMeta = [
        'public' => ['aus dem Internet', 'danger'],
        'restricted' => ['freigegebene Quellen', 'warning'],
        'firewalled' => ['Anbieter-Firewall blockiert', 'success'],
        'host_firewall' => ['hinter Host-Firewall', 'success'],
        'unknown' => ['unbekannt', 'neutral'],
        'internal' => ['nur intern', 'neutral'],
    ];
    $hostFw = [
        'active' => ['aktiv', 'success'],
        'inactive' => ['inaktiv', 'danger'],
        'absent' => ['nicht installiert', 'warning'],
        'unknown' => ['nicht einsehbar', 'neutral'],
    ][$sec['host_firewall'] ?? 'unknown'] ?? ['nicht einsehbar', 'neutral'];
    $failedWeek = $security ? $security['failed_daily']->sum() : 0;
@endphp

@if(!$sec)
    <x-nx-card>
        <div class="text-sm text-[color:var(--nx-muted)]">Noch keine Sicherheitsdaten — sie kommen mit der nächsten Prüfung durch den Beobachter.</div>
    </x-nx-card>
@else
    <x-nx-stat-grid :cols="4">
        <x-nx-stat label="Firewall" icon="heroicon-o-shield-check"
                   :value="$per && !empty($per['firewalls']) ? 'Perimeter' : ($sec['host_firewall'] === 'active' ? 'Host' : 'keine')"
                   :accent="($per && !empty($per['firewalls'])) || $sec['host_firewall'] === 'active' ? 'var(--nx-success)' : (($sec['host_firewall'] ?? '') === 'unknown' ? null : 'var(--nx-danger)')"
                   :hint="'Host: ' . $hostFw[0]" />
        <x-nx-stat label="Öffentlich erreichbar" icon="heroicon-o-globe-alt" :value="$publicPorts->count() . ' Ports'"
                   :accent="$publicPorts->pluck('port')->diff([22, 80, 443])->isNotEmpty() ? 'var(--nx-danger)' : null"
                   :hint="$publicPorts->pluck('port')->implode(', ') ?: 'keine'" />
        <x-nx-stat label="Fehlversuche SSH" icon="heroicon-o-finger-print"
                   :value="($ssh['journal_readable'] ?? false) ? number_format($failedWeek, 0, ',', '.') : '—'"
                   :hint="($ssh['journal_readable'] ?? false) ? 'letzte 7 Tage' : 'Log nicht einsehbar'" />
        <x-nx-stat label="Sicherheits-Updates" icon="heroicon-o-arrow-path"
                   :value="($sec['updates_known'] ?? false) ? $sec['updates_security'] : '—'"
                   :accent="($sec['updates_security'] ?? 0) > 0 ? 'var(--nx-warning)' : null"
                   :hint="($sec['updates_known'] ?? false) ? $sec['updates_total'] . ' Updates insgesamt' : 'unbekannt'" />
    </x-nx-stat-grid>

    <x-nx-section icon="heroicon-o-signal" title="Lauschende Ports" :hint="$ports->count()"
                  description="Tatsächliche Erreichbarkeit aus Bindung, Anbieter-Firewall und Host-Firewall.">
        <x-nx-card flush>
            <div class="overflow-x-auto">
                <x-nx-table>
                    <x-nx-table-header>
                        <x-nx-table-header-cell align="right">Port</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Adresse</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Prozess</x-nx-table-header-cell>
                        <x-nx-table-header-cell>Erreichbarkeit</x-nx-table-header-cell>
                    </x-nx-table-header>
                    <x-nx-table-body>
                        @foreach($ports->sortBy(fn ($p) => [array_search($p['exposure'], array_keys($exposureMeta)), $p['port']]) as $p)
                            @php [$label, $variant] = $exposureMeta[$p['exposure']] ?? [$p['exposure'], 'neutral']; @endphp
                            <x-nx-table-row>
                                <x-nx-table-cell align="right" class="font-mono text-xs tabular-nums">{{ $p['port'] }}/{{ $p['proto'] }}</x-nx-table-cell>
                                <x-nx-table-cell class="font-mono text-xs text-[color:var(--nx-muted)]">{{ $p['bind'] }}</x-nx-table-cell>
                                <x-nx-table-cell class="text-sm">{{ $p['process'] ?? '' ?: '—' }}</x-nx-table-cell>
                                <x-nx-table-cell><x-nx-badge :variant="$variant" dot>{{ $label }}</x-nx-badge></x-nx-table-cell>
                            </x-nx-table-row>
                        @endforeach
                    </x-nx-table-body>
                </x-nx-table>
            </div>
        </x-nx-card>
        @if($ports->contains(fn ($p) => empty($p['process'])))
            <p class="text-xs text-[color:var(--nx-faint)]">Fehlende Prozessnamen: dem Beobachter fehlen dafür Rechte.</p>
        @endif
    </x-nx-section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-nx-section icon="heroicon-o-finger-print" title="Wer wollte rein">
            <x-nx-card>
                @if(!($ssh['journal_readable'] ?? false))
                    <div class="text-sm text-[color:var(--nx-muted)]">Das SSH-Log ist für den Beobachter nicht einsehbar.</div>
                @else
                    @php $max = max(1, $security['failed_daily']->max()); @endphp
                    <div class="flex h-20 items-end gap-1.5" role="img" aria-label="Fehlgeschlagene SSH-Anmeldungen je Tag, letzte 7 Tage">
                        @foreach($security['failed_daily'] as $day => $n)
                            <div class="flex flex-1 flex-col items-center gap-1">
                                <div class="w-full rounded-t-[3px] {{ $n > 0 ? 'bg-[color:var(--nx-warning)]' : 'bg-[color:var(--nx-line)]' }}"
                                     style="height: {{ max(2, round($n / $max * 64)) }}px" title="{{ \Illuminate\Support\Carbon::parse($day)->format('d.m.') }}: {{ $n }}"></div>
                                <span class="text-[10px] text-[color:var(--nx-faint)]">{{ \Illuminate\Support\Carbon::parse($day)->isoFormat('dd') }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <div class="mb-1 text-xs uppercase tracking-wide text-[color:var(--nx-faint)]">Quellen (letzte Prüfung)</div>
                            @forelse($ssh['top_sources'] ?? [] as $src)
                                <div class="flex justify-between font-mono text-xs"><span>{{ $src['key'] }}</span><span class="tabular-nums text-[color:var(--nx-muted)]">{{ $src['count'] }}×</span></div>
                            @empty
                                <div class="text-xs text-[color:var(--nx-muted)]">keine</div>
                            @endforelse
                        </div>
                        <div>
                            <div class="mb-1 text-xs uppercase tracking-wide text-[color:var(--nx-faint)]">Versuchte Benutzer</div>
                            @forelse($ssh['top_users'] ?? [] as $u)
                                <div class="flex justify-between font-mono text-xs"><span>{{ $u['key'] ?: '(leer)' }}</span><span class="tabular-nums text-[color:var(--nx-muted)]">{{ $u['count'] }}×</span></div>
                            @empty
                                <div class="text-xs text-[color:var(--nx-muted)]">keine</div>
                            @endforelse
                        </div>
                    </div>
                @endif
            </x-nx-card>
        </x-nx-section>

        <x-nx-section icon="heroicon-o-key" title="Zugang">
            <x-nx-card>
                <div class="space-y-1">
                    <x-nx-property-row icon="heroicon-o-lock-closed" label="Passwort-Login">
                        <x-nx-badge :variant="($ssh['password_auth'] ?? '') === 'no' ? 'success' : 'danger'" dot>{{ ($ssh['password_auth'] ?? '') === 'no' ? 'aus' : 'an' }}</x-nx-badge>
                    </x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-user" label="Root-Login">
                        <x-nx-badge :variant="($ssh['root_login'] ?? '') === 'yes' ? 'danger' : 'success'" dot>
                            {{ ['yes' => 'mit Passwort', 'no' => 'gesperrt', 'prohibit-password' => 'nur Schlüssel', 'without-password' => 'nur Schlüssel', 'forced-commands-only' => 'nur Befehle'][$ssh['root_login'] ?? ''] ?? ($ssh['root_login'] ?? '—') }}
                        </x-nx-badge>
                    </x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-no-symbol" label="fail2ban">
                        @if(!empty($ssh['fail2ban']))
                            {{ $ssh['fail2ban']['currently_banned'] }} gesperrt · {{ $ssh['fail2ban']['total_banned'] }} insgesamt
                        @else
                            <span class="text-[color:var(--nx-muted)]">nicht einsehbar / nicht aktiv</span>
                        @endif
                    </x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-clock" label="Zeit-Sync">
                        {{ ['yes' => 'synchron', 'no' => 'nicht synchron'][$sec['time_synced'] ?? ''] ?? '—' }}
                    </x-nx-property-row>
                </div>
                <div class="mt-4">
                    <div class="mb-2 text-xs uppercase tracking-wide text-[color:var(--nx-faint)]">Letzte Anmeldungen</div>
                    @forelse($security['logins'] ?? [] as $l)
                        <div class="flex justify-between gap-3 py-0.5 text-xs">
                            <span class="font-mono">{{ $l->message }}</span>
                            <span class="whitespace-nowrap tabular-nums text-[color:var(--nx-muted)]">{{ $l->occurred_at?->format('d.m. H:i') }}</span>
                        </div>
                    @empty
                        <div class="text-xs text-[color:var(--nx-muted)]">{{ ($ssh['journal_readable'] ?? false) ? 'Keine Anmeldungen außer dem Beobachter.' : 'Nicht einsehbar.' }}</div>
                    @endforelse
                </div>
            </x-nx-card>
        </x-nx-section>
    </div>

    @if($per && !empty($per['firewalls']))
        <x-nx-section icon="heroicon-o-shield-check" title="Anbieter-Firewall" :hint="implode(', ', $per['firewalls'])"
                      description="Eingehende Regeln beim Anbieter — alles andere ist von außen blockiert.">
            <x-nx-card flush>
                <div class="overflow-x-auto">
                    <x-nx-table>
                        <x-nx-table-header>
                            <x-nx-table-header-cell>Protokoll</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Port</x-nx-table-header-cell>
                            <x-nx-table-header-cell>Erlaubte Quellen</x-nx-table-header-cell>
                        </x-nx-table-header>
                        <x-nx-table-body>
                            @foreach($per['rules'] ?? [] as $r)
                                @php $world = in_array('0.0.0.0/0', $r['sources'] ?? [], true) || in_array('::/0', $r['sources'] ?? [], true); @endphp
                                <x-nx-table-row>
                                    <x-nx-table-cell class="font-mono text-xs">{{ $r['protocol'] }}</x-nx-table-cell>
                                    <x-nx-table-cell class="font-mono text-xs">{{ $r['port'] ?? '' ?: '—' }}</x-nx-table-cell>
                                    <x-nx-table-cell class="text-xs">
                                        @if($world)
                                            <x-nx-badge variant="warning">Internet</x-nx-badge>
                                        @else
                                            <span class="font-mono">{{ implode(', ', $r['sources'] ?? []) }}</span>
                                        @endif
                                        @if(!empty($r['note']))<span class="text-[color:var(--nx-muted)]"> · {{ $r['note'] }}</span>@endif
                                    </x-nx-table-cell>
                                </x-nx-table-row>
                            @endforeach
                        </x-nx-table-body>
                    </x-nx-table>
                </div>
            </x-nx-card>
        </x-nx-section>
    @endif
@endif
