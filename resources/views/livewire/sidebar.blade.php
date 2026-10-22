<div>
    <div x-show="!collapsed" class="p-3 text-sm italic text-[var(--nx-text)] uppercase border-b border-[color:var(--nx-line)] mb-2">
        Infra
    </div>

    <x-ui-sidebar-list label="Allgemein">
        <x-ui-sidebar-item :href="route('infra.dashboard')">
            @svg('heroicon-o-home', 'w-4 h-4 text-[var(--nx-text)]')
            <span class="ml-2 text-sm">Dashboard</span>
        </x-ui-sidebar-item>
        <x-ui-sidebar-item :href="route('infra.tasks')">
            @svg('heroicon-o-view-columns', 'w-4 h-4 text-[var(--nx-text)]')
            <span class="ml-2 text-sm">Maßnahmen</span>
        </x-ui-sidebar-item>
    </x-ui-sidebar-list>

    @if($servers->isNotEmpty())
        <x-ui-sidebar-list label="Server">
            @foreach($servers as $server)
                @php $h = $server->health(); @endphp
                <x-ui-sidebar-item :href="route('infra.servers.show', $server)">
                    <span @class([
                        'inline-block w-2 h-2 rounded-full shrink-0',
                        'bg-[color:var(--nx-success)]' => $h === 'ok',
                        'bg-[color:var(--nx-warning)]' => in_array($h, ['warning', 'stale'], true),
                        'bg-[color:var(--nx-danger)]' => $h === 'critical',
                        'bg-[color:var(--nx-line)]' => $h === 'unknown',
                    ])></span>
                    <span class="ml-2 text-sm truncate">{{ $server->name }}</span>
                </x-ui-sidebar-item>
            @endforeach
        </x-ui-sidebar-list>
    @endif

    <div x-show="collapsed" class="px-2 py-2 border-b border-[color:var(--nx-line)]">
        <div class="flex flex-col gap-2">
            <a href="{{ route('infra.dashboard') }}" wire:navigate class="flex items-center justify-center p-2 rounded-md text-[var(--nx-text)] hover:bg-[var(--nx-bg)]">
                @svg('heroicon-o-server-stack', 'w-5 h-5')
            </a>
        </div>
    </div>
</div>
