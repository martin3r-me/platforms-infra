<?php

namespace Platform\Infra\Livewire;

use Livewire\Component;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Services\InfraTeamResolver;

class Sidebar extends Component
{
    public function render()
    {
        $teamId = InfraTeamResolver::rootTeamId(auth()->user());

        $servers = $teamId
            ? InfraServer::query()
                ->where('team_id', $teamId)
                ->watched()
                ->withCount([
                    'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
                    'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
                ])
                ->orderBy('name')
                ->get()
            : collect();

        return view('infra::livewire.sidebar', ['servers' => $servers]);
    }
}
