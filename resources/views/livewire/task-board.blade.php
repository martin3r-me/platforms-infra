@php
    use Platform\Infra\Models\InfraTask;
@endphp

<div class="space-y-4">
    <form wire:submit="addTask" class="flex flex-wrap items-end gap-3">
        <div class="min-w-[16rem] flex-1">
            <x-nx-input-text name="newTitle" label="Neue Maßnahme" wire:model="newTitle" placeholder="z. B. logrotate-Konfiguration reparieren" />
        </div>
        @if(!$serverId)
            <div class="w-64">
                <x-nx-input-select name="newServerId" label="Server" wire:model="newServerId" :options="$serverOptions" nullable nullLabel="— ohne Server —" />
            </div>
        @endif
        <x-nx-button variant="primary" type="submit">Hinzufügen</x-nx-button>
    </form>

    <div class="h-[34rem] flex flex-col">
        <x-nx-kanban-container sortable="noop" sortable-group="updateTaskOrder">
            @foreach($columns as $status => $tasks)
                <x-nx-kanban-column :title="InfraTask::statusLabel($status)" :sortable-id="$status" :count="$tasks->count()"
                                    :tone="InfraTask::statusTone($status)" :muted="$status === 'done'" :scrollable="true">
                    @foreach($tasks as $task)
                        <x-nx-kanban-card :sortable-id="$task->id" wire:key="task-{{ $task->id }}">
                            <button type="button" wire:click="openTask({{ $task->id }})" class="block w-full text-left space-y-1.5">
                                <div class="text-sm font-medium text-[color:var(--nx-text)]">{{ $task->title }}</div>
                                <div class="flex flex-wrap items-center gap-1.5 text-xs text-[color:var(--nx-muted)]">
                                    @if($task->priority === 'high')
                                        <x-nx-badge variant="danger">Hoch</x-nx-badge>
                                    @endif
                                    @if(!$serverId && $task->server)
                                        <span>{{ $task->server->name }}</span>
                                    @endif
                                    @if($task->finding)
                                        <x-nx-badge :variant="$task->finding->status === 'resolved' ? 'success' : 'warning'" dot>
                                            Befund {{ $task->finding->status === 'resolved' ? 'behoben' : 'offen' }}
                                        </x-nx-badge>
                                    @endif
                                    @if($task->userInCharge)
                                        <span>· {{ $task->userInCharge->name }}</span>
                                    @endif
                                    @if($task->due_date)
                                        <span @class(['text-[color:var(--nx-danger)]' => $task->status !== 'done' && $task->due_date->isPast()])>· bis {{ $task->due_date->format('d.m.') }}</span>
                                    @endif
                                </div>
                            </button>
                        </x-nx-kanban-card>
                    @endforeach
                </x-nx-kanban-column>
            @endforeach
        </x-nx-kanban-container>
    </div>

    <x-nx-modal size="lg" wire:model="showEdit">
        <x-slot name="header">Maßnahme</x-slot>
        <div class="space-y-4">
            <x-nx-input-text name="edit.title" label="Titel" wire:model="edit.title" required />
            <x-nx-input-textarea name="edit.description" label="Beschreibung" wire:model="edit.description" :rows="4" />
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <x-nx-input-select name="edit.status" label="Status" wire:model="edit.status" required
                    :options="collect(InfraTask::STATUSES)->map(fn ($s) => ['value' => $s, 'label' => InfraTask::statusLabel($s)])->all()" />
                <x-nx-input-select name="edit.priority" label="Priorität" wire:model="edit.priority" required
                    :options="collect(InfraTask::PRIORITIES)->map(fn ($p) => ['value' => $p, 'label' => InfraTask::priorityLabel($p)])->all()" />
                <x-nx-input-text name="edit.due_date" label="Fällig" type="date" wire:model="edit.due_date" />
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-nx-input-select name="edit.infra_server_id" label="Server" wire:model="edit.infra_server_id" :options="$allServerOptions" nullable nullLabel="— ohne Server —" />
                <x-nx-input-select name="edit.user_in_charge_id" label="Verantwortlich" wire:model="edit.user_in_charge_id" :options="$members" nullable nullLabel="— niemand —" />
            </div>
            <x-nx-input-textarea name="edit.done_note" label="Ergebnis / Notiz" wire:model="edit.done_note" :rows="2" />
            @if($showEdit && $editId)
                <div class="border-t border-[color:var(--nx-line)] pt-4">
                    <livewire:infra.task-time :task-id="$editId" :key="'task-time-' . $editId" />
                </div>
            @endif
        </div>
        <x-slot name="footer">
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if($confirmDelete)
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-[color:var(--nx-danger)]">Wirklich löschen?</span>
                        <x-nx-button variant="danger" wire:click="deleteTask">Löschen</x-nx-button>
                        <x-nx-button variant="ghost" wire:click="$set('confirmDelete', false)">Nein</x-nx-button>
                    </div>
                @else
                    <x-nx-button variant="ghost" wire:click="$set('confirmDelete', true)">Löschen</x-nx-button>
                @endif
                <div class="flex gap-3">
                    <x-nx-button variant="ghost" wire:click="$set('showEdit', false)">Abbrechen</x-nx-button>
                    <x-nx-button variant="primary" wire:click="saveTask">Speichern</x-nx-button>
                </div>
            </div>
        </x-slot>
    </x-nx-modal>
</div>
