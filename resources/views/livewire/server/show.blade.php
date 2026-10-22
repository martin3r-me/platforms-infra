@php
    use Platform\Infra\Models\InfraFinding;
    use Platform\Infra\Models\InfraLogEntry;
    use Platform\Infra\Models\InfraServer;
    $m = $server->last_report ?? [];
    $disk = $server->maxDiskUsedPct();
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar :title="$server->name" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Infra', 'icon' => 'server-stack', 'href' => route('infra.dashboard')],
            ['label' => $server->name],
        ]">
            <x-nx-button variant="secondary" size="sm" wire:click="openEdit">
                @svg('heroicon-o-pencil-square', 'w-4 h-4')
                <span>Bearbeiten</span>
            </x-nx-button>
        </x-ui-page-actionbar>
    </x-slot>

    <x-ui-page-container width="contained" spacing="space-y-6">
        <div wire:poll.60s.visible class="flex flex-wrap items-center gap-2">
            <x-nx-badge :variant="InfraServer::healthVariant($health)" dot>{{ InfraServer::healthLabel($health) }}</x-nx-badge>
            @if($server->status !== InfraServer::STATUS_ACTIVE)
                <x-nx-badge>{{ $server->status === InfraServer::STATUS_MAINTENANCE ? 'Wartung' : 'Stillgelegt' }}</x-nx-badge>
            @endif
            <span class="text-sm text-[color:var(--nx-muted)]">
                {{ collect([$server->role, $server->os, $server->ip_private, $server->ip_public])->filter()->implode(' · ') }}
            </span>
        </div>

        <x-nx-stat-grid :cols="4">
            <x-nx-stat label="Last (1 min)" icon="heroicon-o-cpu-chip"
                       :value="isset($m['load1']) ? number_format((float) $m['load1'], 2, ',', '') : '—'"
                       :hint="!empty($m['cpu_count']) ? $m['cpu_count'] . ' Kerne' : null" />
            <x-nx-stat label="Arbeitsspeicher" icon="heroicon-o-rectangle-stack"
                       :value="isset($m['mem_used_pct']) ? round((float) $m['mem_used_pct']) . ' %' : '—'"
                       :hint="isset($m['swap_used_pct']) ? 'Swap ' . round((float) $m['swap_used_pct']) . ' %' : null" />
            <x-nx-stat label="Platte (max.)" icon="heroicon-o-circle-stack"
                       :value="$disk !== null ? round($disk) . ' %' : '—'"
                       :accent="$disk !== null && $disk >= 90 ? 'var(--nx-danger)' : ($disk !== null && $disk >= 80 ? 'var(--nx-warning)' : null)" />
            <x-nx-stat label="Letzte Meldung" icon="heroicon-o-signal"
                       :value="$server->last_seen_at?->diffForHumans() ?? 'nie'"
                       :hint="isset($m['uptime_s']) ? 'läuft seit ' . \Carbon\CarbonInterval::seconds((int) $m['uptime_s'])->cascade()->forHumans(['parts' => 2]) : null" />
        </x-nx-stat-grid>

        <x-nx-tabs>
            @foreach(['findings' => 'Befunde', 'tasks' => 'Maßnahmen', 'logs' => 'Logs', 'metrics' => 'Verlauf', 'details' => 'Details'] as $key => $label)
                <x-nx-tab :active="$tab === $key" wire:click="setTab('{{ $key }}')">
                    {{ $label }}
                    @if(($tabCounts[$key] ?? 0) > 0)
                        <span class="ml-1 rounded-full bg-[color:var(--nx-hover)] px-1.5 text-xs tabular-nums text-[color:var(--nx-muted)]">{{ $tabCounts[$key] }}</span>
                    @endif
                </x-nx-tab>
            @endforeach
        </x-nx-tabs>

        @if($tab === 'findings')
            <div class="flex flex-wrap items-center gap-2">
                @foreach(['open' => 'Offen', 'resolved' => 'Behoben', 'accepted' => 'Akzeptiert', 'all' => 'Alle'] as $key => $label)
                    <x-nx-button :variant="$findingFilter === $key ? 'primary' : 'ghost'" size="sm" wire:click="$set('findingFilter', '{{ $key }}')">{{ $label }}</x-nx-button>
                @endforeach
            </div>

            @if($findings->isEmpty())
                <x-nx-card>
                    <div class="text-sm text-[color:var(--nx-muted)]">Keine Befunde in dieser Ansicht.</div>
                </x-nx-card>
            @else
                <x-nx-card flush class="divide-y divide-[color:var(--nx-line)]">
                    @foreach($findings as $f)
                        <div class="p-4 space-y-2" wire:key="finding-{{ $f->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-nx-badge :variant="InfraFinding::severityVariant($f->severity)" dot>{{ InfraFinding::severityLabel($f->severity) }}</x-nx-badge>
                                        <span class="font-medium text-[color:var(--nx-text)]">{{ $f->title }}</span>
                                    </div>
                                    <div class="text-xs text-[color:var(--nx-muted)] tabular-nums">
                                        {{ $f->check_key }}@if($f->subject) · {{ $f->subject }}@endif
                                        · {{ InfraFinding::statusLabel($f->status) }}
                                        · {{ $f->occurrence_count }}× gesehen, zuerst {{ $f->first_seen_at?->format('d.m.Y H:i') }}, zuletzt {{ $f->last_seen_at?->diffForHumans() }}
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if($f->status === InfraFinding::STATUS_OPEN)
                                        <x-nx-button size="sm" variant="ghost" wire:click="startTransition({{ $f->id }}, 'acknowledged')">In Arbeit</x-nx-button>
                                    @endif
                                    @if($f->isOpen())
                                        @if($taskByFinding->has($f->id))
                                            <x-nx-button size="sm" variant="ghost" wire:click="setTab('tasks')">Maßnahme offen</x-nx-button>
                                        @else
                                            <x-nx-button size="sm" variant="ghost" wire:click="taskFromFinding({{ $f->id }})">Maßnahme anlegen</x-nx-button>
                                        @endif
                                        <x-nx-button size="sm" variant="secondary" wire:click="startTransition({{ $f->id }}, 'resolved')">Behoben</x-nx-button>
                                        <x-nx-button size="sm" variant="ghost" wire:click="startTransition({{ $f->id }}, 'accepted')">Akzeptieren</x-nx-button>
                                    @else
                                        <x-nx-button size="sm" variant="ghost" wire:click="startTransition({{ $f->id }}, 'open')">Wieder öffnen</x-nx-button>
                                    @endif
                                </div>
                            </div>
                            @if($f->detail)
                                <pre class="text-xs whitespace-pre-wrap break-words text-[color:var(--nx-muted)] bg-[color:var(--nx-bg)] rounded-[6px] p-3 max-h-48 overflow-auto">{{ $f->detail }}</pre>
                            @endif
                            @if($f->resolution_note)
                                <div class="text-xs text-[color:var(--nx-muted)]">Notiz: {{ $f->resolution_note }}</div>
                            @endif
                        </div>
                    @endforeach
                </x-nx-card>
            @endif
        @endif

        @if($tab === 'tasks')
            <livewire:infra.task-board :server-id="$server->id" :key="'tasks-' . $server->id" />
        @endif

        @if($tab === 'logs')
            <div class="flex flex-wrap items-center gap-2">
                @foreach(['problems' => 'Warnungen & Fehler', 'errors' => 'Nur Fehler', 'all' => 'Alle'] as $key => $label)
                    <x-nx-button :variant="$logLevel === $key ? 'primary' : 'ghost'" size="sm" wire:click="$set('logLevel', '{{ $key }}')">{{ $label }}</x-nx-button>
                @endforeach
            </div>

            @if($logs->isEmpty())
                <x-nx-card>
                    <div class="text-sm text-[color:var(--nx-muted)]">Keine Log-Einträge in dieser Ansicht.</div>
                </x-nx-card>
            @else
                <x-nx-card flush>
                    <div class="overflow-x-auto">
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Zeit</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Stufe</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Quelle</x-nx-table-header-cell>
                                <x-nx-table-header-cell>Meldung</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($logs as $l)
                                    <x-nx-table-row wire:key="log-{{ $l->id }}">
                                        <x-nx-table-cell class="whitespace-nowrap tabular-nums text-[color:var(--nx-muted)]">{{ $l->occurred_at?->format('d.m. H:i:s') }}</x-nx-table-cell>
                                        <x-nx-table-cell><x-nx-badge :variant="InfraLogEntry::levelVariant($l->level)">{{ $l->level }}</x-nx-badge></x-nx-table-cell>
                                        <x-nx-table-cell class="whitespace-nowrap text-[color:var(--nx-muted)]">{{ $l->source ?? '—' }}</x-nx-table-cell>
                                        <x-nx-table-cell><span class="font-mono text-xs break-all">{{ \Illuminate\Support\Str::limit($l->message, 400) }}</span></x-nx-table-cell>
                                    </x-nx-table-row>
                                @endforeach
                            </x-nx-table-body>
                        </x-nx-table>
                    </div>
                </x-nx-card>
                <div>{{ $logs->links() }}</div>
            @endif
        @endif

        @if($tab === 'metrics')
            @if($metrics->isEmpty())
                <x-nx-card>
                    <div class="text-sm text-[color:var(--nx-muted)]">Noch keine Messwerte der letzten 7 Tage.</div>
                </x-nx-card>
            @else
                @php
                    $series = [
                        ['key' => 'disk_used_pct', 'label' => 'Platte (max.) %', 'max' => 100],
                        ['key' => 'mem_used_pct', 'label' => 'Arbeitsspeicher %', 'max' => 100],
                        ['key' => 'load1', 'label' => 'Last (1 min)', 'max' => max(1, (float) $metrics->max('load1'), (float) ($m['cpu_count'] ?? 1))],
                    ];
                    $n = $metrics->count();
                @endphp
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($series as $s)
                        @php
                            $vals = $metrics->pluck($s['key'])->map(fn ($v) => $v === null ? null : (float) $v)->values();
                            $pts = [];
                            foreach ($vals as $i => $v) {
                                if ($v === null) continue;
                                $x = $n > 1 ? round($i / ($n - 1) * 300, 1) : 150;
                                $y = round(80 - min(1, $v / $s['max']) * 76, 1);
                                $pts[] = $x . ',' . $y;
                            }
                            $last = $vals->filter(fn ($v) => $v !== null)->last();
                        @endphp
                        <x-nx-card>
                            <div class="flex items-baseline justify-between mb-2">
                                <span class="text-xs uppercase tracking-wide text-[color:var(--nx-faint)]">{{ $s['label'] }}</span>
                                <span class="text-sm font-medium tabular-nums text-[color:var(--nx-text)]">{{ $last !== null ? number_format($last, $s['key'] === 'load1' ? 2 : 0, ',', '') : '—' }}</span>
                            </div>
                            <svg viewBox="0 0 300 80" class="w-full h-20" preserveAspectRatio="none" role="img" aria-label="{{ $s['label'] }} der letzten 7 Tage">
                                <line x1="0" y1="80" x2="300" y2="80" stroke="var(--nx-line)" stroke-width="1" />
                                @if(count($pts) > 1)
                                    <polyline points="0,80 {{ implode(' ', $pts) }} 300,80" fill="var(--nx-accent)" fill-opacity="0.08" stroke="none" />
                                    <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="var(--nx-accent)" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                                @endif
                            </svg>
                            <div class="text-xs text-[color:var(--nx-faint)] mt-1">{{ $n }} Messungen · 7 Tage</div>
                        </x-nx-card>
                    @endforeach
                </div>
            @endif
        @endif

        @if($tab === 'details')
            <x-nx-card>
                <div class="space-y-1">
                    <x-nx-property-row icon="heroicon-o-tag" label="Rolle">{{ $server->role ?: '—' }}</x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-globe-alt" label="Hostname">{{ $server->hostname ?: '—' }}</x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-cloud" label="Anbieter">{{ $server->provider }}{{ $server->provider_ref ? ' · ' . $server->provider_ref : '' }}</x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-beaker" label="Umgebung">{{ $server->environment }}</x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-command-line" label="SSH">{{ $server->ssh_user ?: '—' }}{{ $server->ssh_port ? ' · Port ' . $server->ssh_port : '' }}</x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-user" label="Verantwortlich">{{ $server->userInCharge?->name ?? '—' }}</x-nx-property-row>
                    <x-nx-property-row icon="heroicon-o-computer-desktop" label="Betriebssystem">{{ $server->os ?: '—' }}</x-nx-property-row>
                </div>
            </x-nx-card>
            @if(!empty($m['disks']))
                <x-nx-section icon="heroicon-o-circle-stack" title="Datenträger">
                    <x-nx-card flush>
                        <x-nx-table>
                            <x-nx-table-header>
                                <x-nx-table-header-cell>Einhängepunkt</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Größe</x-nx-table-header-cell>
                                <x-nx-table-header-cell align="right">Belegt</x-nx-table-header-cell>
                            </x-nx-table-header>
                            <x-nx-table-body>
                                @foreach($m['disks'] as $d)
                                    <x-nx-table-row>
                                        <x-nx-table-cell class="font-mono text-xs">{{ $d['mount'] ?? '?' }}</x-nx-table-cell>
                                        <x-nx-table-cell align="right" class="tabular-nums">{{ isset($d['size_gb']) ? number_format((float) $d['size_gb'], 0, ',', '.') . ' GB' : '—' }}</x-nx-table-cell>
                                        <x-nx-table-cell align="right">@include('infra::livewire.partials.meter', ['value' => $d['used_pct'] ?? null, 'warn' => 85, 'crit' => 95])</x-nx-table-cell>
                                    </x-nx-table-row>
                                @endforeach
                            </x-nx-table-body>
                        </x-nx-table>
                    </x-nx-card>
                </x-nx-section>
            @endif
            @if($server->notes)
                <x-nx-card><div class="text-sm whitespace-pre-wrap text-[color:var(--nx-text)]">{{ $server->notes }}</div></x-nx-card>
            @endif
            <div>
                @if($confirmDelete)
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-[color:var(--nx-danger)]">Server mit allen Befunden, Logs und Messwerten aus der Übersicht entfernen?</span>
                        <x-nx-button variant="danger" size="sm" wire:click="deleteServer">Entfernen</x-nx-button>
                        <x-nx-button variant="ghost" size="sm" wire:click="$set('confirmDelete', false)">Abbrechen</x-nx-button>
                    </div>
                @else
                    <x-nx-button variant="ghost" size="sm" wire:click="$set('confirmDelete', true)">Server entfernen</x-nx-button>
                @endif
            </div>
        @endif
    </x-ui-page-container>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Server" width="w-80" :defaultOpen="true">
            <div class="p-6 space-y-6">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[color:var(--nx-faint)] mb-3">Offen</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between"><span class="text-[color:var(--nx-muted)]">Kritisch</span><span class="tabular-nums">{{ $server->open_critical_count }}</span></div>
                        <div class="flex justify-between"><span class="text-[color:var(--nx-muted)]">Warnungen</span><span class="tabular-nums">{{ $server->open_warning_count }}</span></div>
                    </div>
                </div>
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-[color:var(--nx-faint)] mb-3">Verantwortlich</h3>
                    <div class="text-sm text-[color:var(--nx-text)]">{{ $server->userInCharge?->name ?? '—' }}</div>
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-nx-modal size="lg" wire:model="showEdit">
        <x-slot name="header">Server bearbeiten</x-slot>
        @include('infra::livewire.partials.server-form', ['options' => $options])
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showEdit', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="saveServer">Speichern</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>

    <x-nx-modal size="sm" wire:model="showNote">
        <x-slot name="header">{{ $noteStatus === 'accepted' ? 'Befund akzeptieren' : 'Befund als behoben markieren' }}</x-slot>
        <x-nx-input-textarea name="note" :label="$noteStatus === 'accepted' ? 'Warum ist das in Ordnung?' : 'Was wurde getan? (optional)'" wire:model="note" :rows="3" />
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <x-nx-button variant="ghost" wire:click="$set('showNote', false)">Abbrechen</x-nx-button>
                <x-nx-button variant="primary" wire:click="confirmTransition">Speichern</x-nx-button>
            </div>
        </x-slot>
    </x-nx-modal>
</x-ui-page>
