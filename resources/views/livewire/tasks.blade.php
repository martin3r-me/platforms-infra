<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Maßnahmen" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Infra', 'icon' => 'server-stack', 'href' => route('infra.dashboard')],
            ['label' => 'Maßnahmen'],
        ]" />
    </x-slot>

    <x-ui-page-container width="full" spacing="space-y-6">
        <livewire:infra.task-board key="tasks-all" />
    </x-ui-page-container>
</x-ui-page>
