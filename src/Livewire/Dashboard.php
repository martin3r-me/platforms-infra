<?php

namespace Platform\Infra\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Infra\Livewire\Concerns\EditsServerForm;
use Platform\Infra\Models\InfraFinding;
use Platform\Infra\Models\InfraLogEntry;
use Platform\Infra\Models\InfraServer;
use Platform\Infra\Services\InfraTeamResolver;

class Dashboard extends Component
{
    use EditsServerForm;

    public bool $showCreate = false;
    public bool $showRetired = false;

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->form = $this->blankForm();
        $this->showCreate = true;
    }

    public function createServer(): void
    {
        $teamId = $this->teamId();
        $data = $this->validatedForm($teamId);

        $server = InfraServer::create($data + [
            'team_id' => $teamId,
            'created_by_user_id' => Auth::id(),
        ]);

        $this->showCreate = false;
        $this->redirectRoute('infra.servers.show', ['server' => $server->id], navigate: true);
    }

    protected function teamId(): int
    {
        $teamId = InfraTeamResolver::rootTeamId(Auth::user());
        abort_unless($teamId, 403);

        return $teamId;
    }

    public function render()
    {
        $teamId = $this->teamId();

        $servers = InfraServer::query()
            ->where('team_id', $teamId)
            ->when(!$this->showRetired, fn ($q) => $q->watched())
            ->withCount([
                'findings as open_critical_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_CRITICAL),
                'findings as open_warning_count' => fn ($q) => $q->open()->where('severity', InfraFinding::SEVERITY_WARNING),
            ])
            ->orderBy('name')
            ->get();

        $health = $servers->map(fn ($s) => $s->health());
        $openFindings = InfraFinding::query()
            ->with('server:id,name')
            ->where('team_id', $teamId)
            ->open()
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('last_seen_at')
            ->limit(15)
            ->get();

        $stats = [
            'servers' => $servers->where('status', '!=', InfraServer::STATUS_RETIRED)->count(),
            'reporting' => $health->filter(fn ($h) => in_array($h, [InfraServer::HEALTH_OK, InfraServer::HEALTH_WARNING, InfraServer::HEALTH_CRITICAL], true))->count(),
            'silent' => $health->filter(fn ($h) => in_array($h, [InfraServer::HEALTH_STALE, InfraServer::HEALTH_UNKNOWN], true))->count(),
            'critical' => InfraFinding::where('team_id', $teamId)->open()->where('severity', InfraFinding::SEVERITY_CRITICAL)->count(),
            'warning' => InfraFinding::where('team_id', $teamId)->open()->where('severity', InfraFinding::SEVERITY_WARNING)->count(),
            'errors_24h' => InfraLogEntry::where('team_id', $teamId)
                ->whereIn('level', InfraLogEntry::ERROR_LEVELS)
                ->where('occurred_at', '>=', now()->subDay())
                ->count(),
        ];

        $recentlyResolved = InfraFinding::query()
            ->with('server:id,name')
            ->where('team_id', $teamId)
            ->where('status', InfraFinding::STATUS_RESOLVED)
            ->orderByDesc('resolved_at')
            ->limit(8)
            ->get();

        return view('infra::livewire.dashboard', [
            'servers' => $servers,
            'openFindings' => $openFindings,
            'recentlyResolved' => $recentlyResolved,
            'stats' => $stats,
            'options' => $this->formOptions(),
        ])->layout('platform::layouts.app');
    }
}
