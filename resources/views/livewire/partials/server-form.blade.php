{{-- Shared server form (create + edit). Expects $options; binds to $form. --}}
<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-nx-input-text name="form.name" label="Name" wire:model="form.name" placeholder="z. B. BHG.DIGITAL.OFFICE" required />
        <x-nx-input-text name="form.role" label="Rolle" wire:model="form.role" placeholder="z. B. Web, Datenbank, GPU" />
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-nx-input-select name="form.environment" label="Umgebung" wire:model="form.environment" :options="$options['environments']" required />
        <x-nx-input-select name="form.provider" label="Anbieter" wire:model="form.provider" :options="$options['providers']" required />
        <x-nx-input-text name="form.provider_ref" label="Anbieter-Kennung" wire:model="form.provider_ref" hint="ID bei Hetzner/Forge" />
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-nx-input-text name="form.hostname" label="Hostname" wire:model="form.hostname" />
        <x-nx-input-text name="form.ip_public" label="Öffentliche IP" wire:model="form.ip_public" />
        <x-nx-input-text name="form.ip_private" label="Private IP" wire:model="form.ip_private" />
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-nx-input-text name="form.ssh_user" label="SSH-Benutzer" wire:model="form.ssh_user" placeholder="z. B. forge" />
        <x-nx-input-number name="form.ssh_port" label="SSH-Port" wire:model="form.ssh_port" :min="1" :max="65535" placeholder="22" />
        <x-nx-input-select name="form.status" label="Status" wire:model="form.status" :options="$options['statuses']" required />
    </div>
    @if(!empty($options['members']))
        <x-nx-input-select name="form.user_in_charge_id" label="Verantwortlich" wire:model="form.user_in_charge_id"
            :options="$options['members']" nullable nullLabel="— niemand —" />
    @endif
    <x-nx-input-textarea name="form.notes" label="Notizen" wire:model="form.notes" :rows="3" />
</div>
