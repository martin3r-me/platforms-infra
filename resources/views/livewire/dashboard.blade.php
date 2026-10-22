@php
    use Platform\Infra\Models\InfraFinding;
    use Platform\Infra\Models\InfraServer;
    $diskWarn = 85; $diskCrit = 95; $memWarn = 90; $memCrit = 95;
@endphp

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
        {{-- Live: Daten kommen alle paar Minuten vom Beobachter --}}
        <div wire:poll.60s.visible class="flex flex-wrap items-center justify-between gap-2 text-xs text-[color:var(--nx-muted)]">
            <span class="inline-flex items-center gap-1.5">
                <span @class([
                    'inline-block h-2 w-2 rounded-full',
                    'bg-[color:var(--nx-success)]' => $stats['last_check'] && $stats['last_check']->gt(now()->subMinutes(20)),
                    'bg-[color:var(--nx-warning)]' => !$stats['last_check'] || $stats['last_check']->lte(now()->subMinutes(20)),
                ])></span>
                {{ $stats['last_check'] ? 'Zuletzt geprüft ' . $stats['last_check']->diffForHumans() : 'Noch keine Prüfung' }}
            </span>
            <span>{{ $stats['errors_24h'] }} Fehler im Systemlog (24 h)</span>
        </div>

        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Server" :value="$stats['servers']" icon="heroicon-o-server-stack"
                       :hint="$counts['problems'] . ' mit Problemen'" :href="route('infra.dashboard')" wire:navigate />
            <x-nx-stat label="Kritisch" :value="$stats['critical']" icon="heroicon-o-x-circle"
                       :accent="$stats['critical'] > 0 ? 'var(--nx-danger)' : null" hint="offene Befunde"
                       :href="route('infra.dashboard', ['filter' => 'problems'])" wire:navigate />
            <x-nx-stat label="Warnungen" :value="$stats['warning']" icon="heroicon-o-exclamation-triangle"
                       :accent="$stats['warning'] > 0 ? 'var(--nx-warning)' : null" hint="offene Befunde"
                       :href="route('infra.dashboard', ['filter' => 'problems'])" wire:navigate />
            <x-nx-stat label="Maßnahmen" :value="$stats['tasks_open']" icon="heroicon-o-view-columns"
                       :hint="$stats['tasks_mine'] ? $stats['tasks_mine'] . ' bei mir' : 'offen'" :href="route('infra.tasks')" wire:navigate />
        </x-nx-stat-grid>

        <x-nx-section icon="heroicon-o-server-stack" title="Server" :hint="$servers->count() . ' von ' . $counts['all']">
            <x-slot name="action">
                <x-nx-button variant="ghost" size="sm" wire:click="$toggle('showRetired')">
                    {{ $showRetired ? 'Stillgelegte ausblenden' : 'Stillgelegte zeigen' }}
                </x-nx-button>
            </x-slot>

            <div class="flex flex-wrap items-center gap-3">
                <div class="relative min-w-[14rem] flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-2.5 flex items-center text-[color:var(--nx-faint)]">
                        @svg('heroicon-o-magnifying-glass', 'w-4 h-4')
                    </span>
                    <input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, Rolle oder IP suchen …"
                           aria-label="Server suchen"
                           class="w-full rounded-[6px] border border-[color:var(--nx-line-strong)] bg-[color:var(--nx-surface)] py-1.5 pl-8 pr-3 text-sm text-[color:var(--nx-text)] placeholder:text-[color:var(--nx-faint)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--nx-line-strong)]" />
                </div>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Filter">
                    @foreach(['all' => 'Alle', 'problems' => 'Probleme', 'ok' => 'OK', 'silent' => 'Still'] as $key => $label)
                        <x-nx-button size="sm" :variant="$filter === $key ? 'primary' : 'ghost'" wire:click="setFilter('{{ $key }}')" :aria-pressed="$filter === $key ? 'true' : 'false'">
                            {{ $label }} <span class="tabular-nums opacity-70">{{ $counts[$key] }}</span>
                        </x-nx-button>
                    @endforeach
                </div>
            </div>

            @if($counts['all'] === 0)
                <x-nx-card>
                    <x-nx-empty icon="heroicon-o-server-stack">
                        Noch keine Server. Lege den ersten an — oder lass den Beobachter (Jazz) sie erkennen.
                        <x-slot name="action">
                            <x-nx-button variant="secondary" size="sm" wire:click="openCreate">Server anlegen</x-nx-button>
                        </x-slot>
                    </x-nx-empty>
                </x-nx-card>
            @elseif($servers->isEmpty())
                <x-nx-card>
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-[color:var(--nx-muted)]">
                        <span>Keine Server passen zu Suche und Filter.</span>
                        <x-nx-button size="sm" variant="ghost" wire:click="$set('search', ''); $set('filter', 'all')">Zurücksetzen</x-nx-button>
                    </div>
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
                                <x-nx-table-header-cell align="right">Offen</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Geprüft</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($servers as $server)
                                    @php
                                        $h = $server->health_state;
                                        $m = $server->last_report ?? [];
                                        $url = route('infra.servers.show', $server);
                                        $loadRatio = (!empty($m['cpu_count']) && isset($m['load1'])) ? (float) $m['load1'] / (int) $m['cpu_count'] : null;
                                    @endphp
                                    <x-nx-table-row wire:key="srv-{{ $server->id }}" class="group cursor-pointer"
                                        x-data
                                        x-on:click="if ($event.target.closest('a,button')) return; ($event.metaKey || $event.ctrlKey) ? window.open('{{ $url }}', '_blank') : Livewire.navigate('{{ $url }}')">
                                        <x-nx-table-cell>
                                            <a href="{{ $url }}" wire:navigate class="font-medium text-[color:var(--nx-text)] group-hover:underline underline-offset-2 focus:outline-none focus-visible:underline">
                                                {{ $server->name }}
                                            </a>
                                            <div class="text-xs text-[color:var(--nx-muted)]">
                                                {{ collect([$server->role, $server->ip_private ?: $server->ip_public])->filter()->implode(' · ') ?: '—' }}
                                            </div>
                                        </x-nx-table-cell>
                                        <x-nx-table-cell>
                                            <div class="flex flex-wrap items-center gap-1">
                                                <x-nx-badge :variant="InfraServer::healthVariant($h)" dot>{{ InfraServer::healthLabel($h) }}</x-nx-badge>
                                                @if($server->status !== InfraServer::STATUS_ACTIVE)
                                                    <x-nx-badge>{{ $server->status === InfraServer::STATUS_MAINTENANCE ? 'Wartung' : 'Stillgelegt' }}</x-nx-badge>
                                                @endif
                                            </div>
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums whitespace-nowrap">
                                            <span @class(['text-[color:var(--nx-warning)] font-medium' => $loadRatio !== null && $loadRatio >= 1.5])>
                                                {{ isset($m['load1']) ? number_format((float) $m['load1'], 2, ',', '') : '—' }}
                                            </span>
                                            @if(!empty($m['cpu_count']))<span class="text-[color:var(--nx-faint)]">/{{ $m['cpu_count'] }}</span>@endif
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right">
                                            @include('infra::livewire.partials.meter', ['value' => $m['mem_used_pct'] ?? null, 'warn' => $memWarn, 'crit' => $memCrit])
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right">
                                            @include('infra::livewire.partials.meter', ['value' => $server->maxDiskUsedPct(), 'warn' => $diskWarn, 'crit' => $diskCrit])
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="whitespace-nowrap">
                                            <div class="flex items-center justify-end gap-1">
                                                @if($server->open_critical_count)
                                                    <x-nx-badge variant="danger">{{ $server->open_critical_count }}</x-nx-badge>
                                                @endif
                                                @if($server->open_warning_count)
                                                    <x-nx-badge variant="warning">{{ $server->open_warning_count }}</x-nx-badge>
                                                @endif
                                                @if($server->open_tasks_count)
                                                    <x-nx-badge variant="info" title="offene Maßnahmen">{{ $server->open_tasks_count }} M</x-nx-badge>
                                                @endif
                                                @if(!$server->open_critical_count && !$server->open_warning_count && !$server->open_tasks_count)
                                                    <span class="text-[color:var(--nx-faint)]">—</span>
                                                @endif
                                            </div>
                                        </x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="whitespace-nowrap text-[color:var(--nx-muted)]">
                                            <span title="{{ $server->last_seen_at?->format('d.m.Y H:i') }}">{{ $server->last_seen_at?->diffForHumans(null, true) ?? 'nie' }}</span>
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
                      description="Je Server und Prüfung eine Zeile — egal wie oft das Problem gesehen wurde.">
            @if($openFindings->isEmpty())
                <x-nx-card>
                    <div class="flex items-center gap-2 text-sm text-[color:var(--nx-muted)]">
                        @svg('heroicon-o-check-circle', 'w-4 h-4 text-[color:var(--nx-success)]')
                        Keine offenen Befunde.
                    </div>
                </x-nx-card>
            @else
                <x-nx-card flush class="divide-y divide-[color:var(--nx-line)]">
                    @foreach($openFindings as $f)
                        <x-nx-list-item :href="route('infra.servers.show', $f->infra_server_id)"
                                        :icon="match ($f->severity) { 'critical' => 'heroicon-o-x-circle', 'warning' => 'heroicon-o-exclamation-triangle', default => 'heroicon-o-information-circle' }"
                                        :title="$f->title"
                                        :subtitle="($f->server?->name ?? '—') . ' · seit ' . $f->first_seen_at?->diffForHumans(null, true)">
                            <x-slot name="trailing">
                                <div class="flex items-center gap-1">
                                    @if($f->status === InfraFinding::STATUS_ACKNOWLEDGED)
                                        <x-nx-badge>in Arbeit</x-nx-badge>
                                    @endif
                                    <x-nx-badge :variant="InfraFinding::severityVariant($f->severity)" dot>{{ InfraFinding::severityLabel($f->severity) }}</x-nx-badge>
                                </div>
                            </x-slot>
                        </x-nx-list-item>
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
                    <div class="space-y-1 text-sm">
                        @foreach([InfraServer::HEALTH_CRITICAL, InfraServer::HEALTH_WARNING, InfraServer::HEALTH_STALE, InfraServer::HEALTH_UNKNOWN, InfraServer::HEALTH_OK] as $h)
                            @php $n = $servers->where('health_state', $h)->count(); @endphp
                            <div class="flex items-center justify-between rounded-[6px] px-1 py-0.5">
                                <x-nx-badge :variant="InfraServer::healthVariant($h)" dot>{{ InfraServer::healthLabel($h) }}</x-nx-badge>
                                <span class="tabular-nums {{ $n ? 'text-[color:var(--nx-text)]' : 'text-[color:var(--nx-faint)]' }}">{{ $n }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[color:var(--nx-faint)] mb-3">So entsteht das Bild</h3>
                    <p class="text-sm text-[color:var(--nx-muted)]">
                        Der Beobachter erkennt die Server beim Anbieter, prüft sie regelmäßig und meldet Messwerte,
                        Befunde und Fehler-Logs. Was bei einer Prüfung nicht mehr auftaucht, gilt als behoben.
                    </p>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Zuletzt behoben" width="w-80" :defaultOpen="false" storeKey="activityOpen" side="right">
            <div class="p-6 space-y-3">
                @forelse($recentlyResolved as $f)
                    <a href="{{ route('infra.servers.show', $f->infra_server_id) }}" wire:navigate class="block rounded-[6px] p-1.5 -m-1.5 text-sm hover:bg-[color:var(--nx-hover)]">
                        <div class="text-[color:var(--nx-text)]">{{ $f->title }}</div>
                        <div class="text-xs text-[color:var(--nx-muted)]">{{ $f->server?->name }} · {{ $f->resolved_at?->diffForHumans() }}</div>
                    </a>
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
