<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Infra" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Infra', 'icon' => 'server-stack'],
        ]">
            <x-nx-button variant="primary" size="sm" wire:click="openCreate">
                @svg('heroicon-o-plus', 'w-4 h-4')
                <span>Server anlegen</span>
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Server" :value="$stats['servers']" icon="heroicon-o-server-stack"
                       :hint="$stats['reporting'] . ' melden, ' . $stats['silent'] . ' still'" />
            <x-nx-stat label="Kritisch" :value="$stats['critical']" icon="heroicon-o-x-circle"
                       :accent="$stats['critical'] > 0 ? 'var(--nx-danger)' : null" hint="offene Befunde" />
            <x-nx-stat label="Warnungen" :value="$stats['warning']" icon="heroicon-o-exclamation-triangle"
                       :accent="$stats['warning'] > 0 ? 'var(--nx-warning)' : null" hint="offene Befunde" />
            <x-nx-stat label="Fehler im Log" :value="$stats['errors_24h']" icon="heroicon-o-document-text" hint="letzte 24 h" />
        </x-nx-stat-grid>

        <x-nx-section icon="heroicon-o-server-stack" title="Server" :hint="$servers->count()">
            <x-slot name="action">
                <x-nx-button variant="ghost" size="sm" wire:click="$toggle('showRetired')">
                    {{ $showRetired ? 'Stillgelegte ausblenden' : 'Stillgelegte zeigen' }}
                </x-nx-button>
            </x-slot>

            @if($servers->isEmpty())
                <x-nx-card>
                    <x-nx-empty icon="heroicon-o-server-stack">
                        Noch keine Server. Lege den ersten an — oder lass den Beobachter (Jazz) ihn über die Agent-API melden.
                        <x-slot name="action">
                            <x-nx-button variant="secondary" size="sm" wire:click="openCreate">Server anlegen</x-nx-button>
                        </x-slot>
                    </x-nx-empty>
                </x-nx-card>
            @else
                <x-nx-card flush>
                    <div class="overflow-x-auto">
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Server</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Zustand</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Last</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">RAM</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Platte</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Befunde</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Letzte Meldung</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($servers as $server)
                                    @php
                                        $h = $server->health();
                                        $m = $server->last_report ?? [];
                                        $disk = $server->maxDiskUsedPct();
                                    @endphp
                                    <x-nx-table-row :href="route('infra.servers.show', $server)">
                                        <x-nx-table-cell>
                                            <div class="font-medium text-[color:var(--nx-text)]">{{ $server->name }}</div>
                                            <div class="text-xs text-[color:var(--nx-muted)]">
                                                {{ collect([$server->role, $server->ip_private ?: $server->ip_public])->filter()->implode(' · ') ?: '—' }}
                                            </div>
                                        </x-nx-table-cell>
                                        <x-nx-table-cell>
                                            <x-nx-badge :variant="\Platform\Infra\Models\InfraServer::healthVariant($h)" dot>
                                                {{ \Platform\Infra\Models\InfraServer::healthLabel($h) }}
                                            </x-nx-badge>
                                            @if($server->status !== 'active')
                                                <x-nx-badge>{{ $server->status === 'maintenance' ? 'Wartung' : 'Stillgelegt' }}</x-nx-badge>
                                            @endif
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums">
                                            {{ isset($m['load1']) ? number_format((float) $m['load1'], 2, ',', '') : '—' }}
                                            @if(!empty($m['cpu_count']))<span class="text-[color:var(--nx-faint)]">/{{ $m['cpu_count'] }}</span>@endif
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums">
                                            {{ isset($m['mem_used_pct']) ? round((float) $m['mem_used_pct']) . ' %' : '—' }}
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums">
                                            <span @class(['text-[color:var(--nx-danger)] font-medium' => $disk !== null && $disk >= 90, 'text-[color:var(--nx-warning)]' => $disk !== null && $disk >= 80 && $disk < 90])>
                                                {{ $disk !== null ? round($disk) . ' %' : '—' }}
                                            </span>
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums">
                                            @if($server->open_critical_count)
                                                <x-nx-badge variant="danger">{{ $server->open_critical_count }}</x-nx-badge>
                                            @endif
                                            @if($server->open_warning_count)
                                                <x-nx-badge variant="warning">{{ $server->open_warning_count }}</x-nx-badge>
                                            @endif
                                            @if(!$server->open_critical_count && !$server->open_warning_count)
                                                <span class="text-[color:var(--nx-faint)]">—</span>
                                            @endif
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="text-[color:var(--nx-muted)]">
                                            {{ $server->last_seen_at?->diffForHumans() ?? 'nie' }}
                                        </x-nx-table-cell>
                                    </x-nx-table-row>
                                @endforeach
                            </x-nx-table-body>
                        </x-nx-table>
                    </div>
                </x-nx-card>
            @endif
        </x-nx-section>

        <x-nx-section icon="heroicon-o-exclamation-triangle" title="Offene Befunde" :hint="$stats['critical'] + $stats['warning']"
                      description="Dedupliziert je Server und Prüfung — ein Problem ist eine Zeile, egal wie oft es gesehen wurde.">
            @if($openFindings->isEmpty())
                <x-nx-card>
                    <div class="text-sm text-[color:var(--nx-muted)]">Keine offenen Befunde.</div>
                </x-nx-card>
            @else
                <x-nx-card flush class="divide-y divide-[color:var(--nx-line)]">
                    @foreach($openFindings as $f)
                        <x-nx-list-item :href="route('infra.servers.show', $f->infra_server_id)"
                                        :icon="$f->severity === 'critical' ? 'heroicon-o-x-circle' : 'heroicon-o-exclamation-triangle'"
                                        :title="$f->title"
                                        :subtitle="($f->server?->name ?? '—') . ' · ' . $f->check_key . ($f->subject ? ' · ' . $f->subject : '')"
                                        :meta="'seit ' . $f->first_seen_at?->diffForHumans(null, true) . ($f->status === 'acknowledged' ? ' · in Arbeit' : '')" />
                    @endforeach
                </x-nx-card>
            @endif
        </x-nx-section>
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Übersicht" width="w-80" :defaultOpen="true">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[color:var(--nx-faint)] mb-3">Zustand</h3>
                    <div class="space-y-2 text-sm">
                        @foreach(['critical', 'warning', 'stale', 'ok', 'unknown'] as $h)
                            @php $n = $servers->filter(fn ($s) => $s->health() === $h)->count(); @endphp
                            @if($n > 0)
                                <div class="flex items-center justify-between">
                                    <x-nx-badge :variant="\Platform\Infra\Models\InfraServer::healthVariant($h)" dot>{{ \Platform\Infra\Models\InfraServer::healthLabel($h) }}</x-nx-badge>
                                    <span class="tabular-nums text-[color:var(--nx-muted)]">{{ $n }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[color:var(--nx-faint)] mb-3">So entsteht das Bild</h3>
                    <p class="text-sm text-[color:var(--nx-muted)]">
                        Der Beobachter prüft jeden Server und meldet Messwerte, Befunde und Fehler-Logs.
                        Was bei einer Prüfung nicht mehr auftaucht, wird automatisch als behoben markiert.
                    </p>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Zuletzt behoben" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-6 space-y-3">
                @forelse($recentlyResolved as $f)
                    <div class="text-sm">
                        <div class="text-[color:var(--nx-text)]">{{ $f->title }}</div>
                        <div class="text-xs text-[color:var(--nx-muted)]">{{ $f->server?->name }} · {{ $f->resolved_at?->diffForHumans() }}</div>
                    </div>
                @empty
                    <div class="text-sm text-[color:var(--nx-muted)]">Noch nichts behoben.</div>
                @endforelse
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-nx-modal size="lg" wire:model="showCreate">
        <x-slot name="header">Server anlegen</x-slot>
        @include('infra::livewire.partials.server-form', ['options' => $options])
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showCreate', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="createServer">Anlegen</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
