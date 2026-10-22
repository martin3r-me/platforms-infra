<?php

namespace Platform\Infra\Livewire\Concerns;

use Illuminate\Validation\Rule;
use Platform\Infra\Models\InfraServer;

/**
 * Shared server form for create (Dashboard) and edit (Server\Show). Lives in Concerns/
 * — the Livewire auto-registration skips it because it is a trait, not a component.
 */
trait EditsServerForm
{
    public array $form = [];

    protected function blankForm(): array
    {
        return [
            'name' => '',
            'hostname' => '',
            'role' => '',
            'environment' => 'production',
            'provider' => 'hetzner',
            'provider_ref' => '',
            'ip_public' => '',
            'ip_private' => '',
            'ssh_user' => '',
            'ssh_port' => null,
            'status' => InfraServer::STATUS_ACTIVE,
            'user_in_charge_id' => null,
            'notes' => '',
        ];
    }

    protected function formFromServer(InfraServer $server): array
    {
        $form = $this->blankForm();
        foreach (array_keys($form) as $key) {
            $form[$key] = $server->{$key} ?? $form[$key];
        }

        return $form;
    }

    protected function validatedForm(int $teamId, ?int $ignoreId = null): array
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255',
                Rule::unique('infra_servers', 'name')->where('team_id', $teamId)->whereNull('deleted_at')->ignore($ignoreId)],
            'form.hostname' => ['nullable', 'string', 'max:255'],
            'form.role' => ['nullable', 'string', 'max:255'],
            'form.environment' => ['required', Rule::in(InfraServer::ENVIRONMENTS)],
            'form.provider' => ['required', Rule::in(InfraServer::PROVIDERS)],
            'form.provider_ref' => ['nullable', 'string', 'max:255'],
            'form.ip_public' => ['nullable', 'string', 'max:64'],
            'form.ip_private' => ['nullable', 'string', 'max:64'],
            'form.ssh_user' => ['nullable', 'string', 'max:64'],
            'form.ssh_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'form.status' => ['required', Rule::in(InfraServer::STATUSES)],
            'form.user_in_charge_id' => ['nullable', 'integer'],
            'form.notes' => ['nullable', 'string', 'max:10000'],
        ], [], [
            'form.name' => 'Name',
            'form.ssh_port' => 'SSH-Port',
        ])['form'];

        foreach ($data as $k => $v) {
            if ($v === '') {
                $data[$k] = null;
            }
        }

        return $data;
    }

    public function formOptions(): array
    {
        return [
            'environments' => [
                ['value' => 'production', 'label' => 'Produktion'],
                ['value' => 'staging', 'label' => 'Staging'],
                ['value' => 'development', 'label' => 'Entwicklung'],
            ],
            'providers' => [
                ['value' => 'hetzner', 'label' => 'Hetzner'],
                ['value' => 'forge', 'label' => 'Laravel Forge'],
                ['value' => 'digitalocean', 'label' => 'DigitalOcean'],
                ['value' => 'other', 'label' => 'Sonstige'],
            ],
            'statuses' => [
                ['value' => InfraServer::STATUS_ACTIVE, 'label' => 'Aktiv'],
                ['value' => InfraServer::STATUS_MAINTENANCE, 'label' => 'Wartung'],
                ['value' => InfraServer::STATUS_RETIRED, 'label' => 'Stillgelegt'],
            ],
        ];
    }
}
